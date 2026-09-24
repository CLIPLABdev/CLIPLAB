<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Communications\{CommunicationMailSettingsService,CommunicationTemplateService,CommunicationEventCatalog,EmailTemplateRenderer,EmailTestSendService,EmailDocument};
use App\Core\{Request,Response,Session,View};
use App\Services\{RateLimiter,MailerFactory,LogMailer};
use PDO;

final class CommunicationAdminController
{
    private CommunicationTemplateService $templates;private \Closure $mailerFactory;
    public function __construct(private View $view,private PDO $pdo,private CommunicationMailSettingsService $settings,private RateLimiter $limiter,?callable $mailerFactory=null) {
        $this->templates=new CommunicationTemplateService($pdo);$this->mailerFactory=$mailerFactory===null?\Closure::fromCallable([MailerFactory::class,'make']):\Closure::fromCallable($mailerFactory);
    }
    private function actor():array {
        $s=$this->pdo->prepare("SELECT id,name,email FROM users WHERE id=:id AND role='admin' AND status='active'");$s->execute(['id'=>(int)Session::get('user_id',0)]);$row=$s->fetch(PDO::FETCH_ASSOC);if(!is_array($row))throw new \DomainException('Administrador não autorizado.');return $row;
    }
    public function templates(Request $r):Response {
        $admin=$this->actor();$this->templates->installDefaults();$rows=$this->pdo->query('SELECT * FROM communication_email_templates ORDER BY event,version DESC')->fetchAll(PDO::FETCH_ASSOC);
        $editing=(int)$r->query('editar',0);$edit=$editing>0?$this->templates->find($editing):null;
        $system=(string)$r->query('modelo','');
        if($editing===0 && isset((new CommunicationEventCatalog())->events()[$system]))$edit=$this->templates->systemTemplate($system);
        return $this->view->render('communications.admin-templates',['title'=>'E-mails','admin'=>$admin,'templates'=>$rows,'editing'=>$edit,'events'=>(new CommunicationEventCatalog())->events(),'message'=>Session::pull('communications_admin_message')]);
    }
    public function saveTemplate(Request $r):Response {
        try {$this->templates->create(trim((string)$r->input('event','')),trim((string)$r->input('subject_template','')),(string)$r->input('html_template',''),(string)$r->input('text_template',''),(int)$this->actor()['id']);Session::flash('communications_admin_message','Nova versão salva como rascunho. Revise e ative quando estiver pronta.');}
        catch(\InvalidArgumentException $e){Session::flash('communications_admin_message',$e->getMessage());}
        catch(\Throwable){Session::flash('communications_admin_message','Não foi possível salvar. Verifique as migrações e tente novamente.');}
        return Response::redirect('/admin/emails');
    }
    public function duplicate(Request $r,array $p):Response {return $this->templateAction('duplicate',(int)($p['id']??0));}
    public function activate(Request $r,array $p):Response {return $this->templateAction('activate',(int)($p['id']??0));}
    private function templateAction(string $action,int $id):Response {
        try {$this->templates->$action($id,(int)$this->actor()['id']);Session::flash('communications_admin_message',$action==='activate'?'Versão ativada.':'Versão duplicada como rascunho.');}
        catch(\InvalidArgumentException $e){Session::flash('communications_admin_message',$e->getMessage());}catch(\Throwable){Session::flash('communications_admin_message','Não foi possível alterar o template.');}
        return Response::redirect('/admin/emails');
    }
    private function rendered(int $id,bool $preview=false):array {
        $row=$this->templates->find($id);$variables=$this->templates->exampleVariables($row['event']);$renderer=new EmailTemplateRenderer();
        $subject=$renderer->renderSubject($row['subject_template'],$variables,array_keys($variables));
        return [$subject,(new EmailDocument())->render($subject,$renderer->render($row['html_template'],$variables,array_keys($variables)),$preview)];
    }
    public function preview(Request $r,array $p):Response {
        $this->actor();try{[$subject,$html]=$this->rendered((int)($p['id']??0),true);$response=Response::html($html);}
        catch(\Throwable){$response=Response::text('Template indisponível ou inválido.',422);}
        return $response->withHeader('Content-Security-Policy',"default-src 'none'; style-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'; sandbox")->withHeader('Referrer-Policy','no-referrer')->withHeader('Cache-Control','no-store');
    }
    public function settings(Request $r):Response {return $this->view->render('communications.admin-settings',['title'=>'Configuração de e-mail','admin'=>$this->actor(),'settings'=>$this->settings->publicState(),'message'=>Session::pull('communications_admin_message')]);}
    public function saveSettings(Request $r):Response {
        try{$this->settings->save((int)$this->actor()['id'],['from_address'=>$r->input('from_address',''),'from_name'=>$r->input('from_name',''),'smtp_host'=>$r->input('smtp_host',''),'smtp_port'=>$r->input('smtp_port',''),'smtp_encryption'=>$r->input('smtp_encryption',''),'smtp_username'=>$r->input('smtp_username',''),'smtp_password'=>$r->input('smtp_password',''),'smtp_timeout'=>$r->input('smtp_timeout','')]);Session::flash('communications_admin_message','Configuração SMTP salva.');}
        catch(\Throwable){Session::flash('communications_admin_message','Configuração inválida. Use TLS/SSL, remetente válido e credenciais completas.');}return Response::redirect('/admin/email-configuracao');
    }
    public function testSend(Request $r):Response {
        $actor=$this->actor();$email=(string)$actor['email'];$recipient=mb_strtolower(trim((string)$r->input('recipient',$email)));
        if(!hash_equals(mb_strtolower($email),$recipient))return Response::text('O teste só pode ser enviado ao seu próprio e-mail.',422);
        if(!$this->limiter->hit('smtp-test',(string)$actor['id'],3,900))return Response::text('Limite de testes atingido. Tente em 15 minutos.',429)->withHeader('Retry-After','900');
        try {
            $config=$this->settings->effective();if(($config['transport']??'')!=='smtp')throw new \RuntimeException('SMTP indisponível.');
            MailerFactory::make($config); // Validate before using the injected transport factory; no network activity.
            $mailer=($this->mailerFactory)($config);if($mailer instanceof LogMailer)throw new \RuntimeException('Log não entrega e-mails.');
            $id=(int)$r->input('template_id',0);[$subject,$html]=$id>0?$this->rendered($id):['Teste de SMTP ClipLab',(new EmailDocument())->render('Teste de SMTP ClipLab','<h1>Seu teste de envio.</h1><p>Este e-mail foi solicitado no painel de administração do ClipLab para conferir a configuração SMTP.</p><p>Se você recebeu esta mensagem, confira também o nome e o endereço do remetente.</p>')];
            (new EmailTestSendService($mailer))->send($email,$recipient,$subject,$html);$this->settings->recordTest(true);Session::flash('communications_admin_message','Servidor SMTP aceitou o e-mail de teste. Confira a caixa de entrada e spam; o aceite não comprova entrega.');
        }catch(\Throwable){$this->settings->recordTest(false);return Response::text('O teste não foi aceito. Verifique a configuração SMTP e as credenciais.',422);}
        return Response::redirect('/admin/email-configuracao');
    }
}
