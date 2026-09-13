<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Communications\{CommunicationEmitterService,CommunicationEventCatalog,CommunicationInboxService,EmailOutboxWorker};
use App\Contracts\Mailer;
use App\Core\{Request,Router};
use App\Middleware\AuthMiddleware;
use App\Security\SecretCipher;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestDatabase;

final class CommunicationPreferencesIntegrationTest extends TestCase
{
    private PDO $pdo; private array $ids; private SecretCipher $cipher;
    protected function setUp():void {
        $this->pdo=AdminTestDatabase::create();$this->ids=AdminTestDatabase::seed($this->pdo);
        $this->cipher=new SecretCipher(base64_encode(str_repeat('k',32)));$_SESSION=[];
        $this->pdo->exec('CREATE TABLE communication_preferences(user_id INTEGER,category TEXT,email_enabled INTEGER,in_app_enabled INTEGER,marketing_opted_in_at TEXT,updated_at TEXT,PRIMARY KEY(user_id,category))');
        $this->pdo->exec('CREATE TABLE communication_notifications(id INTEGER PRIMARY KEY,user_id INTEGER,event TEXT,category TEXT,title TEXT,body TEXT,dedupe_key TEXT,read_at TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(user_id,dedupe_key))');
        $this->pdo->exec("CREATE TABLE communication_email_outbox(id INTEGER PRIMARY KEY,user_id INTEGER,recipient TEXT,event TEXT,category TEXT,payload_ciphertext TEXT,dedupe_key TEXT UNIQUE,status TEXT,attempts INTEGER DEFAULT 0,available_at TEXT,leased_until TEXT,lease_token_hash TEXT,last_error_code TEXT,sent_at TEXT)");
        $this->pdo->exec('CREATE TABLE communication_email_attempts(id INTEGER PRIMARY KEY,outbox_id INTEGER,attempt_number INTEGER,outcome TEXT,error_code TEXT,UNIQUE(outbox_id,attempt_number))');
        $this->pdo->exec("CREATE TABLE communication_email_templates(id INTEGER PRIMARY KEY,event TEXT,locale TEXT,subject_template TEXT,html_template TEXT,is_active INTEGER,version INTEGER)");
        $this->pdo->exec("INSERT INTO communication_email_templates VALUES(1,'media.processing_completed','pt-BR','Pronto','<p>{{nome_projeto}}</p>',1,1)");
    }
    protected function tearDown():void {$_SESSION=[];}
    private function router():Router {
        $router=new Router();$register=require dirname(__DIR__,2).'/routes/communications.php';
        $register($router,['pdo'=>$this->pdo,'authenticated'=>new AuthMiddleware(new \App\Repositories\UserRepository($this->pdo)),'admin_only'=>static fn()=>false]);return $router;
    }
    private function emitter():CommunicationEmitterService{return new CommunicationEmitterService($this->pdo,$this->cipher,new CommunicationEventCatalog());}
    private function preference(string $category,int $email,int $inApp,?int $user=null):void {
        $s=$this->pdo->prepare('INSERT INTO communication_preferences VALUES(?,?,?,?,NULL,CURRENT_TIMESTAMP) ON CONFLICT(user_id,category) DO UPDATE SET email_enabled=excluded.email_enabled,in_app_enabled=excluded.in_app_enabled');
        $s->execute([$user??$this->ids['user_id'],$category,$email,$inApp]);
    }
    public function testPreferencesPostIsOwnerScopedRepeatableAndGetReflectsIndependentChannels():void {
        $id=$this->ids['user_id'];$other=$this->ids['other_admin_id'];$router=$this->router();
        $_SESSION=['user_id'=>$id,'_csrf'=>str_repeat('c',64)];
        $this->preference('processing',1,1,$other);
        $post=['_token'=>str_repeat('c',64),'user_id'=>$other,'processing_email'=>'1','usage_email'=>['1'],'usage_in_app'=>'1','marketing_opt_in'=>['1'],'account_email'=>'0','billing_email'=>'0'];
        for($i=0;$i<2;$i++)self::assertSame(302,$router->dispatch(Request::fake('POST','/preferencias',$post))->status());
        $s=$this->pdo->query("SELECT category,email_enabled,in_app_enabled FROM communication_preferences WHERE user_id=$id ORDER BY category");
        $rows=array_map(static fn(array $r)=>['category'=>$r['category'],'email_enabled'=>(int)$r['email_enabled'],'in_app_enabled'=>(int)$r['in_app_enabled']],$s->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame([['category'=>'marketing','email_enabled'=>0,'in_app_enabled'=>1],['category'=>'processing','email_enabled'=>1,'in_app_enabled'=>0],['category'=>'usage','email_enabled'=>0,'in_app_enabled'=>1]],$rows);
        self::assertSame(1,(int)$this->pdo->query("SELECT in_app_enabled FROM communication_preferences WHERE user_id=$other")->fetchColumn());
        $response=$router->dispatch(Request::fake('GET','/preferencias'));self::assertSame(200,$response->status());
        $doc=new \DOMDocument();@$doc->loadHTML($response->body());$xp=new \DOMXPath($doc);
        foreach(['processing_email'=>true,'processing_in_app'=>false,'usage_email'=>false,'usage_in_app'=>true,'marketing_opt_in'=>false] as $name=>$enabled){
            self::assertSame(1,$xp->query('//input[@name="'.$name.'"]')->length);
            self::assertSame($enabled,$xp->query('//input[@name="'.$name.'"][@checked]')->length===1);
        }
        self::assertStringContainsString('obrigatórias',$response->body());
    }
    public function testGuestAndCsrfDoNotWritePreferences():void {
        $router=$this->router();self::assertSame(302,$router->dispatch(Request::fake('GET','/preferencias'))->status());
        $_SESSION=['user_id'=>$this->ids['user_id'],'_csrf'=>str_repeat('c',64)];
        self::assertSame(419,$router->dispatch(Request::fake('POST','/preferencias',['processing_email'=>'1']))->status());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM communication_preferences')->fetchColumn());
    }
    public function testEmissionHonorsIndependentOptionalChannelsButNotForgedMandatoryOptOut():void {
        $id=$this->ids['user_id'];$this->preference('processing',0,1);$this->preference('usage',1,0);$this->preference('account',0,0);$this->preference('billing',0,0);
        $emitter=$this->emitter();
        for($i=0;$i<2;$i++)$emitter->emit($id,'media.processing_completed',['nome_usuario'=>'Cliente','nome_projeto'=>'Projeto'],'processing:1');
        $emitter->emit($id,'media.usage_limit_reached',['nome_usuario'=>'Cliente','nome_plano'=>'Free'],'usage:1');
        $emitter->emit($id,'account.password_changed',['nome_usuario'=>'Cliente'],'account:1');
        $emitter->emit($id,'marketing.campaign',['nome_usuario'=>'Cliente','titulo'=>'Oferta','conteudo'=>'Texto'],'marketing:1');
        $variables=array_fill_keys((new CommunicationEventCatalog())->events()['billing.payment_approved']['variables'],'teste');
        $emitter->emit($id,'billing.payment_approved',$variables,'billing:1');
        self::assertSame(['usage','account','billing'],$this->pdo->query('SELECT category FROM communication_email_outbox ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(['processing','account','billing'],$this->pdo->query('SELECT category FROM communication_notifications ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    }
    public function testOptOutAfterEnqueueCancelsWithoutRetryAndScrubsPayload():void {
        $id=$this->ids['user_id'];$this->emitter()->emit($id,'media.processing_completed',['nome_usuario'=>'Cliente','nome_projeto'=>'Projeto'],'processing:queued');
        $this->preference('processing',0,1);
        $mailer=new class implements Mailer {public int $sent=0;public function send(string $r,string $s,string $h):void{++$this->sent;}};
        $worker=new EmailOutboxWorker($this->pdo,$this->cipher,$mailer);
        self::assertSame('cancelled',$worker->processOne());self::assertSame('idle',$worker->processOne());self::assertSame(0,$mailer->sent);
        self::assertNull($this->pdo->query('SELECT payload_ciphertext FROM communication_email_outbox')->fetchColumn());
        self::assertSame('opt_out',$this->pdo->query('SELECT error_code FROM communication_email_attempts')->fetchColumn());
    }

    public function testPreferenceChangeDuringTemplatePreparationIsCheckedAgainBeforeTransport():void {
        $id=$this->ids['user_id'];$this->emitter()->emit($id,'media.processing_completed',['nome_usuario'=>'Cliente','nome_projeto'=>'Projeto'],'processing:late');
        $changed=false;
        $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PreferenceInterleavingStatement::class,[function(string $sql)use(&$changed):void {
            if(!$changed&&str_starts_with($sql,'SELECT subject_template,html_template')){$changed=true;$this->preference('processing',0,1);}
        }]]);
        $mailer=new class implements Mailer {public int $sent=0;public function send(string $r,string $s,string $h):void{++$this->sent;}};
        self::assertSame('cancelled',(new EmailOutboxWorker($this->pdo,$this->cipher,$mailer))->processOne());
        self::assertTrue($changed);self::assertSame(0,$mailer->sent);self::assertSame('opt_out',$this->pdo->query('SELECT last_error_code FROM communication_email_outbox')->fetchColumn());
    }

    public function testSavingPreferencesRollsBackAllCategoriesOnFailure():void {
        $id=$this->ids['user_id'];$this->preference('processing',1,1);
        $this->pdo->exec("CREATE TRIGGER refuse_usage BEFORE INSERT ON communication_preferences WHEN NEW.category='usage' BEGIN SELECT RAISE(ABORT,'fixture failure'); END");
        try{(new CommunicationInboxService($this->pdo))->savePreferences($id,[]);self::fail('Expected persistence failure');}catch(\PDOException){}
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM communication_preferences')->fetchColumn());
        self::assertSame(1,(int)$this->pdo->query("SELECT email_enabled FROM communication_preferences WHERE category='processing'")->fetchColumn());
    }

    public function testAllOptionalChannelsDisabledIsANoOpInsideCallerTransaction():void {
        $id=$this->ids['user_id'];$this->preference('processing',0,0);
        $this->pdo->beginTransaction();
        $this->emitter()->emit($id,'media.processing_completed',['nome_usuario'=>'Cliente','nome_projeto'=>'Projeto'],'processing:none');
        self::assertTrue($this->pdo->inTransaction());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM communication_email_outbox')->fetchColumn());
        self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM communication_notifications')->fetchColumn());
        $this->pdo->rollBack();
    }

    public function testMarketingRequiresExplicitScalarConsentAndOptOutRemovesIt():void {
        $id=$this->ids['user_id'];$inbox=new CommunicationInboxService($this->pdo);
        self::assertFalse($inbox->marketingOptedIn($id));
        $inbox->savePreferences($id,['marketing_opt_in'=>'1']);self::assertTrue($inbox->marketingOptedIn($id));
        $inbox->savePreferences($id,['marketing_opt_in'=>['1']]);self::assertFalse($inbox->marketingOptedIn($id));
        self::assertNull($this->pdo->query("SELECT marketing_opted_in_at FROM communication_preferences WHERE category='marketing'")->fetchColumn());
        self::assertSame(3,(int)$this->pdo->query('SELECT COUNT(*) FROM communication_preferences')->fetchColumn());
    }
}

/** Runs a deterministic competing preference write at the real template SQL boundary. */
final class PreferenceInterleavingStatement extends \PDOStatement
{
    protected function __construct(private \Closure $afterExecute){}
    public function execute(?array $params=null):bool {$result=parent::execute($params);($this->afterExecute)($this->queryString);return $result;}
}
