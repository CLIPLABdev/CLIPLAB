<?php
declare(strict_types=1);
namespace App\Billing;

final class HostedCheckoutUrl
{
    public static function validate(string $url,string $provider): string
    {
        $parts=parse_url($url);$allowed=$provider==='stripe'?['checkout.stripe.com']:['payment-link.pagar.me','sdx-payment-link.pagar.me'];
        if(!$parts || strlen($url)>4096 || ($parts['scheme']??'')!=='https' || !in_array($parts['host']??'',$allowed,true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || preg_match('/[\r\n]/',$url))throw new \DomainException('URL do checkout inválida.');
        return $url;
    }
}
