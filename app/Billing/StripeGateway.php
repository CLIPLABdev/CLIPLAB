<?php
declare(strict_types=1);
namespace App\Billing;

use DomainException;

final class StripeGateway implements GatewayAdapter
{
    public function __construct(private HttpTransport $http,private string $secret,private string $environment,private string $returnBase='') {}
    private function call(string $method,string $path,array $body=[],?string $key=null): array
    {
        $headers=['Authorization'=>'Bearer '.$this->secret,'Stripe-Version'=>'2025-06-30.basil','Content-Type'=>'application/x-www-form-urlencoded'];
        if($key!==null)$headers['Idempotency-Key']=$key;
        return $this->http->request($method,'https://api.stripe.com/v1/'.$path,$headers,$body);
    }
    public function create(array $attempt,array $quote): array
    {
        // Both browser outcomes lead to the same read-only, owner-scoped status page.
        // Only provider confirmation can change billing state or grant a plan.
        $returnUrl=$quote['return_base'].'/checkout/retorno/'.rawurlencode((string)$attempt['id']);
        $result=$this->call('POST','checkout/sessions',[
            'mode'=>'subscription','client_reference_id'=>$attempt['id'],'customer_email'=>$quote['email'],
            'success_url'=>$returnUrl,
            'cancel_url'=>$returnUrl,
            'metadata'=>['attempt_id'=>$attempt['id']], 'subscription_data'=>['metadata'=>['attempt_id'=>$attempt['id']]],
            'line_items'=>[['quantity'=>1,'price_data'=>['currency'=>'brl','unit_amount'=>$quote['amount_cents'],'product_data'=>['name'=>$quote['plan_name']],'recurring'=>['interval'=>'month','interval_count'=>1]]]],
        ],'checkout-'.$attempt['id']);
        $this->mode($result); self::id($result['id']??'','cs_');
        return ['provider_checkout_id'=>$result['id'],'checkout_url'=>HostedCheckoutUrl::validate((string)($result['url']??''),'stripe')];
    }
    public function confirm(array $attempt,array $quote,string $objectId,string $eventType): array
    {
        if(str_starts_with($objectId,'cs_')) {
            self::id($objectId,'cs_');$session=$this->call('GET','checkout/sessions/'.$objectId);$this->mode($session);
            if(($session['id']??null)!==$attempt['provider_checkout_id'] || ($session['client_reference_id']??null)!==$attempt['id'] || ($session['mode']??null)!=='subscription')throw new DomainException('Checkout não corresponde à tentativa.');
            $this->money($session['amount_total']??null,$session['currency']??null,$quote);
            $subId=$session['subscription']??'';
        } elseif(str_starts_with($objectId,'in_')) {
            self::id($objectId,'in_');$eventInvoice=$this->call('GET','invoices/'.$objectId);$this->mode($eventInvoice);
            $subId=$eventInvoice['parent']['subscription_details']['subscription']??'';
        } else {self::id($objectId,'sub_');$subId=$objectId;}
        self::id($subId,'sub_');$sub=$this->call('GET','subscriptions/'.$subId);$this->mode($sub);
        if(($sub['id']??null)!==$subId || ($sub['metadata']['attempt_id']??null)!==$attempt['id'])throw new DomainException('Assinatura não corresponde à tentativa.');
        $items=$sub['items']['data']??[];
        if(count($items)!==1 || ($items[0]['quantity']??null)!==1 || ($items[0]['price']['recurring']['interval']??null)!=='month' || ($items[0]['price']['recurring']['interval_count']??null)!==1)throw new DomainException('Recorrência divergente.');
        $this->money($items[0]['price']['unit_amount']??null,$items[0]['price']['currency']??null,$quote);
        // A delayed invoice event is about that invoice, not the subscription's newest bill.
        $invoiceId=isset($eventInvoice)?$objectId:($sub['latest_invoice']??'');self::id($invoiceId,'in_');
        $invoice=$eventInvoice??$this->call('GET','invoices/'.$invoiceId);$this->mode($invoice);
        if(($invoice['id']??null)!==$invoiceId || ($invoice['parent']['subscription_details']['subscription']??null)!==$subId || ($invoice['customer']??null)!==($sub['customer']??null))throw new DomainException('Fatura não corresponde à assinatura.');
        $this->money($invoice['total']??null,$invoice['currency']??null,$quote);
        $paid=($invoice['status']??null)==='paid'; if($paid)$this->money($invoice['amount_paid']??null,$invoice['currency']??null,$quote);
        $period=$this->servicePeriod($invoice,$subId,$quote);
        $status=$sub['status']??'incomplete'; if(!in_array($status,['active','trialing','past_due','canceled','unpaid','incomplete'],true))$status='incomplete';
        return ['subscription_id'=>$subId,'customer_id'=>$sub['customer'],'subscription_status'=>$status,'invoice_id'=>$invoiceId,
            'payment_status'=>$paid?'paid':(($invoice['status']??'')==='uncollectible'?'failed':'pending'),
            'amount_cents'=>$quote['amount_cents'],'currency'=>'BRL','period_start'=>$period['start'],
            'period_end'=>$period['end'],'paid_at'=>(int)($invoice['status_transitions']['paid_at']??0),
            'subscription_period_start'=>(int)($items[0]['current_period_start']??0),'subscription_period_end'=>(int)($items[0]['current_period_end']??0),
            'cancel_at_period_end'=>!empty($sub['cancel_at_period_end'])];
    }
    private function servicePeriod(array $invoice,string $subscriptionId,array $quote):array
    {
        // Invoice.period_end describes usage aggregation, not the paid service period.
        // Our hosted checkout creates exactly one non-prorated recurring item.
        $lines=$invoice['lines']['data']??[];
        if(($invoice['lines']['has_more']??true)!==false || count($lines)!==1)throw new DomainException('Itens da fatura divergentes.');
        $line=$lines[0];$parent=$line['parent']??[];$details=$parent['subscription_item_details']??[];
        if(($parent['type']??null)!=='subscription_item_details' || ($details['subscription']??null)!==$subscriptionId || ($details['proration']??null)!==false || ($line['quantity']??null)!==1)throw new DomainException('Item recorrente divergente.');
        $this->money($line['amount']??null,$line['currency']??null,$quote);
        $period=$line['period']??[];
        if(!is_int($period['start']??null) || !is_int($period['end']??null) || $period['start']<=0 || $period['end']<=$period['start'])throw new DomainException('Período de serviço inválido.');
        return $period;
    }
    private function mode(array $value): void
    {
        if(!array_key_exists('livemode',$value) || $value['livemode']!==($this->environment==='production'))throw new DomainException('Ambiente divergente.');
    }
    private function money($amount,$currency,array $quote): void
    {
        if(!is_int($amount) || $amount!==$quote['amount_cents'] || strtoupper((string)$currency)!=='BRL')throw new DomainException('Valor ou moeda divergente.');
    }
    public static function id($id,string $prefix): void
    {
        if(!is_string($id) || strlen($id)>191 || !str_starts_with($id,$prefix) || preg_match('/^[a-zA-Z0-9_]+$/D',$id)!==1)throw new DomainException('Identificador financeiro inválido.');
    }
}
