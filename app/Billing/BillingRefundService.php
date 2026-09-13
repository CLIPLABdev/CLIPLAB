<?php
declare(strict_types=1);
namespace App\Billing;

use DomainException;
use RuntimeException;

/** Records provider-confirmed refunds. This service deliberately has no refund/create API. */
final class BillingRefundService
{
    public function __construct(private BillingRepository $repository,private HttpTransport $http) {}
    public function confirm(string $provider,string $environment,string $objectId,string $secret,string $eventId): array
    {
        $base=$provider==='stripe'?'https://api.stripe.com/v1/':($environment==='sandbox'?'https://sdx-api.pagar.me/core/v5/':'https://api.pagar.me/core/v5/');
        $headers=$provider==='stripe'?['Authorization'=>'Bearer '.$secret,'Stripe-Version'=>'2025-06-30.basil']:['Authorization'=>'Basic '.base64_encode($secret.':')];
        if($provider==='stripe' && str_starts_with($objectId,'re_')){StripeGateway::id($objectId,'re_');$refund=$this->http->request('GET',$base.'refunds/'.$objectId,$headers);if(($refund['id']??null)!==$objectId)throw new DomainException('Estorno divergente.');$objectId=$refund['charge']??'';}
        StripeGateway::id($objectId,'ch_');$charge=$this->http->request('GET',$base.'charges/'.$objectId,$headers);
        if(($charge['id']??null)!==$objectId || strtoupper((string)($charge['currency']??''))!=='BRL')throw new DomainException('Cobrança de estorno divergente.');
        if($provider==='stripe'){
            if(($charge['livemode']??null)!==($environment==='production') || ($charge['paid']??null)!==true)throw new DomainException('Cobrança não paga neste ambiente.');
            $intent=$charge['payment_intent']??'';StripeGateway::id($intent,'pi_');
            $links=$this->http->request('GET',$base.'invoice_payments?payment%5Btype%5D=payment_intent&payment%5Bpayment_intent%5D='.$intent.'&limit=100',$headers);
            if(!empty($links['has_more']) || count($links['data']??[])!==1)throw new DomainException('Pagamento distribuído exige revisão.');$link=$links['data'][0];
            if(($link['livemode']??null)!==($environment==='production') || ($link['status']??null)!=='paid' || ($link['payment']['payment_intent']??null)!==$intent || strtoupper((string)($link['currency']??''))!=='BRL')throw new DomainException('Fatura não corresponde à cobrança.');
            $invoiceId=$link['invoice']??'';$customer=$charge['customer']??'';$paid=$charge['amount_captured']??null;$amount=0;$after='';
            for($page=0;$page<5;$page++){
                $refunds=$this->http->request('GET',$base.'refunds?charge='.$objectId.'&limit=100'.($after!==''?'&starting_after='.$after:''),$headers);
                foreach($refunds['data']??[] as $refund){
                    if(($refund['charge']??null)!==$objectId || strtoupper((string)($refund['currency']??''))!=='BRL' || !is_int($refund['amount']??null))throw new DomainException('Estorno não corresponde à cobrança.');
                    if(($refund['status']??null)==='succeeded')$amount+=$refund['amount'];
                }
                if(empty($refunds['has_more']))break;
                if($page===4 || empty($refunds['data']))throw new RuntimeException('Histórico de estornos requer reconciliação ampliada.');
                $last=end($refunds['data']);$after=$last['id']??'';StripeGateway::id($after,'re_');
            }
            if(($link['amount_paid']??null)!==$paid)throw new DomainException('Valor distribuído exige revisão.');
        }else{
            $invoiceId=$charge['invoice']['id']??'';$customer=$charge['customer']['id']??'';$paid=$charge['paid_amount']??null;
            $transaction=$charge['last_transaction']??[];
            $amount=($transaction['success']??null)===true && in_array($transaction['status']??null,['refunded','partial_refunded'],true)?($charge['canceled_amount']??null):0;
        }
        StripeGateway::id($invoiceId,'in_');StripeGateway::id($customer,'cus_');
        if(!is_int($paid) || !is_int($amount) || $paid<1 || $amount<0 || $amount>$paid)throw new DomainException('Valor de estorno inválido.');
        $payment=$this->repository->one('SELECT p.*,s.provider_customer_id FROM billing_payments p JOIN billing_subscriptions s ON s.id=p.subscription_id WHERE p.provider=? AND p.environment=? AND p.provider_invoice_id=?',[$provider,$environment,$invoiceId]);
        if(!$payment)throw new RuntimeException('Pagamento original ainda não confirmado localmente.');
        if($payment['provider_customer_id']!==$customer || (int)$payment['paid_amount_cents']!==$paid || !in_array($payment['status'],['paid','refunded'],true))throw new DomainException('Pagamento original divergente.');
        return $this->repository->transaction(function()use($provider,$environment,$objectId,$eventId,$payment,$amount){
            $this->repository->lockUser((int)$payment['user_id']);
            $event=$this->repository->one('SELECT status FROM billing_webhook_events WHERE provider=? AND environment=? AND provider_event_id=?',[$provider,$environment,$eventId]);
            if(($event['status']??'')==='processed')return ['status'=>'duplicate'];
            $existing=$this->repository->one('SELECT * FROM billing_refunds WHERE provider=? AND environment=? AND provider_charge_id=?',[$provider,$environment,$objectId]);
            if($existing && (int)$existing['payment_id']!==(int)$payment['id'])throw new DomainException('Estorno já vinculado a outro pagamento.');
            $totalForCharge=max($amount,(int)($existing['amount_cents']??0));
            if($totalForCharge>0){
                if($existing)$this->repository->execute('UPDATE billing_refunds SET amount_cents=?,confirmed_at=? WHERE id=?',[$totalForCharge,gmdate('Y-m-d H:i:s'),$existing['id']]);
                else $this->repository->execute('INSERT INTO billing_refunds(provider,environment,provider_charge_id,payment_id,amount_cents,confirmed_at) VALUES(?,?,?,?,?,?)',[$provider,$environment,$objectId,$payment['id'],$totalForCharge,gmdate('Y-m-d H:i:s')]);
                $sum=$this->repository->one('SELECT SUM(amount_cents) AS n FROM billing_refunds WHERE payment_id=?',[$payment['id']]);$refunded=(int)$sum['n'];
                if($refunded>(int)$payment['paid_amount_cents'])throw new DomainException('Total de estornos excede o pagamento.');
                $full=$refunded===(int)$payment['paid_amount_cents'];
                $this->repository->execute('UPDATE billing_payments SET refunded_amount_cents=?,status=? WHERE id=?',[$refunded,$full?'refunded':'paid',$payment['id']]);
                if($full && $environment==='production'){
                    $source=$this->repository->one('SELECT * FROM billing_entitlements WHERE user_id=? AND subscription_id=?',[$payment['user_id'],$payment['subscription_id']]);
                    if($source && !empty($payment['period_ends_at']) && $source['valid_until']<=$payment['period_ends_at'])(new BillingEntitlementService($this->repository))->revoke((int)$payment['user_id'],(int)$payment['subscription_id']);
                }
            }
            $this->repository->execute("UPDATE billing_webhook_events SET status='processed',processed_at=?,failure_code=NULL WHERE provider=? AND environment=? AND provider_event_id=?",[gmdate('Y-m-d H:i:s'),$provider,$environment,$eventId]);return ['status'=>'processed'];
        });
    }
}
