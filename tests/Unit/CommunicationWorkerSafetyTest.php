<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Communications\EmailOutboxWorker;
use App\Contracts\Mailer;
use App\Security\SecretCipher;
use PDO;
use PHPUnit\Framework\TestCase;
final class CommunicationWorkerSafetyTest extends TestCase
{
    private function fixture():array {
        $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$cipher=new SecretCipher(base64_encode(str_repeat('k',32)));
        $pdo->exec('CREATE TABLE communication_email_outbox(id INTEGER PRIMARY KEY,user_id INTEGER,recipient TEXT,event TEXT,category TEXT,payload_ciphertext TEXT,dedupe_key TEXT,status TEXT,attempts INTEGER DEFAULT 0,available_at TEXT,leased_until TEXT,lease_token_hash TEXT,last_error_code TEXT,sent_at TEXT)');$pdo->exec('CREATE TABLE communication_campaigns(id INTEGER PRIMARY KEY,status TEXT)');$pdo->exec("INSERT INTO communication_campaigns VALUES(1,'processing'),(2,'cancelled')");$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT,status TEXT)');$pdo->exec("INSERT INTO users VALUES(1,'ana@example.test','active')");
        $pdo->exec('CREATE TABLE communication_email_templates(id INTEGER PRIMARY KEY,event TEXT,locale TEXT,subject_template TEXT,html_template TEXT,text_template TEXT,is_active INTEGER,version INTEGER)');
        $pdo->exec("INSERT INTO communication_email_templates VALUES(1,'auth.welcome','pt-BR','Olá','<p>{{nome_usuario}}</p>','Olá',1,1)");
        $pdo->exec('CREATE TABLE communication_email_attempts(id INTEGER PRIMARY KEY,outbox_id INTEGER,attempt_number INTEGER,outcome TEXT,error_code TEXT,UNIQUE(outbox_id,attempt_number))');
        $payload=$cipher->encrypt(json_encode(['event'=>'auth.welcome','variables'=>['nome_usuario'=>'Ana']],JSON_THROW_ON_ERROR),'clipforge:communications:v1');
        $s=$pdo->prepare("INSERT INTO communication_email_outbox(id,user_id,recipient,event,category,payload_ciphertext,dedupe_key,status,available_at) VALUES(1,1,'ana@example.test','auth.welcome','account',:payload,'welcome:1','pending','2000-01-01')");$s->execute(['payload'=>$payload]);return [$pdo,$cipher];
    }
    public function testAcceptedAndTerminalFailedAttemptsAreAuditedAndPayloadScrubbed():void {
        [$pdo,$cipher]=$this->fixture();$mailer=new class implements Mailer {public function send(string $recipient,string $subject,string $html):void{throw new \RuntimeException('token=NEVER_LOG_ME');}};
        self::assertSame('failed',(new EmailOutboxWorker($pdo,$cipher,$mailer,null,null,1))->processOne());
        self::assertNull($pdo->query('SELECT payload_ciphertext FROM communication_email_outbox')->fetchColumn());
        self::assertSame('failed',$pdo->query('SELECT outcome FROM communication_email_attempts')->fetchColumn());self::assertStringNotContainsString('NEVER_LOG_ME',json_encode($pdo->query('SELECT * FROM communication_email_attempts')->fetchAll(),JSON_THROW_ON_ERROR));
        [$pdo,$cipher]=$this->fixture();$mailer=new class implements Mailer {public function send(string $recipient,string $subject,string $html):void{}};
        self::assertSame('sent',(new EmailOutboxWorker($pdo,$cipher,$mailer))->processOne());self::assertSame('accepted',$pdo->query('SELECT outcome FROM communication_email_attempts')->fetchColumn());
    }
    public function testLogTransportIsNotAcceptedAsDelivery():void {
        [$pdo,$cipher]=$this->fixture();$this->expectException(\RuntimeException::class);
        new EmailOutboxWorker($pdo,$cipher,new \App\Services\LogMailer('unused.log'));
    }
    public function testExpiredFinalLeaseDoesNotSendAgain():void {
        [$pdo,$cipher]=$this->fixture();$pdo->exec("UPDATE communication_email_outbox SET status='leased',attempts=3,leased_until='2000-01-01',lease_token_hash='stale'");
        $mailer=new class implements Mailer {public int $calls=0;public function send(string $recipient,string $subject,string $html):void{$this->calls++;}};
        self::assertSame('failed',(new EmailOutboxWorker($pdo,$cipher,$mailer))->processOne());self::assertSame(0,$mailer->calls);
    }
    public function testTransactionalsArePrioritizedAndMarketingOptOutCancelsBeforeSend():void {
        [$pdo,$cipher]=$this->fixture();$pdo->exec('CREATE TABLE communication_preferences(user_id INTEGER,category TEXT,email_enabled INTEGER,marketing_opted_in_at TEXT)');
        $payload=$cipher->encrypt(json_encode(['event'=>'marketing.campaign','variables'=>['nome_usuario'=>'Ana','titulo'=>'Oferta','conteudo'=>'Exemplo']],JSON_THROW_ON_ERROR),'clipforge:communications:v1');
        $s=$pdo->prepare("INSERT INTO communication_email_outbox(id,user_id,recipient,event,category,payload_ciphertext,dedupe_key,status,available_at) VALUES(0,1,'ana@example.test','marketing.campaign','marketing',:payload,'campaign:1','pending','2000-01-01')");$s->execute(['payload'=>$payload]);
        $mailer=new class implements Mailer {public int $calls=0;public function send(string $recipient,string $subject,string $html):void{$this->calls++;}};$worker=new EmailOutboxWorker($pdo,$cipher,$mailer);
        self::assertSame('sent',$worker->processOne());self::assertSame('sent',$pdo->query('SELECT status FROM communication_email_outbox WHERE id=1')->fetchColumn());
        self::assertSame('cancelled',$worker->processOne());self::assertSame(1,$mailer->calls);self::assertNull($pdo->query('SELECT payload_ciphertext FROM communication_email_outbox WHERE id=0')->fetchColumn());
    }
    public function testMarketingCampaignUsesItsFrozenTemplateAndSafeUnsubscribeFooter():void {
        [$pdo,$cipher]=$this->fixture();$pdo->exec('CREATE TABLE communication_preferences(user_id INTEGER,category TEXT,email_enabled INTEGER,marketing_opted_in_at TEXT)');$pdo->exec("INSERT INTO communication_preferences VALUES(1,'marketing',1,'2026-09-01')");$pdo->exec("INSERT INTO communication_email_templates VALUES(2,'marketing.campaign','pt-BR','Live {{titulo}}','<p>Live {{conteudo}}</p>','Live',1,1)");
        $payload=$cipher->encrypt(json_encode(['event'=>'marketing.campaign','variables'=>['nome_usuario'=>'Ana','titulo'=>'Oferta','conteudo'=>'Frozen'],'template_snapshot'=>['subject_template'=>'Frozen {{titulo}}','html_template'=>'<p>Frozen {{conteudo}}</p>','text_template'=>'Frozen'],'unsubscribe_url'=>'https://example.test/cancelar-inscricao?token=abc'],JSON_THROW_ON_ERROR),'clipforge:communications:v1');$s=$pdo->prepare("INSERT INTO communication_email_outbox(id,user_id,recipient,event,category,payload_ciphertext,dedupe_key,status,available_at) VALUES(2,1,'ana@example.test','marketing.campaign','marketing',:payload,'campaign:2:user:1','pending','2000-01-01')");$s->execute(['payload'=>$payload]);
        $pdo->exec("UPDATE communication_campaigns SET status='processing' WHERE id=2");$mailer=new class implements Mailer {public string $subject='';public string $html='';public function send(string $r,string $s,string $h):void{$this->subject=$s;$this->html=$h;}};$worker=new EmailOutboxWorker($pdo,$cipher,$mailer);self::assertSame('sent',$worker->processOne());self::assertSame('sent',$worker->processOne());self::assertSame('Frozen Oferta',$mailer->subject);self::assertStringContainsString('Frozen Frozen',$mailer->html);self::assertStringContainsString('cancelar-inscricao?token=abc',$mailer->html);
    }
    public function testCancelledCampaignCannotRecoverExpiredLeaseAndSend():void {
        [$pdo,$cipher]=$this->fixture();$pdo->exec('CREATE TABLE communication_preferences(user_id INTEGER,category TEXT,email_enabled INTEGER,marketing_opted_in_at TEXT)');$pdo->exec("INSERT INTO communication_preferences VALUES(1,'marketing',1,'2026-09-01')");$payload=$cipher->encrypt(json_encode(['event'=>'marketing.campaign','variables'=>['nome_usuario'=>'Ana','titulo'=>'Oferta','conteudo'=>'Texto']],JSON_THROW_ON_ERROR),'clipforge:communications:v1');$s=$pdo->prepare("INSERT INTO communication_email_outbox(id,user_id,recipient,event,category,payload_ciphertext,dedupe_key,status,attempts,available_at,leased_until,lease_token_hash) VALUES(2,1,'ana@example.test','marketing.campaign','marketing',:payload,'campaign:2:user:1','leased',0,'2000-01-01','2000-01-01','old')");$s->execute(['payload'=>$payload]);$mailer=new class implements Mailer {public int $calls=0;public function send(string $r,string $s,string $h):void{$this->calls++;}};$worker=new EmailOutboxWorker($pdo,$cipher,$mailer);self::assertSame('sent',$worker->processOne());self::assertSame('cancelled',$worker->processOne());self::assertSame(1,$mailer->calls);self::assertSame('cancelled',$pdo->query('SELECT status FROM communication_email_outbox WHERE id=2')->fetchColumn());self::assertNull($pdo->query('SELECT payload_ciphertext FROM communication_email_outbox WHERE id=2')->fetchColumn());
    }
    public function testSuspendedOrChangedAccountCannotReceiveQueuedNotification():void {
        [$pdo,$cipher]=$this->fixture();$pdo->exec("UPDATE users SET status='suspended' WHERE id=1");$mailer=new class implements Mailer {public int $calls=0;public function send(string $r,string $s,string $h):void{$this->calls++;}};
        self::assertSame('cancelled',(new EmailOutboxWorker($pdo,$cipher,$mailer))->processOne());self::assertSame(0,$mailer->calls);self::assertSame('recipient_ineligible',$pdo->query('SELECT last_error_code FROM communication_email_outbox WHERE id=1')->fetchColumn());self::assertNull($pdo->query('SELECT payload_ciphertext FROM communication_email_outbox WHERE id=1')->fetchColumn());
    }
    public function testConfirmedEmailChangeKeepsTheLegitimateOldAddressNotice():void {
        [$pdo,$cipher]=$this->fixture();$pdo->exec('CREATE TABLE account_email_changes(id INTEGER PRIMARY KEY,user_id INTEGER,current_email TEXT,requested_email TEXT,token_hash TEXT,expires_at TEXT,consumed_at TEXT,revoked_at TEXT)');$pdo->exec("UPDATE users SET email='novo@example.test' WHERE id=1");$pdo->exec("INSERT INTO account_email_changes VALUES(9,1,'ana@example.test','novo@example.test','hash','2099-01-01','2026-09-07',NULL)");$pdo->exec("INSERT INTO communication_email_templates VALUES(2,'account.email_changed','pt-BR','Alterado','<p>{{nome_usuario}}</p>','Alterado',1,1)");$payload=$cipher->encrypt(json_encode(['event'=>'account.email_changed','variables'=>['nome_usuario'=>'Ana']],JSON_THROW_ON_ERROR),'clipforge:communications:v1');$s=$pdo->prepare("INSERT INTO communication_email_outbox(id,user_id,recipient,event,category,payload_ciphertext,dedupe_key,status,available_at) VALUES(2,1,'ana@example.test','account.email_changed','account',:payload,'email-confirmed:9','pending','2000-01-01')");$s->execute(['payload'=>$payload]);$mailer=new class implements Mailer {public array $recipients=[];public function send(string $r,string $s,string $h):void{$this->recipients[]=$r;}};$worker=new EmailOutboxWorker($pdo,$cipher,$mailer);
        self::assertSame('cancelled',$worker->processOne());self::assertSame('sent',$worker->processOne());self::assertSame(['ana@example.test'],$mailer->recipients);
    }
}
