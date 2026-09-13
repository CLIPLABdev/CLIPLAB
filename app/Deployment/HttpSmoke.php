<?php

declare(strict_types=1);

namespace App\Deployment;

use InvalidArgumentException;
use RuntimeException;

final class HttpSmoke
{
    private const PUBLIC_PATHS=['/','/login','/cadastro'];
    private const AUTH_PATHS=['/dashboard','/clips','/conta/plano','/admin'];
    private const PRIVATE_PATHS=['/.env','/.git/config','/storage','/app','/app/Core/Env.php','/vendor/autoload.php','/config/database.php','/bootstrap/app.php','/composer.lock'];
    private $get;

    /** @param callable(string):array{0:int,1:array<string,string>}|null $get */
    public function __construct(?callable $get=null) { $this->get=$get ?? [$this,'httpsGet']; }

    /** @return array{ready:bool,checks:list<array{path:string,status:int,ok:bool}>} */
    public function run(string $baseUrl): array
    {
        $parts=parse_url($baseUrl);
        if (!is_array($parts) || ($parts['scheme'] ?? '')!=='https' || !isset($parts['host']) || $parts['host']===''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '',['','/'],true) || preg_match('/[\x00-\x20\x7f]/',$baseUrl)) {
            throw new InvalidArgumentException('Smoke base URL must be an HTTPS origin without credentials.');
        }
        $base=rtrim($baseUrl,'/');
        $checks=[];
        $ready=true;
        foreach (array_merge(self::PUBLIC_PATHS,self::AUTH_PATHS,self::PRIVATE_PATHS) as $path) {
            $status=0;
            $ok=false;
            try {
                [$status,$headers]=($this->get)($base.$path);
                if (!is_int($status) || !is_array($headers)) throw new RuntimeException('Invalid smoke response.');
                $headers=array_change_key_case($headers,CASE_LOWER);
                if (in_array($path,self::PUBLIC_PATHS,true)) $ok=$status===200 && $this->secureHeaders($headers);
                elseif (in_array($path,self::AUTH_PATHS,true)) {
                    $ok=in_array($status,[302,303],true) && in_array($headers['location'] ?? '',['/login',$base.'/login'],true)
                        && $this->secureHeaders($headers);
                } else $ok=in_array($status,[403,404],true);
            } catch (\Throwable) { $status=0; }
            $checks[]=['path'=>$path,'status'=>$status,'ok'=>$ok];
            $ready=$ready && $ok;
        }
        return ['ready'=>$ready,'checks'=>$checks];
    }

    private function secureHeaders(array $headers): bool
    {
        foreach (['content-security-policy','x-content-type-options','referrer-policy','permissions-policy','x-frame-options','strict-transport-security'] as $name) {
            if (!is_string($headers[$name] ?? null)) return false;
        }
        $csp=$headers['content-security-policy'];
        if (!preg_match("/(?:^|;)\\s*default-src\\s+'self'(?:\\s|;|$)/i",$csp)
            || !preg_match("/(?:^|;)\\s*object-src\\s+'none'\\s*(?:;|$)/i",$csp)
            || !preg_match("/(?:^|;)\\s*frame-ancestors\\s+'(?:self|none)'\\s*(?:;|$)/i",$csp)
            || preg_match("/'unsafe-(?:inline|eval)'/i",$csp)) return false;
        $hsts=$headers['strict-transport-security'];
        if (!preg_match('/(?:^|;)\s*max-age=([0-9]+)(?:\s*;|\s*$)/i',$hsts,$match) || (int)$match[1]<86400) return false;
        foreach (['camera','microphone','geolocation'] as $feature) {
            if (!preg_match('/(?:^|,)\s*'.$feature.'=\(\s*\)(?:\s*,|\s*$)/i',$headers['permissions-policy'])) return false;
        }
        return strtolower(trim($headers['x-content-type-options']))==='nosniff'
            && in_array(strtoupper(trim($headers['x-frame-options'])),['DENY','SAMEORIGIN'],true)
            && in_array(strtolower(trim($headers['referrer-policy'])),['no-referrer','same-origin','strict-origin','strict-origin-when-cross-origin'],true);
    }

    /** Bounded GET, verified TLS, no redirects, no credentials, and no response body in output. */
    private function httpsGet(string $url): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('HTTP transport is unavailable.');
        $curl=curl_init($url);
        if ($curl===false) throw new RuntimeException('HTTP transport is unavailable.');
        $headers=[];
        $headerBytes=0;
        $bodyBytes=0;
        try {
            curl_setopt_array($curl,[
                CURLOPT_HTTPGET=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>10,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER=>['Accept: text/html','User-Agent: ClipForge-Smoke/2'],
                CURLOPT_HEADERFUNCTION=>static function ($handle,string $line) use (&$headers,&$headerBytes): int {
                    $headerBytes+=strlen($line);
                    if ($headerBytes>32768) return 0;
                    if (preg_match('#^HTTP/\S+ [0-9]{3}#',$line)) $headers=[];
                    elseif (str_contains($line,':')) {
                        [$name,$value]=explode(':',$line,2);
                        $headers[strtolower(trim($name))]=trim($value);
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION=>static function ($handle,string $chunk) use (&$bodyBytes): int {
                    $bodyBytes+=strlen($chunk);
                    return $bodyBytes>1048576 ? 0 : strlen($chunk);
                },
            ]);
            $success=curl_exec($curl);
            return [$success===false ? 0 : (int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),$headers];
        } finally { curl_close($curl); }
    }
}
