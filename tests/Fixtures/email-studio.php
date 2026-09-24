<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use App\Communications\{CommunicationTemplateService,CommunicationMailSettingsService};
use App\Controllers\CommunicationAdminController;
use App\Core\{Request,View};
use App\Middleware\SecurityHeadersMiddleware;
use App\Security\SecretCipher;
use App\Services\RateLimiter;
session_set_save_handler(new class implements SessionHandlerInterface {
    public function open($path,$name):bool{return true;} public function close():bool{return true;}
    public function read($id):string{return '';} public function write($id,$data):bool{return true;}
    public function destroy($id):bool{return true;} public function gc($max):int{return 0;}
});
session_start();$_SESSION=['user_id'=>1];
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,name TEXT,email TEXT,role TEXT,status TEXT)');
$pdo->exec("INSERT INTO users VALUES(1,'Equipe ClipLab','admin@example.test','admin','active')");
$pdo->exec('CREATE TABLE communication_email_templates(id INTEGER PRIMARY KEY AUTOINCREMENT,event TEXT,locale TEXT,subject_template TEXT,html_template TEXT,text_template TEXT,is_active INTEGER,version INTEGER,updated_by INTEGER,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE communication_mail_settings(id INTEGER PRIMARY KEY)');
$service=new CommunicationTemplateService($pdo);$service->installDefaults();
$copy=$service->systemTemplate('auth.welcome');$service->create('auth.welcome',$copy['subject_template'],$copy['html_template'],$copy['text_template'],1);
$controller=new CommunicationAdminController(new View(),$pdo,new CommunicationMailSettingsService($pdo,new SecretCipher(base64_encode(str_repeat('x',32))),['transport'=>'log']),new RateLimiter($pdo),static function(){throw new RuntimeException('No SMTP in fixtures.');});
$middleware=new SecurityHeadersMiddleware();$result=[];
$paths=['/admin/emails','/admin/email-configuracao','/admin/emails?modelo=auth.password_reset'];
foreach($pdo->query('SELECT id FROM communication_email_templates')->fetchAll(PDO::FETCH_COLUMN) as $id)$paths[]='/admin/emails/'.$id.'/preview';
foreach($paths as $path) {
    $request=Request::fake('GET',$path);
    $response=$middleware->handle($request,static function($request)use($controller,$path){
        if(preg_match('~/([0-9]+)/preview$~',$path,$m))return $controller->preview($request,['id'=>$m[1]]);
        return $path==='/admin/email-configuracao'?$controller->settings($request):$controller->templates($request);
    });
    $result[$path]=['status'=>$response->status(),'body'=>$response->body(),'headers'=>['Content-Type'=>$response->header('Content-Type'),'Content-Security-Policy'=>$response->header('Content-Security-Policy'),'Referrer-Policy'=>$response->header('Referrer-Policy'),'Cache-Control'=>$response->header('Cache-Control','no-store')]];
}
echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
