<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Communications\{CommunicationEmitterService,CommunicationEventCatalog};
use App\Contracts\Mailer;
use App\Repositories\{PasswordResetRepository,UserRepository};
use App\Security\SecretCipher;
use App\Services\PasswordResetService;
use PDO;
use PHPUnit\Framework\TestCase;

final class PasswordResetIdentityRaceTest extends TestCase
{
    /** @dataProvider changedIdentities */
    public function testIdentityChangeCommittedBeforeResetLockCannotIssueNewChallenge(string $change):void
    {
        $pdo=new ResetIdentityInterleavingPdo();
        $pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT,password_hash TEXT,status TEXT)');
        $pdo->exec("INSERT INTO users VALUES(1,'old@example.test','password-hash','active')");
        $pdo->exec('CREATE TABLE password_reset_tokens(id INTEGER PRIMARY KEY,user_id INTEGER,token_hash TEXT,expires_at TEXT,used_at TEXT)');
        $pdo->exec("INSERT INTO password_reset_tokens VALUES(1,1,'previous','2099-01-01',NULL)");
        $pdo->exec('CREATE TABLE communication_email_outbox(id INTEGER PRIMARY KEY,user_id INTEGER,recipient TEXT,event TEXT,category TEXT,payload_ciphertext TEXT,dedupe_key TEXT UNIQUE,status TEXT,available_at TEXT)');
        $emitter=new CommunicationEmitterService($pdo,new SecretCipher(base64_encode(str_repeat('k',32))),new CommunicationEventCatalog());
        $service=new PasswordResetService(new UserRepository($pdo),new PasswordResetRepository($pdo),null,'https://app.example.test',$emitter);
        // Execute the other actor's committed change after findByEmail(), immediately
        // before this request begins the transaction that acquires the user lock.
        $pdo->beforeNextTransaction=function()use($pdo,$change):void {
            $pdo->exec($change);
            $pdo->exec('UPDATE password_reset_tokens SET used_at=CURRENT_TIMESTAMP WHERE user_id=1 AND used_at IS NULL');
        };
        $service->request('old@example.test');
        self::assertSame(1,$pdo->interleavings);
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn());
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM password_reset_tokens WHERE used_at IS NULL')->fetchColumn());
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM communication_email_outbox')->fetchColumn());
    }

    public static function changedIdentities():array {
        return [
            'confirmed new email'=>["UPDATE users SET email='new@example.test' WHERE id=1"],
            'suspended account'=>["UPDATE users SET status='suspended' WHERE id=1"],
        ];
    }

    public function testLegacyMailerAlsoRemainsSilentWhenIdentityChanges():void
    {
        $pdo=new ResetIdentityInterleavingPdo();
        $pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT,password_hash TEXT,status TEXT)');$pdo->exec("INSERT INTO users VALUES(1,'old@example.test','hash','active')");
        $pdo->exec('CREATE TABLE password_reset_tokens(id INTEGER PRIMARY KEY,user_id INTEGER,token_hash TEXT,expires_at TEXT,used_at TEXT)');
        $mail=new class implements Mailer {public int $sent=0;public function send(string $to,string $subject,string $html):void{++$this->sent;}};
        $service=new PasswordResetService(new UserRepository($pdo),new PasswordResetRepository($pdo),$mail,'https://app.example.test');
        $pdo->beforeNextTransaction=fn()=>$pdo->exec("UPDATE users SET email='new@example.test' WHERE id=1");
        $service->request('old@example.test');self::assertSame(0,$mail->sent);self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn());
    }

    public function testStaleRequestPreservesNewIdentityChallengeAndCallerTransaction():void
    {
        $pdo=new ResetIdentityInterleavingPdo();
        $pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT,status TEXT)');
        $pdo->exec("INSERT INTO users VALUES(1,'new@example.test','active')");
        $pdo->exec('CREATE TABLE password_reset_tokens(id INTEGER PRIMARY KEY,user_id INTEGER,token_hash TEXT,expires_at TEXT,used_at TEXT)');
        $pdo->exec("INSERT INTO password_reset_tokens VALUES(1,1,'new-identity-challenge','2099-01-01',NULL)");
        $pdo->beginTransaction();
        $enqueued=false;
        $created=(new PasswordResetRepository($pdo))->replaceForUser(1,'stale-request','2099-01-01',function()use(&$enqueued):void{$enqueued=true;},'old@example.test');
        self::assertFalse($created);
        self::assertFalse($enqueued);
        self::assertTrue($pdo->inTransaction());
        self::assertSame(['new-identity-challenge'],$pdo->query('SELECT token_hash FROM password_reset_tokens WHERE used_at IS NULL')->fetchAll(PDO::FETCH_COLUMN));
        $pdo->rollBack();
    }
}

/** Real SQLite; only transaction entry schedules a deterministic concurrent actor. */
final class ResetIdentityInterleavingPdo extends PDO
{
    public ?\Closure $beforeNextTransaction=null;public int $interleavings=0;
    public function __construct(){parent::__construct('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
    public function beginTransaction():bool {
        if($this->beforeNextTransaction!==null){$operation=$this->beforeNextTransaction;$this->beforeNextTransaction=null;++$this->interleavings;$operation();}
        return parent::beginTransaction();
    }
}
