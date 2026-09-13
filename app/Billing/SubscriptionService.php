<?php
declare(strict_types=1);
namespace App\Billing;

use App\Contracts\CommunicationEmitter;
use DomainException;
use RuntimeException;

final class SubscriptionService
{
    public function __construct(private BillingRepository $repository,private GatewaySettingsService $settings,private HttpTransport $http,private ?CommunicationEmitter $emitter=null) {}
    /** Pagar.me supports immediate cancellation; Stripe also supports period-end cancellation. */
    public function cancel(int $userId,int $subscriptionId,bool $atPeriodEnd=true): array
    {
        $subscription=$this->owned($userId,$subscriptionId);
        if($subscription['provider']==='pagarme' && $atPeriodEnd)throw new DomainException('O Pagar.me suporta cancelamento imediato. Confirme essa modalidade explicitamente.');
        $credentials=$this->settings->credentials($subscription['provider'],$subscription['environment'],false);
        $remote=$this->read($subscription,$credentials['secret']);
        $already=($remote['status']??'')==='canceled' || ($atPeriodEnd && !empty($remote['cancel_at_period_end']));
        if(!$already){
            $method=$atPeriodEnd?'POST':'DELETE';$body=$subscription['provider']==='stripe'?($atPeriodEnd?['cancel_at_period_end'=>'true']:['invoice_now'=>'false','prorate'=>'false']):['cancel_pending_invoices'=>false];
            $headers=$this->headers($subscription,$credentials['secret']);if($method==='POST')$headers['Idempotency-Key']='cancel-end-'.$subscription['provider_subscription_id'];
            $this->http->request($method,$this->url($subscription),$headers,$body);
            $remote=$this->read($subscription,$credentials['secret']);
        }
        if(($remote['status']??'')!=='canceled' && !($atPeriodEnd && !empty($remote['cancel_at_period_end'])))throw new RuntimeException('O provedor ainda não confirmou o cancelamento.');
        return $this->persist($subscription,$remote);
    }
    /** Existing subscriptions can be refreshed after a lost cancellation response. */
    public function refresh(int $userId,int $subscriptionId): array
    {
        $subscription=$this->owned($userId,$subscriptionId);$credentials=$this->settings->credentials($subscription['provider'],$subscription['environment'],false);
        return $this->persist($subscription,$this->read($subscription,$credentials['secret']));
    }
    private function owned(int $userId,int $subscriptionId): array
    {
        $row=$this->repository->one('SELECT * FROM billing_subscriptions WHERE id=? AND user_id=?',[$subscriptionId,$userId]);if(!$row)throw new DomainException('Assinatura não encontrada.');return $row;
    }
    private function headers(array $subscription,string $secret): array
    {
        return $subscription['provider']==='stripe'?['Authorization'=>'Bearer '.$secret,'Stripe-Version'=>'2025-06-30.basil','Content-Type'=>'application/x-www-form-urlencoded']:['Authorization'=>'Basic '.base64_encode($secret.':'),'Content-Type'=>'application/json'];
    }
    private function url(array $subscription): string
    {
        StripeGateway::id($subscription['provider_subscription_id'],'sub_');GatewaySettingsService::validate($subscription['provider'],$subscription['environment']);
        $base=$subscription['provider']==='stripe'?'https://api.stripe.com/v1/':($subscription['environment']==='sandbox'?'https://sdx-api.pagar.me/core/v5/':'https://api.pagar.me/core/v5/');return $base.'subscriptions/'.$subscription['provider_subscription_id'];
    }
    private function read(array $subscription,string $secret): array
    {
        $observed=(int)floor(microtime(true)*1000000);
        $result=$this->http->request('GET',$this->url($subscription),$this->headers($subscription,$secret));
        if(($result['id']??null)!==$subscription['provider_subscription_id'])throw new DomainException('Assinatura remota divergente.');
        if($subscription['provider']==='stripe'){
            if(($result['livemode']??null)!==($subscription['environment']==='production') || ($result['customer']??null)!==$subscription['provider_customer_id'] || ($result['metadata']['attempt_id']??null)!==$subscription['checkout_attempt_id'])throw new DomainException('Vínculo da assinatura divergente.');
        }else{
            $attempt=$this->repository->attempt($subscription['checkout_attempt_id']);
            if(!$attempt || ($result['plan']['id']??null)!==$attempt['provider_plan_id'] || ($result['customer']['id']??null)!==$subscription['provider_customer_id'])throw new DomainException('Vínculo da assinatura divergente.');
        }
        $result['_confirmed_observed_at']=$observed;return $result;
    }
    private function persist(array $subscription,array $remote): array
    {
        return $this->repository->transaction(function()use($subscription,$remote){
            $this->repository->lockUser((int)$subscription['user_id']);$fresh=$this->owned((int)$subscription['user_id'],(int)$subscription['id']);
            if((int)$fresh['last_confirmed_epoch']>(int)$remote['_confirmed_observed_at'])return $fresh;
            $status=$remote['status']??'pending';if(!in_array($status,['active','trialing','past_due','canceled','unpaid','incomplete'],true))$status='pending';
            $cancel=$subscription['provider']==='stripe' && !empty($remote['cancel_at_period_end']);$canceled=$remote['canceled_at']??null;
            $canceledAt=is_int($canceled)?gmdate('Y-m-d H:i:s',$canceled):($canceled?gmdate('Y-m-d H:i:s',strtotime((string)$canceled)):null);
            $this->repository->execute('UPDATE billing_subscriptions SET status=?,cancel_at_period_end=?,canceled_at=?,last_confirmed_epoch=? WHERE id=?',[$status,$cancel?1:0,$canceledAt,$remote['_confirmed_observed_at'],$subscription['id']]);
            if($subscription['environment']==='production' && in_array($status,['canceled','unpaid'],true))(new BillingEntitlementService($this->repository))->revoke((int)$subscription['user_id'],(int)$subscription['id']);
            if($status==='canceled')(new CouponService($this->repository))->release($subscription['checkout_attempt_id']);
            return $this->owned((int)$subscription['user_id'],(int)$subscription['id']);
        });
    }
}
