<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Process\ProcessRunner;
use PHPUnit\Framework\TestCase;

final class DeploymentCliHardeningTest extends TestCase
{
    public function testUsageErrorsDoNotLoadEnvironmentOrProduceArtifacts(): void
    {
        $runner=new ProcessRunner([PHP_BINARY],sys_get_temp_dir());
        $root=dirname(__DIR__,2);
        $commands=[
            [PHP_BINARY,$root.'/bin/check-production.php','--role=unknown'],
            [PHP_BINARY,$root.'/bin/check-production.php','--role=web','--role=worker'],
            [PHP_BINARY,$root.'/bin/smoke-http.php','--base-url=http://example.test'],
            [PHP_BINARY,$root.'/bin/build-release.php','--output=one.zip','--output=two.zip'],
        ];
        foreach ($commands as $command) {
            $result=$runner->run($command,10,16384);
            self::assertSame(2,$result->exitCode,basename($command[1]));
            self::assertStringNotContainsString('Stack trace',$result->stderr);
        }
    }

    public function testConfiguredValuesCannotMakeCliReadyAndNoSecretEscapes(): void
    {
        $environment=['APP_ENV_FILE'=>'','APP_ENV'=>'testing','APP_DEBUG'=>'false','APP_URL'=>'https://example.test',
            'DB_DSN'=>'missing-driver:readiness-test','DB_USERNAME'=>'FAKE_DB_USER','DB_PASSWORD'=>'PASSWORD_CANARY',
            'GEMINI_API_KEY'=>'FAKE_KEY_CANARY','GEMINI_MODEL'=>'fake-model','MEDIA_PRIVATE_ROOT'=>sys_get_temp_dir()];
        $before=[];
        foreach ($environment as $name=>$value) { $before[$name]=getenv($name); putenv($name.'='.$value); }
        try {
            $result=(new ProcessRunner([PHP_BINARY],sys_get_temp_dir()))->run([PHP_BINARY,dirname(__DIR__,2).'/bin/check-production.php','--role=web'],10,16384);
            self::assertSame(1,$result->exitCode);
            $payload=json_decode($result->stdout,true,32,JSON_THROW_ON_ERROR);
            self::assertFalse($payload['ready']);
            self::assertContains('database',$payload['missing']);
            self::assertContains('https',$payload['missing']);
            self::assertStringNotContainsString('CANARY',$result->stdout.$result->stderr);
            self::assertStringNotContainsString('missing-driver',$result->stdout.$result->stderr);
        } finally {
            foreach ($before as $name=>$value) $value===false ? putenv($name) : putenv($name.'='.$value);
        }
    }
}
