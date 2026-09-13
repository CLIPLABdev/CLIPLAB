<?php
declare(strict_types=1);
namespace App\Billing;

use App\Contracts\CommunicationEmitter;
use DomainException;

/** Explicit owner-scoped recovery. Never creates a checkout, invoice, charge or refund. */
final class BillingReconciliationService
{
    public function __construct(private BillingRepository $repository,private GatewaySettingsService $settings,private HttpTransport $http,private ?CommunicationEmitter $emitter=null) {}
    public function reconcile(int $userId,string $attemptId): array
    {
        $attempt=$this->repository->attemptForUser($attemptId,$userId);if(!$attempt)throw new DomainException('Checkout não encontrado.');
        if((int)$attempt['lease_until']>time())return ['status'=>'pending'];
        $credentials=$this->settings->credentials($attempt['provider'],$attempt['environment'],false);
        $processor=new BillingWebhookProcessor($this->repository,$this->settings,$this->http,$this->emitter);
        $subscription=$this->repository->one('SELECT provider_subscription_id FROM billing_subscriptions WHERE checkout_attempt_id=?',[$attemptId]);
        if($subscription)return $processor->reconcileReference($userId,$attemptId,$subscription['provider_subscription_id']);
        if($attempt['provider']==='stripe'){
            $headers=['Authorization'=>'Bearer '.$credentials['secret'],'Stripe-Version'=>'2025-06-30.basil'];$base='https://api.stripe.com/v1/';$id=$attempt['provider_checkout_id'];
            if(!$id){
                $after='';
                for($page=0;$page<5;$page++){
                    $url=$base.'checkout/sessions?limit=100&created%5Bgte%5D='.(int)$attempt['created_epoch'].($after!==''?'&starting_after='.$after:'');
                    $list=$this->http->request('GET',$url,$headers);$matches=[];
                    foreach($list['data']??[] as $session)if(($session['client_reference_id']??null)===$attemptId && ($session['metadata']['attempt_id']??null)===$attemptId)$matches[]=$session;
                    if(count($matches)>1)throw new DomainException('Múltiplos checkouts exigem revisão.');
                    if($matches){$id=$matches[0]['id'];break;}
                    if(empty($list['has_more']) || empty($list['data']))break;
                    $last=end($list['data']);$after=$last['id']??'';StripeGateway::id($after,'cs_');
                }
            }
            if(!$id)return ['status'=>'ambiguous'];StripeGateway::id($id,'cs_');
            $session=$this->http->request('GET',$base.'checkout/sessions/'.$id,$headers);$quote=json_decode($attempt['quote_json'],true,512,JSON_THROW_ON_ERROR);
            if(($session['id']??null)!==$id || ($session['client_reference_id']??null)!==$attemptId || ($session['metadata']['attempt_id']??null)!==$attemptId || ($session['livemode']??null)!==($attempt['environment']==='production') || ($session['mode']??null)!=='subscription' || ($session['amount_total']??null)!==$quote['amount_cents'] || strtolower((string)($session['currency']??''))!=='brl')throw new DomainException('Checkout remoto divergente.');
            $this->repository->execute('UPDATE billing_checkout_attempts SET provider_checkout_id=? WHERE id=?',[$id,$attemptId]);
            if(($session['status']??'')==='expired' && empty($session['subscription']))return $this->expire($attempt);
            if(($session['status']??'')==='complete')return $processor->reconcileReference($userId,$attemptId,$id);
            if(($session['status']??'')==='open'){
                $url=HostedCheckoutUrl::validate((string)($session['url']??''),'stripe');$this->repository->execute("UPDATE billing_checkout_attempts SET checkout_url=?,status='pending',failure_code=NULL WHERE id=? AND status<>'confirmed'",[$url,$attemptId]);return ['status'=>'pending'];
            }
            return ['status'=>'retry'];
        }
        $base=$attempt['environment']==='sandbox'?'https://sdx-api.pagar.me/core/v5/':'https://api.pagar.me/core/v5/';$headers=['Authorization'=>'Basic '.base64_encode($credentials['secret'].':')];$planId=$attempt['provider_plan_id'];
        if(!$planId){
            for($page=1;$page<=5;$page++){
                $list=$this->http->request('GET',$base.'plans?size=100&page='.$page,$headers);
                foreach($list['data']??[] as $plan)if(($plan['metadata']['attempt_id']??null)===$attemptId){if($planId)throw new DomainException('Múltiplos planos exigem revisão.');$planId=$plan['id']??null;}
                if($planId || count($list['data']??[])<100)break;
            }
        }
        if(!$planId)return ['status'=>'ambiguous'];StripeGateway::id($planId,'plan_');$plan=$this->http->request('GET',$base.'plans/'.$planId,$headers);
        if(($plan['id']??null)!==$planId || ($plan['metadata']['attempt_id']??null)!==$attemptId)throw new DomainException('Plano remoto divergente.');
        $this->repository->execute('UPDATE billing_checkout_attempts SET provider_plan_id=? WHERE id=?',[$planId,$attemptId]);
        $list=$this->http->request('GET',$base.'subscriptions?plan_id='.$planId.'&size=100',$headers);$matches=[];
        foreach($list['data']??[] as $sub)if(($sub['plan']['id']??null)===$planId)$matches[]=$sub;
        if(count($matches)>1)throw new DomainException('Múltiplas assinaturas exigem revisão.');
        if($matches)return $processor->reconcileReference($userId,$attemptId,$matches[0]['id']);
        // Absence from a bounded list is not proof that a financial object was never created.
        return ['status'=>$attempt['provider_checkout_id']?'pending':'ambiguous'];
    }
    private function expire(array $attempt): array
    {
        return $this->repository->transaction(function()use($attempt){
            $this->repository->lockUser((int)$attempt['user_id']);$fresh=$this->repository->attempt($attempt['id']);
            if($fresh['status']==='confirmed' || $this->repository->one('SELECT id FROM billing_subscriptions WHERE checkout_attempt_id=?',[$attempt['id']]))return ['status'=>'processed'];
            (new CouponService($this->repository))->release($attempt['id']);
            $this->repository->execute("UPDATE billing_checkout_attempts SET status='expired',checkout_url=NULL,lease_until=0 WHERE id=?",[$attempt['id']]);return ['status'=>'expired'];
        });
    }
}
