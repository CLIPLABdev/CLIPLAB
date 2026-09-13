<?php
declare(strict_types=1);
namespace App\Billing;

use App\Contracts\CommunicationEmitter;
use DomainException;
use Throwable;

final class BillingWebhookProcessor
{
    public function __construct(private BillingRepository $repository,private GatewaySettingsService $settings,private HttpTransport $http,private ?CommunicationEmitter $emitter=null) {}
    /** Authentication must be passed as Stripe-Signature, or Pagar.me's secret endpoint token. */
    public function process(string $provider,string $environment,string $rawBody,string $authentication,?string $expectedAttemptId=null): array
    {
        GatewaySettingsService::validate($provider,$environment);
        if(strlen($rawBody)>262144 || $rawBody==='')throw new DomainException('Corpo do webhook inválido.');
        $credentials=$this->settings->credentials($provider,$environment,false);
        $valid=$provider==='stripe'?(new StripeWebhookVerifier($credentials['webhook_secret']))->verify($rawBody,$authentication):hash_equals($credentials['webhook_secret'],$authentication);
        if(!$valid)throw new DomainException('Webhook não autenticado.');
        try {$event=json_decode($rawBody,true,32,JSON_THROW_ON_ERROR);}catch(Throwable $e){throw new DomainException('JSON inválido.');}
        $eventId=$event['id']??'';$type=$event['type']??'';StripeGateway::id($eventId,$provider==='stripe'?'evt_':'');
        $objectId=$provider==='stripe'?($event['data']['object']['id']??''):($event['data']['id']??'');StripeGateway::id($objectId,'');
        if($provider==='stripe' && ($event['livemode']??null)!==($environment==='production'))throw new DomainException('Ambiente divergente.');
        $allowed=$provider==='stripe'?['checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.async_payment_failed','invoice.paid','invoice.payment_failed','customer.subscription.updated','customer.subscription.deleted']:['invoice.paid','invoice.payment_failed','invoice.canceled','subscription.created','subscription.updated','subscription.canceled'];
        $refundEvent=in_array($type,$provider==='stripe'?['charge.refunded','refund.created','refund.updated']:['charge.refunded'],true);
        if(!in_array($type,$allowed,true) && !$refundEvent)return ['status'=>'ignored'];
        $existing=$this->repository->one('SELECT * FROM billing_webhook_events WHERE provider=? AND environment=? AND provider_event_id=?',[$provider,$environment,$eventId]);
        $hash=hash('sha256',$rawBody);
        if($existing && !hash_equals($existing['payload_sha256'],$hash))throw new DomainException('Identificador de evento reutilizado.');
        if($existing && in_array($existing['status'],['processed','rejected'],true))return ['status'=>'duplicate'];
        if(!$existing) {
            // At most 120 authenticated new events/minute/provider; endpoint middleware may add IP limits.
            $count=$this->repository->one('SELECT COUNT(*) AS n FROM billing_webhook_events WHERE provider=? AND environment=? AND received_at>=?',[$provider,$environment,gmdate('Y-m-d H:i:s',time()-60)]);
            if((int)$count['n']>=120)throw new DomainException('Limite de webhooks atingido.');
            try {$this->repository->execute("INSERT INTO billing_webhook_events(provider,environment,provider_event_id,payload_sha256,status,received_at) VALUES(?,?,?,?,'received',?)",[$provider,$environment,$eventId,$hash,gmdate('Y-m-d H:i:s')]);}catch(\PDOException $e) { return ['status'=>'retry']; }
        }
        try {
            if($refundEvent)return (new BillingRefundService($this->repository,$this->http))->confirm($provider,$environment,$objectId,$credentials['secret'],$eventId);
            $attempt=$this->resolveAttempt($provider,$environment,$objectId,$credentials['secret']);
            if(!$attempt)throw new \RuntimeException('Tentativa ainda não vinculada.');
            if($expectedAttemptId!==null && $attempt['id']!==$expectedAttemptId)throw new DomainException('Objeto não corresponde à tentativa em reconciliação.');
            $quote=json_decode($attempt['quote_json'],true,512,JSON_THROW_ON_ERROR);
            $observed=(int)floor(microtime(true)*1000000);
            $adapter=$provider==='stripe'?new StripeGateway($this->http,$credentials['secret'],$environment):new PagarmeGateway($this->http,$credentials['secret'],$environment,$this->repository);
            $state=$adapter->confirm($attempt,$quote,$objectId,$type);
            return $this->repository->transaction(function()use($attempt,$quote,$state,$provider,$environment,$eventId,$hash,$observed){
                $this->repository->lockUser((int)$attempt['user_id']);
                $event=$this->repository->one('SELECT status,payload_sha256 FROM billing_webhook_events WHERE provider=? AND environment=? AND provider_event_id=?',[$provider,$environment,$eventId]);
                if($event['status']==='processed')return ['status'=>'duplicate'];
                if(!hash_equals($event['payload_sha256'],$hash))throw new DomainException('Evento divergente.');
                $subscription=$this->repository->one('SELECT * FROM billing_subscriptions WHERE provider=? AND environment=? AND provider_subscription_id=?',[$provider,$environment,$state['subscription_id']]);
                if($subscription && ((int)$subscription['user_id']!==(int)$attempt['user_id'] || $subscription['checkout_attempt_id']!==$attempt['id']))throw new DomainException('Assinatura já vinculada.');
                // The subscription watermark must not discard an independently confirmed invoice.
                $staleSubscription=$subscription && (int)$subscription['last_confirmed_epoch']>$observed;
                if($staleSubscription)$state['subscription_status']=$subscription['status'];
                if(!$subscription){
                    $this->repository->execute('INSERT INTO billing_subscriptions(user_id,plan_id,provider,environment,provider_subscription_id,status,currency,amount_cents,plan_snapshot,provider_customer_id,checkout_attempt_id) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$attempt['user_id'],$attempt['plan_id'],$provider,$environment,$state['subscription_id'],$state['subscription_status'],'BRL',$quote['amount_cents'],$attempt['quote_json'],$state['customer_id'],$attempt['id']]);
                    $subscription=['id'=>$this->repository->insertId()];
                }
                if(!$staleSubscription)$this->repository->execute('UPDATE billing_subscriptions SET status=?,current_period_starts_at=?,current_period_ends_at=?,cancel_at_period_end=?,last_confirmed_epoch=? WHERE id=?',[$state['subscription_status'],$this->date($state['subscription_period_start']??$state['period_start']),$this->date($state['subscription_period_end']??$state['period_end']),$state['cancel_at_period_end']?1:0,$observed,$subscription['id']]);
                $payment=$this->repository->one('SELECT * FROM billing_payments WHERE provider=? AND environment=? AND provider_payment_id=?',[$provider,$environment,$state['invoice_id']]);
                if($payment && ((int)$payment['user_id']!==(int)$attempt['user_id'] || (int)$payment['subscription_id']!==(int)$subscription['id']))throw new DomainException('Pagamento já vinculado.');
                $fullyRefunded=$payment && $payment['status']==='refunded';
                $newlyPaid=$state['payment_status']==='paid' && !$fullyRefunded && (!$payment || $payment['status']!=='paid');
                if(!$payment){
                    $this->repository->execute('INSERT INTO billing_payments(user_id,subscription_id,plan_id,provider,environment,provider_payment_id,provider_invoice_id,status,currency,gross_amount_cents,discount_cents,paid_amount_cents,paid_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[$attempt['user_id'],$subscription['id'],$attempt['plan_id'],$provider,$environment,$state['invoice_id'],$state['invoice_id'],$state['payment_status'],'BRL',$quote['gross_amount_cents']??$quote['amount_cents'],$quote['discount_cents']??0,$state['payment_status']==='paid'?$quote['amount_cents']:0,$this->date($state['paid_at'])]);
                }elseif(!in_array($payment['status'],['paid','refunded'],true)){
                    $this->repository->execute('UPDATE billing_payments SET status=?,paid_amount_cents=?,paid_at=?,period_ends_at=? WHERE id=?',[$state['payment_status'],$state['payment_status']==='paid'?$quote['amount_cents']:0,$this->date($state['paid_at']),$this->date($state['period_end']),$payment['id']]);
                }
                if(!$payment)$this->repository->execute('UPDATE billing_payments SET period_ends_at=? WHERE provider=? AND environment=? AND provider_payment_id=?',[$this->date($state['period_end']),$provider,$environment,$state['invoice_id']]);
                if($state['payment_status']==='paid' && !$fullyRefunded && !empty($quote['coupon_id'])){
                    $savedPayment=$this->repository->one('SELECT id FROM billing_payments WHERE provider=? AND environment=? AND provider_payment_id=?',[$provider,$environment,$state['invoice_id']]);
                    (new CouponService($this->repository))->apply($attempt['id'],(int)$savedPayment['id']);
                }
                if(!$fullyRefunded && $state['payment_status']==='paid' && $state['subscription_status']==='active' && $state['period_start']>0 && $state['period_end']>time()){
                    // A sandbox object is recorded, but never changes production entitlements.
                    if($environment==='production')(new BillingEntitlementService($this->repository))->grant((int)$attempt['user_id'],(int)$subscription['id'],(int)$attempt['plan_id'],$state['period_end']);
                    $this->repository->execute("UPDATE billing_checkout_attempts SET status='confirmed' WHERE id=?",[$attempt['id']]);
                }
                if($environment==='production' && in_array($state['subscription_status'],['canceled','unpaid'],true)){
                    (new BillingEntitlementService($this->repository))->revoke((int)$attempt['user_id'],(int)$subscription['id']);
                }
                if($state['subscription_status']==='canceled')(new CouponService($this->repository))->release($attempt['id']);
                if($newlyPaid && $this->emitter)$this->emitter->emit((int)$attempt['user_id'],'billing.payment_approved',[
                    'nome_usuario'=>'','nome_plano'=>$quote['plan_name'],'valor'=>number_format($quote['amount_cents']/100,2,',','.'),'moeda'=>'BRL',
                    'proxima_cobranca'=>$this->date($state['period_end'])??'','link_assinatura'=>$quote['return_base'].'/conta/pagamentos','link_pagamento'=>'','motivo'=>''
                ],'billing:'.$provider.':'.$environment.':'.hash('sha256',$state['invoice_id']).':paid');
                $this->eventStatus($provider,$environment,$eventId,'processed');return ['status'=>'processed'];
            });
        }catch(DomainException $e){$this->eventStatus($provider,$environment,$eventId,'rejected','confirmation_mismatch');return ['status'=>'rejected'];}
        catch(Throwable $e){$this->eventStatus($provider,$environment,$eventId,'retry','provider_or_storage_unavailable');return ['status'=>'retry'];}
    }
    public function reconcileReference(int $userId,string $attemptId,string $objectId): array
    {
        $attempt=$this->repository->attemptForUser($attemptId,$userId);if(!$attempt)throw new DomainException('Tentativa não encontrada.');
        $provider=$attempt['provider'];$environment=$attempt['environment'];$credentials=$this->settings->credentials($provider,$environment,false);
        $type=$provider==='stripe'?(str_starts_with($objectId,'cs_')?'checkout.session.completed':'customer.subscription.updated'):'subscription.updated';
        $event=['id'=>($provider==='stripe'?'evt_':'hook_').'reconcile_'.bin2hex(random_bytes(16)),'type'=>$type,'livemode'=>$environment==='production','data'=>$provider==='stripe'?['object'=>['id'=>$objectId]]:['id'=>$objectId]];
        $raw=json_encode($event,JSON_THROW_ON_ERROR);$now=time();$authentication=$provider==='stripe'?'t='.$now.',v1='.hash_hmac('sha256',$now.'.'.$raw,$credentials['webhook_secret']):$credentials['webhook_secret'];
        return $this->process($provider,$environment,$raw,$authentication,$attemptId);
    }
    private function resolveAttempt(string $provider,string $environment,string $objectId,string $secret): ?array
    {
        $attempt=$this->repository->one('SELECT * FROM billing_checkout_attempts WHERE provider=? AND environment=? AND provider_checkout_id=?',[$provider,$environment,$objectId]);if($attempt)return $attempt;
        $subscription=$this->repository->one('SELECT checkout_attempt_id FROM billing_subscriptions WHERE provider=? AND environment=? AND provider_subscription_id=?',[$provider,$environment,$objectId]);if($subscription)return $this->repository->attempt($subscription['checkout_attempt_id']);
        // Read-only lookup recovers invoice-first callbacks, but never trusts their metadata directly.
        if($provider==='stripe'){
            $resource=str_starts_with($objectId,'in_')?'invoices/':(str_starts_with($objectId,'sub_')?'subscriptions/':'');if($resource==='')return null;
            $headers=['Authorization'=>'Bearer '.$secret,'Stripe-Version'=>'2025-06-30.basil'];
            $object=$this->http->request('GET','https://api.stripe.com/v1/'.$resource.$objectId,$headers);
            if($resource==='invoices/'){$id=$object['parent']['subscription_details']['subscription']??'';StripeGateway::id($id,'sub_');$object=$this->http->request('GET','https://api.stripe.com/v1/subscriptions/'.$id,$headers);}
            $attemptId=$object['metadata']['attempt_id']??'';$attempt=is_string($attemptId)?$this->repository->attempt($attemptId):null;
        }else{
            $base=$environment==='sandbox'?'https://sdx-api.pagar.me/core/v5/':'https://api.pagar.me/core/v5/';$headers=['Authorization'=>'Basic '.base64_encode($secret.':')];
            $resource=str_starts_with($objectId,'in_')?'invoices/':(str_starts_with($objectId,'sub_')?'subscriptions/':'');if($resource==='')return null;
            $object=$this->http->request('GET',$base.$resource.$objectId,$headers);
            if($resource==='invoices/'){$id=$object['subscription']['id']??'';StripeGateway::id($id,'sub_');$object=$this->http->request('GET',$base.'subscriptions/'.$id,$headers);}
            $attempt=$this->repository->one('SELECT * FROM billing_checkout_attempts WHERE provider=? AND environment=? AND provider_plan_id=?',[$provider,$environment,$object['plan']['id']??'']);
        }
        return $attempt && $attempt['provider']===$provider && $attempt['environment']===$environment?$attempt:null;
    }
    private function date(int $epoch): ?string {return $epoch>0?gmdate('Y-m-d H:i:s',$epoch):null;}
    private function eventStatus(string $provider,string $environment,string $id,string $status,?string $failure=null): void
    {
        $this->repository->execute('UPDATE billing_webhook_events SET status=?,failure_code=?,processed_at=? WHERE provider=? AND environment=? AND provider_event_id=?',[$status,$failure,$status==='processed'?gmdate('Y-m-d H:i:s'):null,$provider,$environment,$id]);
    }
}
