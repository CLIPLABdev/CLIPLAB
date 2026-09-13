<?php
declare(strict_types=1);
namespace App\Billing;

use DomainException;
use Throwable;

final class CheckoutService
{
    public function __construct(private BillingRepository $repository,private GatewaySettingsService $settings,private HttpTransport $http,private string $returnBase) {}
    public function start(int $userId,int $planId,string $provider,string $environment,string $requestKey,?string $couponCode=null): array
    {
        GatewaySettingsService::validate($provider,$environment);
        if(preg_match('/^[a-zA-Z0-9_-]{32,100}$/D',$requestKey)!==1)throw new DomainException('Chave de solicitação inválida.');
        $credentials=$this->settings->credentials($provider,$environment);
        $couponCode=CouponService::normalize($couponCode);
        $attempt=$this->repository->transaction(function()use($userId,$planId,$provider,$environment,$requestKey,$couponCode){
            $user=$this->repository->lockUser($userId);
            $existing=$this->repository->one('SELECT * FROM billing_checkout_attempts WHERE user_id=? AND request_key=?',[$userId,$requestKey]);
            if($existing){$savedQuote=json_decode($existing['quote_json'],true,512,JSON_THROW_ON_ERROR);if((int)$existing['plan_id']!==$planId || $existing['provider']!==$provider || $existing['environment']!==$environment || ($savedQuote['coupon_code']??null)!==$couponCode)throw new DomainException('Solicitação já usada com outros dados.');return $existing;}
            if($this->repository->one("SELECT id FROM billing_checkout_attempts WHERE user_id=? AND status IN ('creating','pending','retry','ambiguous')",[$userId]))throw new DomainException('Já existe checkout em andamento.');
            if($this->repository->one("SELECT id FROM billing_subscriptions WHERE user_id=? AND status IN ('active','trialing','past_due','unpaid')",[$userId]))throw new DomainException('Gerencie a assinatura existente antes de contratar outra.');
            $plan=$this->repository->one('SELECT id,name,price_cents FROM plans WHERE id=? AND is_active=1 AND price_cents>0',[$planId]);
            if(!$plan)throw new DomainException('Plano indisponível.');
            $base=rtrim($this->returnBase,'/');$parts=parse_url($base);
            if(!$parts || !in_array($parts['scheme']??'', ['https','http'],true) || empty($parts['host']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment']) || (($parts['scheme']??'')==='http' && !in_array($parts['host'],['localhost','127.0.0.1'],true)))throw new DomainException('URL de retorno inválida.');
            $quote=array_merge(PriceQuote::full((int)$plan['price_cents']),['plan_id'=>$planId,'plan_name'=>$plan['name'],'interval'=>'month','interval_count'=>1,'email'=>$user['email'],'return_base'=>$base]);
            $id=bin2hex(random_bytes(24));$this->repository->execute("INSERT INTO billing_checkout_attempts(id,user_id,plan_id,provider,environment,request_key,quote_json,status,created_epoch) VALUES(?,?,?,?,?,?,?,'creating',?)",[$id,$userId,$planId,$provider,$environment,$requestKey,json_encode($quote,JSON_THROW_ON_ERROR),time()]);
            if($couponCode!==null){$quote=array_merge($quote,(new CouponService($this->repository))->reserve($couponCode,$userId,$planId,(int)$plan['price_cents'],$id,$environment));$this->repository->execute('UPDATE billing_checkout_attempts SET quote_json=? WHERE id=?',[json_encode($quote,JSON_THROW_ON_ERROR),$id]);}
            return $this->repository->attempt($id);
        });
        if(!empty($attempt['checkout_url']))return $attempt;
        if(in_array($attempt['status'],['ambiguous','expired'],true) || time()-(int)$attempt['created_epoch']>82800)throw new DomainException('Checkout requer reconciliação ou nova solicitação antes de tentar novamente.');
        if(!$this->repository->execute('UPDATE billing_checkout_attempts SET lease_until=? WHERE id=? AND lease_until<?',[time()+60,$attempt['id'],time()]))throw new DomainException('Checkout em processamento.');
        $quote=json_decode($attempt['quote_json'],true,512,JSON_THROW_ON_ERROR);
        if($provider==='pagarme')$this->repository->execute("UPDATE billing_checkout_attempts SET status='ambiguous' WHERE id=?",[$attempt['id']]);
        try {
            $adapter=$provider==='stripe'?new StripeGateway($this->http,$credentials['secret'],$environment):new PagarmeGateway($this->http,$credentials['secret'],$environment,$this->repository);
            $result=$adapter->create($attempt,$quote);
            $this->repository->execute("UPDATE billing_checkout_attempts SET provider_checkout_id=?,checkout_url=?,status='pending',lease_until=0,failure_code=NULL WHERE id=?",[$result['provider_checkout_id'],$result['checkout_url'],$attempt['id']]);
        } catch(Throwable $e) {
            $this->repository->execute('UPDATE billing_checkout_attempts SET status=?,lease_until=0,failure_code=? WHERE id=?',[$provider==='stripe'?'retry':'ambiguous','provider_unavailable',$attempt['id']]);
            throw new \RuntimeException('Não foi possível confirmar a criação do checkout. Tente novamente ou aguarde reconciliação.');
        }
        return $this->repository->attempt($attempt['id']);
    }
}
