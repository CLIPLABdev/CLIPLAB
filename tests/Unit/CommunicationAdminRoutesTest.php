<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Core\{Csrf,Request,Response,Router,View};
use App\Security\SecretCipher;
use PDO;
use PHPUnit\Framework\TestCase;

final class CommunicationAdminRoutesTest extends TestCase
{
    private PDO $pdo;private Router $router;private array $sent=[];
    protected function setUp():void {
        $_SESSION=['user_id'=>1];$this->pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY,name TEXT,email TEXT,role TEXT,status TEXT,email_verified_at TEXT)');$this->pdo->exec("INSERT INTO users VALUES(1,'Admin','admin@example.test','admin','active',NULL)");
        $this->pdo->exec('CREATE TABLE communication_email_templates (id INTEGER PRIMARY KEY AUTOINCREMENT,event TEXT,locale TEXT,subject_template TEXT,html_template TEXT,text_template TEXT,is_active INTEGER,version INTEGER,updated_by INTEGER,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(event,locale,version))');
        $this->pdo->exec('CREATE TABLE communication_mail_settings(id INTEGER PRIMARY KEY,from_address TEXT,from_name TEXT,smtp_host TEXT,smtp_port INTEGER,smtp_encryption TEXT,smtp_username TEXT,smtp_password_ciphertext TEXT,smtp_timeout INTEGER,last_test_status TEXT,last_test_code TEXT,last_tested_at TEXT,updated_by INTEGER)');
        $this->pdo->exec('CREATE TABLE rate_limits(id INTEGER PRIMARY KEY,rate_key TEXT,action TEXT,window_started_at TEXT,attempts INTEGER,expires_at TEXT)');
        $this->pdo->exec('CREATE TABLE email_verification_challenges(id INTEGER PRIMARY KEY,user_id INTEGER,email TEXT,token_hash TEXT,expires_at TEXT,used_at TEXT)');
        $this->pdo->exec('CREATE TABLE communication_email_outbox(id INTEGER PRIMARY KEY,user_id INTEGER,dedupe_key TEXT,status TEXT,payload_ciphertext TEXT)');
        $this->router=new Router();$factory=require dirname(__DIR__,2).'/routes/communications.php';
        $factory($this->router,['pdo'=>$this->pdo,'view'=>new View(),'cipher'=>new SecretCipher(base64_encode(str_repeat('x',32))),'authenticated'=>fn($r,$next)=>$next($r),'admin_only'=>function($r,$next){return ($_SESSION['role']??'admin')==='admin'?$next($r):Response::text('Forbidden',403);},'mail_config'=>['transport'=>'smtp','smtp_host'=>'smtp.example.test','smtp_port'=>587,'smtp_encryption'=>'tls','smtp_username'=>'','smtp_password'=>'','from_address'=>'system@example.test'],'mailer_factory'=>function($config){return new class($this->sent) implements \App\Contracts\Mailer {public function __construct(private array &$sent){}public function send(string $to,string $subject,string $html):void{$this->sent[]=compact('to','subject','html');}};}]);
    }
    public function testTestSendRequiresCsrfAdminAndOwnAddressAndRateLimit():void {
        self::assertSame(419,$this->router->dispatch(Request::fake('POST','/admin/email-configuracao/testar'))->status());
        $body=['_token'=>Csrf::token(),'recipient'=>'other@example.test'];self::assertSame(422,$this->router->dispatch(Request::fake('POST','/admin/email-configuracao/testar',$body))->status());self::assertSame([],$this->sent);
        $_SESSION['role']='user';self::assertSame(403,$this->router->dispatch(Request::fake('POST','/admin/email-configuracao/testar',['_token'=>Csrf::token()]))->status());$_SESSION['role']='admin';
        for($i=0;$i<3;$i++)$this->router->dispatch(Request::fake('POST','/admin/email-configuracao/testar',['_token'=>Csrf::token()]));
        self::assertSame(429,$this->router->dispatch(Request::fake('POST','/admin/email-configuracao/testar',['_token'=>Csrf::token()]))->status());
        self::assertCount(3,$this->sent);self::assertSame('admin@example.test',$this->sent[0]['to']);
    }
    public function testPreviewAndVersionActionsAreBound():void {
        $this->router->dispatch(Request::fake('POST','/admin/emails',['_token'=>Csrf::token(),'event'=>'auth.welcome','subject_template'=>'Olá {{nome_usuario}}','html_template'=>'<p>Olá {{nome_usuario}}</p>','text_template'=>'Olá {{nome_usuario}}']));
        $id=(int)$this->pdo->query('SELECT MAX(id) FROM communication_email_templates')->fetchColumn();self::assertGreaterThan(0,$id);
        $preview=$this->router->dispatch(Request::fake('GET','/admin/emails/'.$id.'/preview'));self::assertSame(200,$preview->status());self::assertStringContainsString("default-src 'none'",$preview->header('Content-Security-Policy'));self::assertStringContainsString('Pessoa de exemplo',$preview->body());
        $this->router->dispatch(Request::fake('POST','/admin/emails/'.$id.'/duplicar',['_token'=>Csrf::token()]));$newId=(int)$this->pdo->query('SELECT MAX(id) FROM communication_email_templates')->fetchColumn();
        $this->router->dispatch(Request::fake('POST','/admin/emails/'.$newId.'/ativar',['_token'=>Csrf::token()]));self::assertSame($newId,(int)$this->pdo->query('SELECT id FROM communication_email_templates WHERE is_active=1')->fetchColumn());
    }
    public function testVerificationGetIsNonMutatingAndPostRequiresCsrf():void {
        $token=str_repeat('a',64);$s=$this->pdo->prepare("INSERT INTO email_verification_challenges VALUES(1,1,'admin@example.test',:hash,'2099-01-01',NULL)");$s->execute(['hash'=>hash('sha256',$token)]);
        $get=$this->router->dispatch(Request::fake('GET','/verificar-email?token='.$token));self::assertSame(200,$get->status());self::assertSame('no-referrer',$get->header('Referrer-Policy'));self::assertNull($this->pdo->query('SELECT used_at FROM email_verification_challenges')->fetchColumn());
        self::assertSame(419,$this->router->dispatch(Request::fake('POST','/verificar-email',['token'=>$token]))->status());
        self::assertSame(200,$this->router->dispatch(Request::fake('POST','/verificar-email',['_token'=>Csrf::token(),'token'=>$token]))->status());self::assertNotNull($this->pdo->query('SELECT email_verified_at FROM users')->fetchColumn());
    }
    public function testLogFallbackNeverReportsTestDelivery():void {
        $router=new Router();$factory=require dirname(__DIR__,2).'/routes/communications.php';
        $factory($router,['pdo'=>$this->pdo,'view'=>new View(),'cipher'=>new SecretCipher(base64_encode(str_repeat('x',32))),'authenticated'=>fn($r,$n)=>$n($r),'admin_only'=>fn($r,$n)=>$n($r),'mail_config'=>['environment'=>'testing','transport'=>'log','log_file'=>'unused.log'],'mailer_factory'=>function(){self::fail('Log configuration reached delivery factory');}]);
        self::assertSame(422,$router->dispatch(Request::fake('POST','/admin/email-configuracao/testar',['_token'=>Csrf::token()]))->status());self::assertSame([],$this->sent);
    }
    public function testRegistrationAndDeniedRoutesDoNotResolveDatabaseOrSecrets():void {
        $router=new Router();$factory=require dirname(__DIR__,2).'/routes/communications.php';$calls=0;
        $factory($router,['pdo'=>function()use(&$calls){++$calls;throw new \RuntimeException('Must remain lazy');},'cipher'=>function()use(&$calls){++$calls;throw new \RuntimeException('Must remain lazy');},'authenticated'=>fn($r,$n)=>Response::text('Forbidden',403),'admin_only'=>fn($r,$n)=>Response::text('Forbidden',403)]);
        self::assertSame(0,$calls);self::assertSame(403,$router->dispatch(Request::fake('GET','/admin/emails'))->status());self::assertSame(403,$router->dispatch(Request::fake('GET','/notificacoes'))->status());self::assertSame(0,$calls);
    }
}
