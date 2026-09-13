<?php

declare(strict_types=1);

namespace App\Deployment;

use InvalidArgumentException;

final class ProductionReadiness
{
    /** These values represent completed probes, never mere configuration presence. */
    public function evaluate(string $role,array $capabilities): array
    {
        $common=['php_runtime','database','migrations','private_storage','queue'];
        $worker=['gemini','proc_open','ffprobe','ffmpeg','captions','worker_budget'];
        $requirements=match ($role) {
            'web'=>array_merge($common,['https']),
            'worker'=>array_merge($common,$worker),
            'all-in-one'=>array_merge($common,['https'],$worker),
            default=>throw new InvalidArgumentException('Production role is invalid.'),
        };
        $missing=[];
        $checks=[];
        foreach ($requirements as $name) {
            $checks[$name]=($capabilities[$name] ?? false)===true;
            if (!$checks[$name]) $missing[]=$name;
        }
        $unverified=['cron_schedule','mail_delivery','backup_restore','full_user_workflow'];
        if ($role==='web') $unverified[]='external_worker';
        if ($role==='worker') $unverified[]='web_host';
        return ['ready'=>$missing===[],'role'=>$role,'scope'=>'host_capabilities','end_to_end_verified'=>false,
            'missing'=>$missing,'checks'=>$checks,'unverified'=>$unverified];
    }
}
