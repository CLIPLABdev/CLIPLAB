<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Communications\{CommunicationEmitterService,CommunicationEventCatalog,EmailVerificationService};
use App\Repositories\{UserRepository,PlanRepository};
use App\Security\SecretCipher;
use App\Services\AuthService;
use PDO;use PHPUnit\Framework\TestCase;
final class CommunicationRegistrationTest extends TestCase {
    private function fixture():array {
        $_SESSION=[];$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE plans(id INTEGER PRIMARY KEY,slug TEXT,credits INTEGER,is_active INTEGER)');$pdo->exec("INSERT INTO plans VALUES(1,'free',10,1)");
        $pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,email TEXT,password_hash TEXT,plan_id INTEGER,credits INTEGER,role TEXT,status TEXT,email_verified_at TEXT)');
        $pdo->exec('CREATE TABLE credit_transactions(id INTEGER PRIMARY KEY,user_id INTEGER,type TEXT,amount INTEGER,balance_after INTEGER,reference_type TEXT,reference_id INTEGER,description TEXT)');
        $pdo->exec('CREATE TABLE communication_email_outbox(id INTEGER PRIMARY KEY,user_id INTEGER,recipient TEXT,event TEXT,category TEXT,payload_ciphertext TEXT,dedupe_key TEXT UNIQUE,status TEXT,available_at TEXT)');
        $pdo->exec('CREATE TABLE communication_notifications(id INTEGER PRIMARY KEY,user_id INTEGER,event TEXT,category TEXT,title TEXT,body TEXT,dedupe_key TEXT UNIQUE)');
        $pdo->exec('CREATE TABLE email_verification_challenges(id INTEGER PRIMARY KEY,user_id INTEGER,email TEXT,token_hash TEXT UNIQUE,expires_at TEXT,used_at TEXT)');
        $emitter=new CommunicationEmitterService($pdo,new SecretCipher(base64_encode(str_repeat('k',32))),new CommunicationEventCatalog());
        return [$pdo,new AuthService($pdo,new UserRepository($pdo),new PlanRepository($pdo),$emitter,new EmailVerificationService($pdo,$emitter,'https://app.example.test'))];
    }
    public function testRegistrationQueuesWelcomeAndVerificationWithoutBlockingLogin():void {
        [$pdo,$auth]=$this->fixture();$auth->register(['name'=>'Ana','email'=>'ana@example.test','password'=>'secure-password-123']);
        self::assertSame(['auth.welcome','auth.email_verification'],$pdo->query('SELECT event FROM communication_email_outbox ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM email_verification_challenges')->fetchColumn());
        self::assertTrue($auth->attempt('ana@example.test','secure-password-123'));self::assertNull($pdo->query('SELECT email_verified_at FROM users')->fetchColumn());
    }
    public function testQueuePersistenceFailureRollsBackUserAndCredits():void {
        [$pdo,$auth]=$this->fixture();$pdo->exec('DROP TABLE communication_email_outbox');
        try{$auth->register(['name'=>'Ana','email'=>'ana@example.test','password'=>'secure-password-123']);self::fail('Queue failure swallowed');}catch(\PDOException){}
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM credit_transactions')->fetchColumn());self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM communication_notifications')->fetchColumn());
    }
}
