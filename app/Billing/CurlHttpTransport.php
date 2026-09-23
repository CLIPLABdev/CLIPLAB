<?php
declare(strict_types=1);
namespace App\Billing;

use DomainException;
use RuntimeException;

final class CurlHttpTransport implements HttpTransport
{
    public function request(string $method,string $url,array $headers,array $body=[]): array
    {
        $parts=parse_url($url);$host=$parts['host']??'';$path=$parts['path']??'';
        $prefix=$host==='api.stripe.com'?'/v1/':'/core/v5/';
        if(!$parts || !in_array($host,['api.stripe.com','api.pagar.me','sdx-api.pagar.me'],true) || ($parts['scheme']??'')!=='https' || isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || !in_array($method,['GET','POST','DELETE','PATCH'],true) || !str_starts_with($path,$prefix) || str_contains($path,'..') || preg_match('/[\r\n]/',$url))throw new DomainException('Destino financeiro inválido.');
        if(!function_exists('curl_init'))throw new RuntimeException('Transporte financeiro indisponível.');
        $allowed=['Authorization','Content-Type','Stripe-Version','Idempotency-Key'];$lines=['Accept: application/json','User-Agent: ClipForge-Billing/1.0'];
        foreach($headers as $key=>$value){if(!in_array($key,$allowed,true) || !is_string($value) || preg_match('/[\r\n]/',$value))throw new DomainException('Cabeçalho financeiro inválido.');$lines[]=$key.': '.$value;}
        $payload=$host==='api.stripe.com'?http_build_query($body,'','&',PHP_QUERY_RFC3986):json_encode($body,JSON_THROW_ON_ERROR);
        $response='';$tooLarge=false;$handle=curl_init($url);
        curl_setopt_array($handle,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$lines,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_PROXY=>'',CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use(&$response,&$tooLarge):int {if(strlen($response)+strlen($chunk)>1048576){$tooLarge=true;return 0;}$response.=$chunk;return strlen($chunk);}]);
        if($method!=='GET' && $body!==[])curl_setopt($handle,CURLOPT_POSTFIELDS,$payload);
        $ok=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
        if($ok===false || $tooLarge || $status<200 || $status>=300)throw new RuntimeException('Gateway temporariamente indisponível.');
        try {$decoded=json_decode($response,true,64,JSON_THROW_ON_ERROR);}catch(\Throwable $e){throw new RuntimeException('Resposta financeira inválida.');}
        if(!is_array($decoded))throw new RuntimeException('Resposta financeira inválida.');return $decoded;
    }
}
