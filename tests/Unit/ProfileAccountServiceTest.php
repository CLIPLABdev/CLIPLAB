<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Account\ProfileAccountService;
use App\Contracts\CommunicationEmitter;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProfileAccountServiceTest extends TestCase
{
    private PDO $pdo;
    private object $emitter;
    private DateTimeImmutable $now;
    private ProfileAccountService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT UNIQUE COLLATE NOCASE, password_hash TEXT, status TEXT, avatar_path TEXT, email_verified_at TEXT)');
        $this->pdo->exec('CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY, user_id INTEGER, token_hash TEXT, used_at TEXT)');
        $this->pdo->exec('CREATE TABLE account_email_changes (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, current_email TEXT, requested_email TEXT, token_hash TEXT UNIQUE, expires_at TEXT, consumed_at TEXT, revoked_at TEXT, created_at TEXT)');
        $stmt = $this->pdo->prepare('INSERT INTO users (id,name,email,password_hash,status) VALUES (?,?,?,?,?)');
        $stmt->execute([1,'Ana','ana@example.test',password_hash('Old-password#2026', PASSWORD_DEFAULT),'active']);
        $stmt->execute([2,'Bia','bia@example.test',password_hash('Other-password#2026', PASSWORD_DEFAULT),'active']);
        $this->now = new DateTimeImmutable('2026-09-07 12:00:00 UTC');
        $this->emitter = new class($this->pdo) implements CommunicationEmitter {
            public array $events = [];
            public array $cancellations = [];
            public bool $fail = false;
            public function __construct(private PDO $pdo) {}
            public function emit(int $userId, string $event, array $variables, string $dedupeKey, ?string $recipient = null, array $channels = ['in_app','email'], ?DateTimeImmutable $availableAt = null): void
            {
                if (!$this->pdo->inTransaction()) throw new \RuntimeException('Expected transactional emission');
                if ($this->fail) throw new \RuntimeException('Outbox unavailable');
                $this->events[] = compact('userId','event','variables','dedupeKey','recipient','channels');
            }
            public function cancelByDedupePrefix(int $userId, string $prefix): void { $this->cancellations[] = [$userId,$prefix]; }
        };
        $this->service = new ProfileAccountService($this->pdo, $this->emitter, 'http://localhost:8093', fn () => $this->now);
    }

    public function testNameUpdatePreservesEmailAndNeedsNoPassword(): void
    {
        self::assertFalse($this->service->updateDetails(1,'Ana Nova','ANA@example.test','')['email_pending']);
        self::assertSame('Ana Nova',$this->user()['name']);
        self::assertSame('ana@example.test',$this->user()['email']);
        self::assertSame([], $this->emitter->events);
    }

    public function testEmailWaitsForConfirmedOwnedSingleUseToken(): void
    {
        self::assertTrue($this->service->updateDetails(1,'Ana','nova@example.test','Old-password#2026')['email_pending']);
        self::assertSame('ana@example.test',$this->user()['email']);
        $token = $this->lastToken();
        $row = $this->pdo->query('SELECT * FROM account_email_changes')->fetch(PDO::FETCH_ASSOC);
        self::assertSame(hash('sha256',$token),$row['token_hash']);
        self::assertStringNotContainsString($token,json_encode($row));
        self::assertSame(['email'],$this->emitter->events[0]['channels']);
        self::assertSame('nova@example.test',$this->emitter->events[0]['recipient']);
        self::assertFalse($this->service->confirmEmail(2,$token));
        self::assertTrue($this->service->confirmEmail(1,$token));
        self::assertFalse($this->service->confirmEmail(1,$token));
        self::assertSame('nova@example.test',$this->user()['email']);
        self::assertSame('2026-09-07 12:00:00',$this->user()['email_verified_at']);
        self::assertSame('ana@example.test',$this->emitter->events[1]['recipient']);
    }

    public function testNewRequestRevokesOlderChallenge(): void
    {
        $this->service->updateDetails(1,'Ana','first@example.test','Old-password#2026');
        $old = $this->lastToken();
        $this->service->updateDetails(1,'Ana','second@example.test','Old-password#2026');
        self::assertFalse($this->service->confirmEmail(1,$old));
        self::assertTrue($this->service->confirmEmail(1,$this->lastToken()));
        self::assertContains([1,'email-change:1:'],$this->emitter->cancellations);
    }

    public function testExpiredChallengeCannotChangeEmail(): void
    {
        $this->service->updateDetails(1,'Ana','new@example.test','Old-password#2026');
        $this->now = $this->now->modify('+31 minutes');
        self::assertFalse($this->service->confirmEmail(1,$this->lastToken()));
        self::assertSame('ana@example.test',$this->user()['email']);
    }

    public function testDuplicateEmailAtConfirmationDoesNotOverwriteAnotherAccount(): void
    {
        $this->service->updateDetails(1,'Ana','new@example.test','Old-password#2026');
        $this->pdo->exec("UPDATE users SET email='new@example.test' WHERE id=2");
        self::assertFalse($this->service->confirmEmail(1,$this->lastToken()));
        self::assertSame('ana@example.test',$this->user()['email']);
    }

    /** @dataProvider invalidProfile */
    public function testInvalidProfileDoesNotChangeAnything(string $name,string $email,string $password): void
    {
        try { $this->service->updateDetails(1,$name,$email,$password); self::fail('Invalid update accepted'); }
        catch (\App\Account\ProfileValidationException $error) { self::assertNotEmpty($error->errors()); }
        self::assertSame('Ana',$this->user()['name']);
        self::assertSame('ana@example.test',$this->user()['email']);
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM account_email_changes')->fetchColumn());
    }

    public function invalidProfile(): array
    {
        return [['A','ana@example.test',''],['Ana','invalid',''],['Ana','bia@example.test','Old-password#2026'],['Ana','new@example.test','wrong'],['Ana','new@example.test','']];
    }

    public function testOutboxFailureRollsBackProfileAndChallenge(): void
    {
        $this->emitter->fail = true;
        try { $this->service->updateDetails(1,'Changed','new@example.test','Old-password#2026'); self::fail('Should fail'); }
        catch (\RuntimeException $error) { self::assertSame('Outbox unavailable',$error->getMessage()); }
        self::assertSame('Ana',$this->user()['name']);
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM account_email_changes')->fetchColumn());
    }

    public function testPasswordRequiresCurrentAndRevokesPendingTokens(): void
    {
        $this->service->updateDetails(1,'Ana','new@example.test','Old-password#2026');
        $token = $this->lastToken();
        $this->pdo->exec("INSERT INTO password_reset_tokens(user_id,token_hash) VALUES (1,'hash')");
        $this->service->changePassword(1,'Old-password#2026','New-password#2026','New-password#2026');
        self::assertTrue(password_verify('New-password#2026',$this->user()['password_hash']));
        self::assertNotNull($this->pdo->query('SELECT used_at FROM password_reset_tokens')->fetchColumn());
        self::assertFalse($this->service->confirmEmail(1,$token));
        self::assertSame('account.password_changed',$this->emitter->events[1]['event']);
        self::assertStringNotContainsString('New-password', json_encode($this->emitter->events));
    }

    /** @dataProvider invalidPasswords */
    public function testPasswordValidation(string $current,string $new,string $confirmation): void
    {
        $this->expectException(\App\Account\ProfileValidationException::class);
        try { $this->service->changePassword(1,$current,$new,$confirmation); }
        finally { self::assertTrue(password_verify('Old-password#2026',$this->user()['password_hash'])); }
    }

    public function invalidPasswords(): array
    {
        return [['wrong','New-password#2026','New-password#2026'],['Old-password#2026','short','short'],['Old-password#2026','New-password#2026','mismatch'],['Old-password#2026',str_repeat('x',73),str_repeat('x',73)],['Old-password#2026','Old-password#2026','Old-password#2026']];
    }

    public function testSuspendedAccountCannotChangeProfile(): void
    {
        $this->pdo->exec("UPDATE users SET status='suspended' WHERE id=1");
        $this->expectException(\App\Account\ProfileValidationException::class);
        $this->service->updateDetails(1,'Another Name','ana@example.test','');
    }

    public function testServiceDoesNotCommitCallingTransaction(): void
    {
        $this->pdo->beginTransaction();
        $this->service->updateDetails(1,'New Name','ana@example.test','');
        self::assertTrue($this->pdo->inTransaction());
        $this->pdo->rollBack();
        self::assertSame('Ana',$this->user()['name']);
    }

    public function testInsecurePublicBaseUrlIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProfileAccountService($this->pdo,$this->emitter,'http://public.example.test');
    }

    private function user(): array { return $this->pdo->query('SELECT * FROM users WHERE id=1')->fetch(PDO::FETCH_ASSOC); }
    private function lastToken(): string
    {
        $event = $this->emitter->events[array_key_last($this->emitter->events)];
        parse_str(parse_url($event['variables']['link_confirmacao'],PHP_URL_QUERY),$query);
        return $query['token'];
    }
}
