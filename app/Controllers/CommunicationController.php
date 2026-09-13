<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Communications\CommunicationInboxService;
use App\Core\{Request,Response,Session,View};
use App\Repositories\UserRepository;
final class CommunicationController
{
    public function __construct(private View $view,private CommunicationInboxService $inbox,private UserRepository $users) {}
    private function user():int {$id=(int)Session::get('user_id',0);if($id<1)throw new \DomainException('Sessão inválida.');return $id;}
    public function notifications(Request $r):Response {$id=$this->user();return $this->view->render('communications.notifications',['title'=>'Notificações','user'=>$this->users->findDashboardProfile($id),'items'=>$this->inbox->notifications($id),'message'=>Session::pull('communications_message')]);}
    public function read(Request $r,array $p):Response {$this->inbox->markRead($this->user(),(int)($p['id']??0));return Response::redirect('/notificacoes');}
    public function preferences(Request $r):Response {$id=$this->user();return $this->view->render('communications.preferences',['title'=>'Preferências','user'=>$this->users->findDashboardProfile($id),'preferences'=>$this->inbox->preferences($id),'message'=>Session::pull('communications_message')]);}
    public function savePreferences(Request $r):Response {
        $input=[];foreach(['processing_email','processing_in_app','usage_email','usage_in_app','marketing_opt_in'] as $field)$input[$field]=$r->input($field);
        $this->inbox->savePreferences($this->user(),$input);Session::flash('communications_message','Preferências atualizadas.');return Response::redirect('/preferencias');
    }
}
