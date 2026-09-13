<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Deployment\ProductionReadiness;
use PHPUnit\Framework\TestCase;

final class DeploymentReadinessTest extends TestCase
{
    public function testConfiguredValuesAloneNeverQualifyAsProductionReady(): void
    {
        $configured=['https'=>true,'database'=>true,'private_storage'=>true,'gemini'=>true,'queue'=>true,'proc_open'=>true,'ffprobe'=>true,'ffmpeg'=>true];
        foreach (['web','worker','all-in-one'] as $role) self::assertFalse((new ProductionReadiness())->evaluate($role,$configured)['ready']);
    }
    public function testRolesRequireOnlyVerifiedCapabilitiesAndDoNotClaimExternalWorkerOrCron(): void
    {
        $verified=['php_runtime'=>true,'https'=>true,'database'=>true,'migrations'=>true,'private_storage'=>true,'queue'=>true,
            'gemini'=>true,'proc_open'=>true,'ffprobe'=>true,'ffmpeg'=>true,'captions'=>true,'worker_budget'=>true];
        $gate=new ProductionReadiness();
        self::assertTrue($gate->evaluate('all-in-one',$verified)['ready']);
        $verified['ffmpeg']=false;
        self::assertFalse($gate->evaluate('worker',$verified)['ready']);
        $web=$gate->evaluate('web',$verified);
        self::assertTrue($web['ready']);
        self::assertContains('external_worker',$web['unverified']);
        self::assertContains('cron_schedule',$web['unverified']);
        self::assertSame('host_capabilities',$web['scope']);
        self::assertFalse($web['end_to_end_verified']);
    }
}
