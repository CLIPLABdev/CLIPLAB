<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Communications\{CommunicationTemplateService,EmailTemplateRenderer,EmailVerificationService};
use App\Contracts\CommunicationEmitter;
use App\Repositories\{UserRepository,PasswordResetRepository};
use App\Services\PasswordResetService;
use PDO;
use PHPUnit\Framework\TestCase;

final class CommunicationsFlowsTest extends TestCase
{
    private function database(): PDO {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT, status TEXT, email_verified_at TEXT, password_hash TEXT)');
        $pdo->exec("INSERT INTO users VALUES (1,'Ana','ana@example.test','active',NULL,'old')");
        $pdo->exec('CREATE TABLE communication_email_templates (id INTEGER PRIMARY KEY AUTOINCREMENT,event TEXT,locale TEXT,subject_template TEXT,html_template TEXT,text_template TEXT,is_active INTEGER,version INTEGER,updated_by INTEGER,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(event,locale,version))');
        $pdo->exec('CREATE TABLE email_verification_challenges (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,email TEXT,token_hash TEXT UNIQUE,expires_at TEXT,used_at TEXT)');
        $pdo->exec('CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,token_hash TEXT UNIQUE,expires_at TEXT,used_at TEXT)');
        return $pdo;
    }
    public function testDefaultResetTemplateContainsActionLink():void {
        $pdo=$this->database(); (new CommunicationTemplateService($pdo))->installDefaults();
        self::assertStringContainsString('{{link_recuperacao}}',(string)$pdo->query("SELECT html_template FROM communication_email_templates WHERE event='auth.password_reset'")->fetchColumn());
    }
    public function testVersionsPreserveOldContentAndActivationIsExclusive():void {
        $pdo=$this->database();$s=new CommunicationTemplateService($pdo);
        $id=$s->create('auth.welcome','Primeiro','<p>Olá {{nome_usuario}}</p>','Olá {{nome_usuario}}',1);
        $second=$s->duplicate($id,1);$s->activate($id,1);$s->activate($second,1);
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM communication_email_templates WHERE is_active=1')->fetchColumn());
        self::assertSame(2,(int)$s->find($second)['version']);
    }
    public function testRejectsEncodedUnsafeUrlsAndUnapprovedHtmlAttributes():void {
        $s=new CommunicationTemplateService($this->database());
        foreach(['<a href="java&#x73;cript:alert(1)">x</a>','<img src="https://x.test/a">','<p style="background:url(https://x.test)">x</p>','<svg><a href="https://x.test">x</a></svg>'] as $html) {
            try {$s->validate('Assunto',$html,'Texto');self::fail('Unsafe HTML accepted');} catch(\InvalidArgumentException $e){self::assertNotSame('',$e->getMessage());}
        }
    }
    public function testSubjectRejectsUnknownTokens():void {
        $this->expectException(\InvalidArgumentException::class);
        (new EmailTemplateRenderer())->renderSubject('{{segredo}}',[],['nome_usuario']);
    }
    public function testVerificationIsHashedExpiresAndIsOneUse():void {
        $pdo=$this->database();$emitter=new FlowEmitter();$s=new EmailVerificationService($pdo,$emitter,'https://app.example.test');
        $s->request(1); parse_str((string)parse_url($emitter->variables['link_confirmacao'],PHP_URL_QUERY),$query);$token=$query['token'];
        self::assertSame(hash('sha256',$token),$pdo->query('SELECT token_hash FROM email_verification_challenges')->fetchColumn());
        self::assertTrue($s->confirm($token));self::assertFalse($s->confirm($token));
        self::assertNotNull($pdo->query('SELECT email_verified_at FROM users')->fetchColumn());
    }
    public function testVerificationFailureRollsBackAndDoesNotConfirmChangedAddress():void {
        $pdo=$this->database();$emitter=new FlowEmitter();$s=new EmailVerificationService($pdo,$emitter,'https://app.example.test');
        $s->request(1);parse_str((string)parse_url($emitter->variables['link_confirmacao'],PHP_URL_QUERY),$query);
        $pdo->exec("UPDATE users SET email='changed@example.test'");self::assertFalse($s->confirm($query['token']));
        $pdo->exec("UPDATE users SET email='ana@example.test'");$pdo->exec("UPDATE email_verification_challenges SET expires_at='2000-01-01'");self::assertFalse($s->confirm($query['token']));
        $emitter->fail=true;try{$s->request(1);self::fail('Queue failure swallowed');}catch(\RuntimeException){}
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM email_verification_challenges')->fetchColumn());
    }
    public function testResetEnqueueFailurePreservesPreviousChallenge():void {
        $pdo=$this->database();$pdo->exec("INSERT INTO password_reset_tokens VALUES(1,1,'previous','2099-01-01',NULL)");
        $emitter=new FlowEmitter();$emitter->fail=true;
        $mailer=new class implements \App\Contracts\Mailer {public function send(string $recipient,string $subject,string $html):void {throw new \LogicException('Legacy transport called');}};
        $s=new PasswordResetService(new UserRepository($pdo),new PasswordResetRepository($pdo),$mailer,'https://app.example.test',$emitter);
        try{$s->request('ana@example.test');self::fail('Queue failure swallowed');}catch(\RuntimeException){}
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM password_reset_tokens')->fetchColumn());
        self::assertNull($pdo->query('SELECT used_at FROM password_reset_tokens WHERE id=1')->fetchColumn());
    }
}
final class FlowEmitter implements CommunicationEmitter {
    public array $variables=[];public bool $fail=false;
    public function emit(int $userId,string $event,array $variables,string $dedupeKey,?string $recipient=null,array $channels=['in_app','email'],?\DateTimeImmutable $availableAt=null):void {if($this->fail)throw new \RuntimeException('Unavailable');$this->variables=$variables;}
    public function cancelByDedupePrefix(int $userId,string $prefix):void {}
}
