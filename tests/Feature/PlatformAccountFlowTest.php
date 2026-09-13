<?php
declare(strict_types=1);
namespace Tests\Feature;

use App\Core\{Csrf,Database,Request,Router,Session};
use App\Security\SecretCipher;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestDatabase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class PlatformAccountFlowTest extends TestCase
{
    private PDO $pdo;
    private Router $router;

    protected function setUp():void
    {
        Session::start(); $_SESSION=[];
        putenv('APP_URL=http://localhost:8093');
        putenv('APP_ENCRYPTION_KEY='.base64_encode(str_repeat('t',32)));
        $this->pdo=AdminTestDatabase::create(); AdminTestDatabase::seed($this->pdo);
        $this->pdo->exec('ALTER TABLE users ADD COLUMN email_verified_at TEXT');
        $this->pdo->exec('ALTER TABLE users ADD COLUMN avatar_path TEXT');
        $this->pdo->exec('CREATE TABLE email_verification_challenges(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,email TEXT,token_hash TEXT UNIQUE,expires_at TEXT,used_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->pdo->exec('CREATE TABLE account_email_changes(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,current_email TEXT,requested_email TEXT,token_hash TEXT UNIQUE,expires_at TEXT,consumed_at TEXT,revoked_at TEXT,created_at TEXT)');
        $this->pdo->exec('CREATE TABLE password_reset_tokens(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,token_hash TEXT UNIQUE,expires_at TEXT,used_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $this->pdo->exec('CREATE TABLE communication_notifications(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,event TEXT,category TEXT,title TEXT,body TEXT,dedupe_key TEXT,read_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(user_id,dedupe_key))');
        $this->pdo->exec("CREATE TABLE communication_email_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,recipient TEXT,event TEXT,category TEXT,payload_ciphertext TEXT,dedupe_key TEXT UNIQUE,status TEXT DEFAULT 'pending',attempts INTEGER DEFAULT 0,available_at TEXT,leased_until TEXT,lease_token_hash TEXT,provider_message_id TEXT,last_error_code TEXT,sent_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $this->pdo->exec('CREATE TABLE communication_preferences(user_id INTEGER,category TEXT,email_enabled INTEGER,in_app_enabled INTEGER,marketing_opted_in_at TEXT,unsubscribe_token_hash TEXT,updated_at TEXT,PRIMARY KEY(user_id,category))');
        $property=new \ReflectionProperty(Database::class,'connection');$property->setAccessible(true);$property->setValue(null,$this->pdo);
        $this->router=require dirname(__DIR__,2).'/routes/web.php';
    }

    private function post(string $path,array $input=[]):\App\Core\Response
    {
        return $this->router->dispatch(Request::fake('POST',$path,$input+['_token'=>Csrf::token()]));
    }

    private function token(string $event,string $variable):string
    {
        $s=$this->pdo->prepare('SELECT payload_ciphertext FROM communication_email_outbox WHERE event=? ORDER BY id DESC LIMIT 1');$s->execute([$event]);
        $encrypted=$s->fetchColumn();self::assertIsString($encrypted,'Durable outbox missing '.$event);
        $payload=json_decode((new SecretCipher((string)getenv('APP_ENCRYPTION_KEY')))->decrypt($encrypted,'clipforge:communications:v1'),true,32,JSON_THROW_ON_ERROR);
        parse_str((string)parse_url($payload['variables'][$variable],PHP_URL_QUERY),$query);
        return $query['token'];
    }

    public function testRegistrationAndAccountSecurityAreConnectedToDurableQueue():void
    {
        $response=$this->post('/cadastro',['name'=>'Integration QA','email'=>'qa-flow@example.test','password'=>'Flow-password-2026','password_confirmation'=>'Flow-password-2026']);
        self::assertSame('/dashboard',$response->header('Location'));
        $id=(int)Session::get('user_id');self::assertGreaterThan(0,$id);
        self::assertSame(2,(int)$this->pdo->query('SELECT COUNT(*) FROM communication_email_outbox')->fetchColumn(),'Registration must queue welcome and verification');
        $token=$this->token('auth.email_verification','link_confirmacao');
        self::assertSame(200,$this->router->dispatch(Request::fake('GET','/verificar-email?token='.$token))->status());
        self::assertNull($this->pdo->query('SELECT email_verified_at FROM users WHERE id='.$id)->fetchColumn(),'GET must not consume confirmation');
        self::assertSame(419,$this->router->dispatch(Request::fake('POST','/verificar-email',['token'=>$token]))->status());
        self::assertSame(200,$this->post('/verificar-email',['token'=>$token])->status());
        self::assertNotNull($this->pdo->query('SELECT email_verified_at FROM users WHERE id='.$id)->fetchColumn());

        $profile=$this->router->dispatch(Request::fake('GET','/perfil'));
        self::assertSame(200,$profile->status());self::assertStringContainsString('/perfil/senha',$profile->body());self::assertStringContainsString('/notificacoes',$profile->body());
        self::assertSame('/perfil',$this->post('/perfil',['name'=>'Updated QA','email'=>'updated-qa@example.test','current_password'=>'Flow-password-2026'])->header('Location'));
        self::assertSame('qa-flow@example.test',$this->pdo->query('SELECT email FROM users WHERE id='.$id)->fetchColumn());
        $token=$this->token('account.email_change_requested','link_confirmacao');
        self::assertSame(200,$this->router->dispatch(Request::fake('GET','/perfil/confirmar-email?token='.$token))->status());
        self::assertSame('qa-flow@example.test',$this->pdo->query('SELECT email FROM users WHERE id='.$id)->fetchColumn());
        $this->post('/perfil/confirmar-email',['token'=>$token]);
        self::assertSame('updated-qa@example.test',$this->pdo->query('SELECT email FROM users WHERE id='.$id)->fetchColumn());
        $this->post('/perfil/senha',['current_password'=>'Flow-password-2026','password'=>'Changed-password-2026','password_confirmation'=>'Changed-password-2026']);
        self::assertTrue(password_verify('Changed-password-2026',$this->pdo->query('SELECT password_hash FROM users WHERE id='.$id)->fetchColumn()));
        self::assertSame(1,(int)$this->pdo->query("SELECT COUNT(*) FROM communication_email_outbox WHERE event='account.password_changed'")->fetchColumn());
        self::assertSame(404,$this->router->dispatch(Request::fake('GET','/perfil/avatar'))->status());

        $this->post('/logout');
        self::assertSame('/esqueci-minha-senha',$this->post('/esqueci-minha-senha',['email'=>'updated-qa@example.test'])->header('Location'));
        $token=$this->token('auth.password_reset','link_recuperacao');
        self::assertSame('/login',$this->post('/redefinir-senha',['token'=>$token,'password'=>'Recovered-password-2026','password_confirmation'=>'Recovered-password-2026'])->header('Location'));
        self::assertTrue(password_verify('Recovered-password-2026',$this->pdo->query('SELECT password_hash FROM users WHERE id='.$id)->fetchColumn()));
        self::assertSame(0,(int)$this->pdo->query("SELECT COUNT(*) FROM communication_email_outbox WHERE status='sent'")->fetchColumn(),'HTTP requests must not synchronously send email');
    }

    public function testUnavailableEncryptionRollsBackRegistrationButDoesNotBreakLoginPage():void
    {
        putenv('APP_ENCRYPTION_KEY=invalid');
        self::assertSame(200,$this->router->dispatch(Request::fake('GET','/login'))->status());
        $response=$this->post('/cadastro',['name'=>'Rollback QA','email'=>'rollback@example.test','password'=>'Flow-password-2026','password_confirmation'=>'Flow-password-2026']);
        self::assertSame('/cadastro',$response->header('Location'));
        self::assertSame(['form' => 'O ambiente de configuração está incompleto. Configure uma APP_ENCRYPTION_KEY válida antes de criar a conta.'], Session::get('_flash_auth_errors'));
        self::assertSame(0,(int)$this->pdo->query("SELECT COUNT(*) FROM users WHERE email='rollback@example.test'")->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM communication_email_outbox')->fetchColumn());
    }
}
