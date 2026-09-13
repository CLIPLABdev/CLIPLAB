<?php
declare(strict_types=1);
namespace App\Billing;

use DomainException;

/** All prices are integer BRL cents. Coupons reduce every monthly renewal. */
final class PriceQuote
{
    public static function discount(int $gross,string $type,int $value): array
    {
        if($gross<100 || $gross>2147483647 || $value<1 || !in_array($type,['percent','fixed'],true) || ($type==='percent' && $value>99))throw new DomainException('Desconto inválido.');
        $discount=$type==='percent'?intdiv($gross*$value,100):$value;
        if($gross-$discount<100)throw new DomainException('O valor recorrente deve ser pelo menos R$ 1,00. Cupons não criam teste gratuito.');
        return ['gross_amount_cents'=>$gross,'discount_cents'=>$discount,'amount_cents'=>$gross-$discount,'currency'=>'BRL','discount_duration'=>'subscription'];
    }
    public static function full(int $gross): array
    {
        if($gross<100 || $gross>2147483647)throw new DomainException('Preço inválido.');
        return ['gross_amount_cents'=>$gross,'discount_cents'=>0,'amount_cents'=>$gross,'currency'=>'BRL','discount_duration'=>null,'coupon_id'=>null,'coupon_code'=>null];
    }
}
