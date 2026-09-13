<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Deployment\HttpSmoke;
use PHPUnit\Framework\TestCase;

final class DeploymentHttpSmokeTest extends TestCase
{
    private function headers(): array
    {
        return ['content-security-policy'=>"default-src 'self'; object-src 'none'; frame-ancestors 'self'; script-src 'self'",
            'x-content-type-options'=>'nosniff','referrer-policy'=>'strict-origin-when-cross-origin',
            'permissions-policy'=>'camera=(), microphone=(), geolocation=()','x-frame-options'=>'SAMEORIGIN',
            'strict-transport-security'=>'max-age=31536000; includeSubDomains'];
    }
    private function response(string $url): array
    {
        $path=parse_url($url,PHP_URL_PATH);
        if (in_array($path,['/','/login','/cadastro'],true)) return [200,$this->headers()];
        if (in_array($path,['/dashboard','/clips','/conta/plano','/admin'],true)) return [302,$this->headers()+['location'=>'/login']];
        return [404,$this->headers()];
    }
    public function testChecksSafeGetRoutesAndAuthenticationWithoutFollowingRedirects(): void
    {
        $seen=[];
        $result=(new HttpSmoke(function (string $url) use (&$seen): array { $seen[]=parse_url($url,PHP_URL_PATH); return $this->response($url); }))->run('https://example.test');
        self::assertTrue($result['ready']);
        foreach (['/dashboard','/clips','/conta/plano','/admin','/.env','/.git/config','/vendor/autoload.php','/config/database.php','/app/Core/Env.php','/composer.lock'] as $path) self::assertContains($path,$seen);
        self::assertNotContains('/logout',$seen);
        self::assertNotContains('/privacidade/consentimentos/mediapipe',$seen);
    }
    public function testWeakSecurityHeadersOnAnyPublicPageBlockReadiness(): void
    {
        foreach (['content-security-policy'=>'x','strict-transport-security'=>'max-age=0','x-frame-options'=>'ALLOWALL','referrer-policy'=>'unsafe-url','permissions-policy'=>''] as $name=>$bad) {
            $result=(new HttpSmoke(function (string $url) use ($name,$bad): array {
                [$status,$headers]=$this->response($url);
                if (str_ends_with($url,'/login')) $headers[$name]=$bad;
                return [$status,$headers];
            }))->run('https://example.test');
            self::assertFalse($result['ready'],$name);
        }
    }
    public function testForeignRedirectsExposedFilesAndTransportErrorsFailWithoutLeakingInternals(): void
    {
        foreach (['/admin','/config/database.php','/login'] as $failure) {
            $result=(new HttpSmoke(function (string $url) use ($failure): array {
                if (str_ends_with($url,$failure)) {
                    if ($failure==='/login') throw new \RuntimeException('PASSWORD_CANARY');
                    return $failure==='/admin' ? [302,['location'=>'https://evil.test/login']] : [200,[]];
                }
                return $this->response($url);
            }))->run('https://example.test');
            self::assertFalse($result['ready']);
            self::assertStringNotContainsString('PASSWORD_CANARY',json_encode($result));
        }
    }
    public function testRejectsUnusableBaseUrlBeforeAnyRequest(): void
    {
        foreach (['http://example.test','https://u:p@example.test','https://example.test/subdir','https://example.test/?key=x',"https://example.test\r\nX-Foo:bar"] as $url) {
            try { (new HttpSmoke(static function (): void { throw new \LogicException('Network must not run'); }))->run($url); self::fail('Invalid base accepted'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
}
