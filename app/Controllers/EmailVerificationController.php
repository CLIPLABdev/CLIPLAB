<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Communications\EmailVerificationService;
use App\Core\{Request,Response,Session,View};
use App\Services\RateLimiter;
final class EmailVerificationController
{
    public function __construct(private View $view,private EmailVerificationService $verification,private RateLimiter $limiter) {}
    public function form(Request $r):Response {$token=$r->query('token');return $this->page(is_string($token)?$token:'',null);}
    public function confirm(Request $r):Response {
        if(!$this->limiter->hit('email-verification-confirm',$r->clientIp(),20,900))return Response::text('Tente novamente em 15 minutos.',429);
        $token=$r->input('token');
        try{$ok=is_string($token)&&$this->verification->confirm($token);return $this->page('',$ok?'E-mail confirmado.':'O link é inválido, já foi utilizado ou expirou.');}catch(\Throwable){return $this->page('','Não foi possível confirmar agora. Tente novamente.');}
    }
    public function resend(Request $r):Response {
        $id=(int)Session::get('user_id',0);if($id<1)return Response::text('Não autorizado.',403);
        if(!$this->limiter->hit('email-verification-resend',(string)$id,3,3600))return Response::text('Limite de reenvios atingido.',429);
        try{$this->verification->request($id);return $this->page('','Se o e-mail ainda não foi confirmado, uma nova mensagem foi colocada na fila.');}catch(\Throwable){return $this->page('','Não foi possível solicitar a confirmação agora.');}
    }
    private function page(string $token,?string $message):Response {return $this->view->render('communications.verify-email',['title'=>'Confirmar e-mail','token'=>preg_match('/^[a-f0-9]{64}$/D',$token)?$token:'','message'=>$message])->withHeader('Referrer-Policy','no-referrer')->withHeader('Cache-Control','no-store');}
}
