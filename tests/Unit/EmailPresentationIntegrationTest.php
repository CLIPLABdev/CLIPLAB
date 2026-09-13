<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Communications\{CommunicationTemplateService,CommunicationMailSettingsService,EmailOutboxWorker};
use App\Controllers\CommunicationAdminController;
use App\Core\{Request,View};
use App\Middleware\SecurityHeadersMiddleware;
use App\Security\SecretCipher;
use App\Services\RateLimiter;
use PDO;
use PHPUnit\Framework\TestCase;

final class EmailPresentationIntegrationTest extends TestCase
{
    private function fixture(): array
    {
        $_SESSION=['user_id'=>1];
        $pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,email TEXT,role TEXT,status TEXT)');
        $pdo->exec("INSERT INTO users VALUES(1,'Admin','admin@example.test','admin','active')");
        $pdo->exec('CREATE TABLE communication_email_templates(id INTEGER PRIMARY KEY AUTOINCREMENT,event TEXT,locale TEXT,subject_template TEXT,html_template TEXT,text_template TEXT,is_active INTEGER,version INTEGER,updated_by INTEGER,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE communication_mail_settings(id INTEGER PRIMARY KEY,last_test_status TEXT,last_test_code TEXT,last_tested_at TEXT)');
        $pdo->exec('CREATE TABLE rate_limits(id INTEGER PRIMARY KEY,rate_key TEXT,action TEXT,window_started_at TEXT,attempts INTEGER,expires_at TEXT)');
        $pdo->exec('CREATE TABLE communication_email_outbox(id INTEGER PRIMARY KEY,user_id INTEGER,recipient TEXT,event TEXT,category TEXT,payload_ciphertext TEXT,dedupe_key TEXT,status TEXT,attempts INTEGER DEFAULT 0,available_at TEXT,leased_until TEXT,lease_token_hash TEXT,last_error_code TEXT,sent_at TEXT)');
        $pdo->exec('CREATE TABLE communication_email_attempts(id INTEGER PRIMARY KEY,outbox_id INTEGER,attempt_number INTEGER,outcome TEXT,error_code TEXT)');
        $cipher=new SecretCipher(base64_encode(str_repeat('x',32)));
        $mail=new class implements \App\Contracts\Mailer {public array $sent=[];public function send(string $to,string $subject,string $html):void{$this->sent[]=compact('to','subject','html');}};
        $config=['transport'=>'smtp','smtp_host'=>'smtp.example.test','smtp_port'=>587,'smtp_encryption'=>'tls','smtp_username'=>'','smtp_password'=>'','from_address'=>'system@example.test'];
        $controller=new CommunicationAdminController(new View(),$pdo,new CommunicationMailSettingsService($pdo,$cipher,$config),new RateLimiter($pdo),static fn()=>$mail);
        return [$pdo,$cipher,$mail,$controller];
    }

    public function testPreviewManualSendAndOutboxKeepTheSameDocumentContent(): void
    {
        [$pdo,$cipher,$mail,$controller]=$this->fixture();
        $service=new CommunicationTemplateService($pdo);$service->installDefaults();
        $id=(int)$pdo->query("SELECT id FROM communication_email_templates WHERE event='auth.welcome'")->fetchColumn();
        $request=Request::fake('GET','/admin/emails/'.$id.'/preview');
        $preview=(new SecurityHeadersMiddleware())->handle($request,static fn($r)=>$controller->preview($r,['id'=>$id]));
        self::assertSame(200,$preview->status());
        self::assertStringNotContainsString("'unsafe-inline'",$preview->header('Content-Security-Policy'));
        self::assertStringContainsString("default-src 'none'",$preview->header('Content-Security-Policy'));
        self::assertSame('no-referrer',$preview->header('Referrer-Policy'));
        self::assertSame(302,$controller->testSend(Request::fake('POST','/admin/email-configuracao/testar',['template_id'=>$id]))->status());
        $payload=$cipher->encrypt(json_encode(['event'=>'auth.welcome','variables'=>$service->exampleVariables('auth.welcome')],JSON_THROW_ON_ERROR),'clipforge:communications:v1');
        $pdo->prepare("INSERT INTO communication_email_outbox(id,user_id,recipient,event,category,payload_ciphertext,dedupe_key,status,available_at) VALUES(1,1,'admin@example.test','auth.welcome','account',?,'welcome:1','pending','2000-01-01')")->execute([$payload]);
        self::assertSame('sent',(new EmailOutboxWorker($pdo,$cipher,$mail))->processOne());
        self::assertCount(2,$mail->sent);
        self::assertSame($mail->sent[0],$mail->sent[1]);
        $dom=new \DOMDocument();@$dom->loadHTML($mail->sent[0]['html']);$xpath=new \DOMXPath($dom);
        self::assertGreaterThan(0,$xpath->query('//*[@style]')->length);
        foreach($xpath->query('//*[@style]') as $node)$node->removeAttribute('style');
        $previewDom=new \DOMDocument();@$previewDom->loadHTML($preview->body());
        self::assertSame($dom->saveHTML($dom->getElementsByTagName('body')->item(0)),$previewDom->saveHTML($previewDom->getElementsByTagName('body')->item(0)));
    }

    public function testLoadingSystemSuggestionDoesNotReplaceOrActivateExistingCopy(): void
    {
        [$pdo,,,$controller]=$this->fixture();$service=new CommunicationTemplateService($pdo);$service->installDefaults();
        $pdo->exec("UPDATE communication_email_templates SET subject_template='Minha mensagem original',version=7 WHERE event='auth.welcome'");
        $before=$pdo->query('SELECT * FROM communication_email_templates ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $response=$controller->templates(Request::fake('GET','/admin/emails?modelo=auth.welcome'));
        self::assertSame($before,$pdo->query('SELECT * FROM communication_email_templates ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        self::assertStringContainsString('Sua próxima ideia começa aqui.',$response->body());
        self::assertStringContainsString('Será salvo como rascunho',$response->body());
        self::assertStringContainsString('Minha mensagem original',$response->body());
    }
}
