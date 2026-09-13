<?php
declare(strict_types=1);
namespace App\Billing;

use DomainException;

/** Core v5 hosted subscriptions. Endpoint token triggers authoritative authenticated reads. */
final class PagarmeGateway implements GatewayAdapter
{
    public function __construct(private HttpTransport $http,private string $secret,private string $environment,private BillingRepository $repository) {}
    private function call(string $method,string $path,array $body=[]): array
    {
        $base=$this->environment==='sandbox'?'https://sdx-api.pagar.me/core/v5/':'https://api.pagar.me/core/v5/';
        return $this->http->request($method,$base.$path,['Authorization'=>'Basic '.base64_encode($this->secret.':'),'Content-Type'=>'application/json'],$body);
    }
    public function create(array $attempt,array $quote): array
    {
        // v5 idempotency documentation covers orders, not plans/paymentlinks. The service
        // marks uncertain creation ambiguous and never repeats these POSTs automatically.
        $plan=$this->call('POST','plans',['name'=>mb_substr($quote['plan_name'],0,64),'currency'=>'BRL','interval'=>'month','interval_count'=>1,'billing_type'=>'prepaid','payment_methods'=>['credit_card'],'installments'=>[1],'metadata'=>['attempt_id'=>$attempt['id']],'items'=>[['name'=>mb_substr($quote['plan_name'],0,64),'quantity'=>1,'pricing_scheme'=>['price'=>$quote['amount_cents']]]]]);
        StripeGateway::id($plan['id']??'','plan_');
        $this->repository->execute('UPDATE billing_checkout_attempts SET provider_plan_id=? WHERE id=?',[$plan['id'],$attempt['id']]);
        $link=$this->call('POST','paymentlinks',['name'=>'CF '.$attempt['id'],'order_code'=>$attempt['id'],'type'=>'subscription','max_sessions'=>1,'max_paid_sessions'=>1,'expires_in'=>60,'payment_settings'=>['accepted_payment_methods'=>['credit_card'],'credit_card_settings'=>['operation_type'=>'auth_and_capture']],'cart_settings'=>['recurrences'=>[['plan_id'=>$plan['id'],'start_in'=>1]]]]);
        StripeGateway::id($link['id']??'','pl_');
        return ['provider_checkout_id'=>$link['id'],'checkout_url'=>HostedCheckoutUrl::validate((string)($link['url']??''),'pagarme')];
    }
    public function confirm(array $attempt,array $quote,string $objectId,string $eventType): array
    {
        if(str_starts_with($objectId,'in_')){
            StripeGateway::id($objectId,'in_');$invoice=$this->call('GET','invoices/'.$objectId);
            if(($invoice['id']??null)!==$objectId)throw new DomainException('Identificador de fatura divergente.');
            $subId=$invoice['subscription']['id']??'';
        }else{StripeGateway::id($objectId,'sub_');$subId=$objectId;}
        StripeGateway::id($subId,'sub_');$sub=$this->call('GET','subscriptions/'.$subId);
        if(($sub['id']??null)!==$subId || empty($attempt['provider_plan_id']) || ($sub['plan']['id']??null)!==$attempt['provider_plan_id'])throw new DomainException('Assinatura não corresponde ao plano exclusivo.');
        $plan=$this->call('GET','plans/'.$attempt['provider_plan_id']);
        if(($plan['id']??null)!==$attempt['provider_plan_id'] || ($plan['metadata']['attempt_id']??null)!==$attempt['id'])throw new DomainException('Plano não corresponde à tentativa.');
        $items=$sub['items']??[];
        if(count($items)!==1 || (int)($items[0]['quantity']??0)!==1 || ($items[0]['pricing_scheme']['price']??null)!==$quote['amount_cents'] || ($sub['currency']??null)!=='BRL' || ($sub['interval']??null)!=='month' || (int)($sub['interval_count']??0)!==1)throw new DomainException('Preço ou recorrência divergente.');
        if(!isset($invoice)){
            // Subscription notifications may precede an invoice. Keep retryable until one
            // can be independently selected by a verified subscription filter.
            $list=$this->call('GET','invoices?subscription_id='.$subId.'&size=100');$candidates=$list['data']??[];
            usort($candidates,static fn($a,$b)=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')));
            if(!$candidates)throw new \RuntimeException('Fatura ainda indisponível.');
            $id=$candidates[0]['id']??'';StripeGateway::id($id,'in_');$invoice=$this->call('GET','invoices/'.$id);
        }
        if(($invoice['subscription']['id']??null)!==$subId || ($invoice['customer']['id']??null)!==($sub['customer']['id']??null) || ($invoice['amount']??null)!==$quote['amount_cents'])throw new DomainException('Fatura divergente.');
        StripeGateway::id($invoice['id']??'','in_');StripeGateway::id($sub['customer']['id']??'','cus_');
        $status=$sub['status']??'future';$payment=$invoice['status']??'pending';
        // Bind entitlement to the invoiced period, not a newer unpaid cycle.
        $start=strtotime((string)($invoice['cycle']['start_at']??''))?:0;$end=strtotime((string)($invoice['cycle']['end_at']??''))?:0;
        return ['subscription_id'=>$subId,'customer_id'=>$sub['customer']['id'],'subscription_status'=>$status==='active'?'active':($status==='canceled'?'canceled':'pending'),'invoice_id'=>$invoice['id'],'payment_status'=>in_array($payment,['paid','failed','canceled'],true)?$payment:'pending','amount_cents'=>$quote['amount_cents'],'currency'=>'BRL','period_start'=>$start,'period_end'=>$end,'paid_at'=>strtotime((string)($invoice['charge']['paid_at']??''))?:0,'cancel_at_period_end'=>false];
    }
}
