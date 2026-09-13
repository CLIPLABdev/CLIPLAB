<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Controllers\{AuthController,PasswordResetController,EmailVerificationController};
use App\Communications\EmailVerificationService;
use App\Contracts\CommunicationEmitter;
use App\Core\{Request,Session,View};
use App\Services\RateLimiter;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuthenticationInputHardeningTest extends TestCase
{
    private PDO $pdo;private array $warnings=[];
    protected function setUp():void {
        $_SESSION=[];$this->pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE rate_limits(id INTEGER PRIMARY KEY,rate_key TEXT,action TEXT,window_started_at TEXT,attempts INTEGER DEFAULT 0,expires_at TEXT,UNIQUE(rate_key,action))');
        $this->pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT,password_hash TEXT,status TEXT)');
        $this->pdo->exec('CREATE TABLE plans(id INTEGER PRIMARY KEY,slug TEXT,credits INTEGER,is_active INTEGER)');
        set_error_handler(function(int $severity,string $message):bool{$this->warnings[]=$message;return true;});
    }
    protected function tearDown():void {restore_error_handler();$_SESSION=[];}
    public static function invalidValues():array {return ['array'=>[['unexpected']],'nested array'=>[['x'=>['nested']]],'true'=>[true],'false'=>[false],'integer'=>[123],'null'=>[null]];}

    /** @dataProvider invalidValues */
    public function testRegistrationRejectsEachNonStringBeforeServiceOrLimiter(mixed $invalid):void {
        $calls=0;$factory=function()use(&$calls){++$calls;return $this->authService();};
        $limiter=function()use(&$calls){++$calls;return new RateLimiter($this->pdo);};
        $controller=new AuthController(new View(),$factory,$limiter);
        foreach(['name','email','password','password_confirmation'] as $field){
            $input=['name'=>'Pessoa Válida','email'=>'pessoa@example.test','password'=>'valid-password-123','password_confirmation'=>'valid-password-123'];$input[$field]=$invalid;
            $response=$controller->register(Request::fake('POST','/cadastro',$input));
            self::assertSame('/cadastro',$response->header('Location'));
            self::assertNotEmpty(Session::pull('auth_errors'));$old=Session::pull('auth_old');
            self::assertIsString($old['name']);self::assertIsString($old['email']);self::assertArrayNotHasKey('password',$old);
        }
        self::assertSame(0,$calls);self::assertSame([],$this->warnings);
    }

    /** @dataProvider invalidValues */
    public function testLoginRejectsNonStringsButStillCountsRateAttempts(mixed $invalid):void {
        $calls=0;$factory=function()use(&$calls){++$calls;return $this->authService();};
        $controller=new AuthController(new View(),$factory,fn()=>new RateLimiter($this->pdo));
        foreach(['email','password'] as $field){$input=['email'=>'pessoa@example.test','password'=>'valid-password-123'];$input[$field]=$invalid;
            $response=$controller->login(Request::fake('POST','/login',$input));self::assertSame('/login',$response->header('Location'));
            self::assertSame(['form'=>'Não foi possível entrar com estas credenciais.'],Session::pull('auth_errors'));
        }
        self::assertSame(0,$calls);self::assertSame(2,(int)$this->pdo->query("SELECT attempts FROM rate_limits WHERE action='login-ip'")->fetchColumn());self::assertSame([],$this->warnings);
    }

    /** @dataProvider invalidValues */
    public function testResetRequestKeepsGenericResponseAndLimitsWithoutInitializingService(mixed $invalid):void {
        $calls=0;$factory=function()use(&$calls){++$calls;throw new \LogicException('Malformed email reached reset');};
        $controller=new PasswordResetController(new View(),$factory,fn()=>new RateLimiter($this->pdo));
        $response=$controller->requestReset(Request::fake('POST','/esqueci-minha-senha',['email'=>$invalid]));
        self::assertSame('/esqueci-minha-senha',$response->header('Location'));self::assertStringContainsString('Se existir uma conta',Session::pull('password_reset_message'));
        self::assertSame(0,$calls);self::assertSame(1,(int)$this->pdo->query("SELECT attempts FROM rate_limits WHERE action='password-reset-ip'")->fetchColumn());self::assertSame([],$this->warnings);
    }

    /** @dataProvider invalidValues */
    public function testResetFieldsAndGetTokenAreNeverCoercedFromNonStrings(mixed $invalid):void {
        $calls=0;$factory=function()use(&$calls){++$calls;throw new \LogicException('Malformed reset reached service');};
        $controller=new PasswordResetController(new View(),$factory);
        $form=$controller->showResetPassword(new Request('GET','/redefinir-senha',['token'=>$invalid]));
        self::assertSame(200,$form->status());self::assertStringContainsString('name="token" value=""',$form->body());
        foreach(['token','password','password_confirmation'] as $field){$input=['token'=>str_repeat('a',64),'password'=>'valid-password-123','password_confirmation'=>'valid-password-123'];$input[$field]=$invalid;
            $response=$controller->reset(Request::fake('POST','/redefinir-senha',$input));self::assertSame(302,$response->status());
            self::assertStringNotContainsString('Array',(string)$response->header('Location'));
        }
        self::assertSame(0,$calls);self::assertSame([],$this->warnings);
    }

    /** @dataProvider invalidValues */
    public function testVerificationRejectsMalformedTokenWithoutWarningOrChallengeQuery(mixed $invalid):void {
        $emitter=new class implements CommunicationEmitter {
            public function emit(int $u,string $e,array $v,string $d,?string $r=null,array $c=['in_app','email'],?\DateTimeImmutable $a=null):void{throw new \LogicException('No delivery');}
            public function cancelByDedupePrefix(int $u,string $p):void{throw new \LogicException('No cancellation');}
        };
        // No challenge table: malformed tokens must not reach persistence.
        $controller=new EmailVerificationController(new View(),new EmailVerificationService($this->pdo,$emitter,'https://app.example.test'),new RateLimiter($this->pdo));
        $form=$controller->form(new Request('GET','/verificar-email',['token'=>$invalid]));self::assertSame(200,$form->status());
        $response=$controller->confirm(Request::fake('POST','/verificar-email',['token'=>$invalid]));
        self::assertSame(200,$response->status());self::assertStringContainsString('O link é inválido',$response->body());
        self::assertSame(1,(int)$this->pdo->query("SELECT attempts FROM rate_limits WHERE action='email-verification-confirm'")->fetchColumn());self::assertSame([],$this->warnings);
    }
    private function authService():\App\Services\AuthService {return new \App\Services\AuthService($this->pdo,new \App\Repositories\UserRepository($this->pdo),new \App\Repositories\PlanRepository($this->pdo));}
}
