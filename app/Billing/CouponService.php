<?php
declare(strict_types=1);
namespace App\Billing;

use DomainException;

final class CouponService
{
    public function __construct(private BillingRepository $repository) {}
    public static function normalize(?string $code): ?string
    {
        $code=strtoupper(trim((string)$code));if($code==='')return null;
        if(preg_match('/^[A-Z0-9_-]{2,80}$/D',$code)!==1)throw new DomainException('Código de cupom inválido.');return $code;
    }
    /** Admin authorization and CSRF belong at the route boundary. No financial rows are deleted. */
    public function save(array $input,?int $id=null): int
    {
        $code=self::normalize((string)($input['code']??''));$type=(string)($input['discount_type']??'');$value=filter_var($input['discount_value']??null,FILTER_VALIDATE_INT);
        $maximum=$input['max_redemptions']??null;$maximum=$maximum===''?null:$maximum;
        $perUser=filter_var($input['per_user_limit']??1,FILTER_VALIDATE_INT);
        if(!$code || !in_array($type,['percent','fixed'],true) || $value===false || $value<1 || ($type==='percent' && $value>99) || ($maximum!==null && (filter_var($maximum,FILTER_VALIDATE_INT)===false || (int)$maximum<1)) || $perUser===false || $perUser<1 || ($input['currency']??'BRL')!=='BRL')throw new DomainException('Regras do cupom inválidas.');
        $start=$this->date($input['starts_at']??null);$end=$this->date($input['ends_at']??null);
        if($start && $end && $start>=$end)throw new DomainException('Período do cupom inválido.');
        $plans=$input['plan_ids']??[];if(!is_array($plans) || count($plans)>100)throw new DomainException('Planos do cupom inválidos.');
        $plans=array_values(array_unique(array_map(static function($value){if(filter_var($value,FILTER_VALIDATE_INT)===false || (int)$value<1)throw new DomainException('Plano inválido.');return (int)$value;},$plans)));
        return $this->repository->transaction(function()use($code,$type,$value,$maximum,$perUser,$start,$end,$plans,$input,$id){
            if($id!==null && !$this->repository->one('SELECT id FROM coupons WHERE id=?'.$this->repository->lockSuffix(),[$id]))throw new DomainException('Cupom não encontrado.');
            foreach($plans as $plan)if(!$this->repository->one('SELECT id FROM plans WHERE id=?',[$plan]))throw new DomainException('Plano não encontrado.');
            $values=[$code,$type,$value,'BRL',$start,$end,$maximum,$perUser,!empty($input['is_active'])?1:0];
            if($id===null){$this->repository->execute('INSERT INTO coupons(code,discount_type,discount_value,currency,starts_at,ends_at,max_redemptions,per_user_limit,is_active) VALUES(?,?,?,?,?,?,?,?,?)',$values);$id=$this->repository->insertId();}
            else{$values[]=$id;$this->repository->execute('UPDATE coupons SET code=?,discount_type=?,discount_value=?,currency=?,starts_at=?,ends_at=?,max_redemptions=?,per_user_limit=?,is_active=? WHERE id=?',$values);}
            $this->repository->execute('DELETE FROM coupon_plans WHERE coupon_id=?',[$id]);
            foreach($plans as $plan)$this->repository->execute('INSERT INTO coupon_plans(coupon_id,plan_id) VALUES(?,?)',[$id,$plan]);return $id;
        });
    }
    public function preview(int $userId,int $planId,?string $code=null,string $environment='production'): array
    {
        if(!in_array($environment,['sandbox','production'],true))throw new DomainException('Ambiente inválido.');
        return $this->repository->transaction(function()use($userId,$planId,$code,$environment){
            $this->repository->lockUser($userId);$plan=$this->repository->one('SELECT id,name,price_cents FROM plans WHERE id=? AND is_active=1 AND price_cents>0',[$planId]);
            if(!$plan)throw new DomainException('Plano indisponível.');
            $normalized=self::normalize($code);$price=$normalized===null?PriceQuote::full((int)$plan['price_cents']):$this->candidate($normalized,$userId,$planId,(int)$plan['price_cents'],$environment);
            return array_merge($price,['plan_id'=>$planId,'plan_name'=>$plan['name'],'interval'=>'month','interval_count'=>1]);
        });
    }
    /** Must run inside checkout transaction after user lock and attempt insert. */
    public function reserve(string $code,int $userId,int $planId,int $gross,string $attemptId,string $environment): array
    {
        $this->repository->requireTransaction();$code=self::normalize($code);if(!$code)throw new DomainException('Cupom inválido.');
        $existing=$this->repository->one('SELECT * FROM coupon_redemptions WHERE checkout_attempt_id=?',[$attemptId]);
        if($existing){
            if($existing['status']==='released' || (int)$existing['user_id']!==$userId || $existing['coupon_code']!==$code)throw new DomainException('Reserva do cupom incompatível.');
            return json_decode($existing['price_snapshot'],true,512,JSON_THROW_ON_ERROR);
        }
        $attempt=$this->repository->attemptForUser($attemptId,$userId);
        if(!$attempt || (int)$attempt['plan_id']!==$planId || $attempt['environment']!==$environment)throw new DomainException('Tentativa não corresponde ao cupom.');
        $price=$this->candidate($code,$userId,$planId,$gross,$environment);
        $this->repository->execute("INSERT INTO coupon_redemptions(coupon_id,user_id,checkout_attempt_id,coupon_code,price_snapshot,discount_cents,status) VALUES(?,?,?,?,?,?,'reserved')",[$price['coupon_id'],$userId,$attemptId,$code,json_encode($price,JSON_THROW_ON_ERROR),$price['discount_cents']]);return $price;
    }
    public function apply(string $attemptId,int $paymentId): void
    {
        $this->repository->requireTransaction();$reservation=$this->repository->one('SELECT * FROM coupon_redemptions WHERE checkout_attempt_id=?',[$attemptId]);
        if(!$reservation)throw new DomainException('Reserva do cupom ausente.');
        $this->repository->one('SELECT id FROM coupons WHERE id=?'.$this->repository->lockSuffix(),[$reservation['coupon_id']]);
        $reservation=$this->repository->one('SELECT * FROM coupon_redemptions WHERE checkout_attempt_id=?'.$this->repository->lockSuffix(),[$attemptId]);
        if($reservation['status']==='released')throw new DomainException('Reserva do cupom liberada; pagamento exige revisão.');
        // One redemption per subscription; renewals cannot consume an additional slot.
        $this->repository->execute("UPDATE coupon_redemptions SET status='applied',payment_id=?,applied_at=? WHERE checkout_attempt_id=? AND status='reserved'",[$paymentId,gmdate('Y-m-d H:i:s'),$attemptId]);
    }
    /** Caller may release only after authoritative checkout expiration/cancel confirmation. */
    public function release(string $attemptId): void
    {
        $this->repository->requireTransaction();$reservation=$this->repository->one('SELECT coupon_id FROM coupon_redemptions WHERE checkout_attempt_id=?',[$attemptId]);
        if(!$reservation)return;$this->repository->one('SELECT id FROM coupons WHERE id=?'.$this->repository->lockSuffix(),[$reservation['coupon_id']]);
        $this->repository->execute("UPDATE coupon_redemptions SET status='released',released_at=? WHERE checkout_attempt_id=? AND status='reserved'",[gmdate('Y-m-d H:i:s'),$attemptId]);
    }
    private function candidate(string $code,int $userId,int $planId,int $gross,string $environment): array
    {
        $coupon=$this->repository->one('SELECT * FROM coupons WHERE code=?'.$this->repository->lockSuffix(),[$code]);$now=gmdate('Y-m-d H:i:s');
        if(!$coupon || !(int)$coupon['is_active'] || ($coupon['starts_at'] && $coupon['starts_at']>$now) || ($coupon['ends_at'] && $coupon['ends_at']<=$now) || ($coupon['currency'] && $coupon['currency']!=='BRL'))throw new DomainException('Cupom indisponível.');
        // Locking reads are current reads under InnoDB REPEATABLE READ, unlike a COUNT
        // from the snapshot established before waiting for the coupon-row lock.
        $restricted=$this->repository->all('SELECT plan_id FROM coupon_plans WHERE coupon_id=?'.$this->repository->lockSuffix(),[$coupon['id']]);
        if($restricted && !in_array($planId,array_map('intval',array_column($restricted,'plan_id')),true))throw new DomainException('Cupom não se aplica ao plano.');
        $redemptions=$this->repository->all("SELECT r.id,r.user_id FROM coupon_redemptions r JOIN billing_checkout_attempts a ON a.id=r.checkout_attempt_id WHERE r.coupon_id=? AND a.environment=? AND r.status IN ('reserved','applied')".$this->repository->lockSuffix(),[$coupon['id'],$environment]);
        $personal=count(array_filter($redemptions,static fn($r)=>(int)$r['user_id']===$userId));
        if(($coupon['max_redemptions']!==null && count($redemptions)>=(int)$coupon['max_redemptions']) || $personal>=(int)$coupon['per_user_limit'])throw new DomainException('Limite de uso do cupom atingido.');
        return array_merge(PriceQuote::discount($gross,$coupon['discount_type'],(int)$coupon['discount_value']),['coupon_id'=>(int)$coupon['id'],'coupon_code'=>$coupon['code']]);
    }
    private function date($value): ?string
    {
        if($value===null || $value==='')return null;
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',(string)$value,new \DateTimeZone('UTC'));
        if(!$parsed || $parsed->format('Y-m-d H:i:s')!==$value)throw new DomainException('Data do cupom inválida. Use UTC.');return $value;
    }
}
