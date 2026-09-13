#!/usr/bin/env php
<?php

declare(strict_types=1);

$role=null;
$verifyHttp=false;
$verifyGemini=false;
foreach (array_slice($argv,1) as $argument) {
    if ($role===null && str_starts_with($argument,'--role=')) $role=substr($argument,7);
    elseif ($argument==='--verify-http' && !$verifyHttp) $verifyHttp=true;
    elseif ($argument==='--verify-gemini' && !$verifyGemini) $verifyGemini=true;
    else { fwrite(STDERR,"Uso: check-production.php --role=web|worker|all-in-one [--verify-http] [--verify-gemini]\n"); exit(2); }
}
if (!in_array($role,['web','worker','all-in-one'],true)) { fwrite(STDERR,"Informe --role=web|worker|all-in-one.\n"); exit(2); }
require dirname(__DIR__).'/bootstrap/app.php';

try {
    $configuration=['database'=>(array)\App\Core\Config::get('database',[]),
        'media'=>(array)\App\Core\Config::get('media',[]),'gemini'=>(array)\App\Core\Config::get('gemini',[]),
        'app_url'=>(string)\App\Core\Env::get('APP_URL',''),
        'encryption_key'=>(string)\App\Core\Env::get('APP_ENCRYPTION_KEY','')];
    $capabilities=(new \App\Deployment\ProductionEnvironmentProbe(dirname(__DIR__),$configuration))->collect($role,$verifyHttp,$verifyGemini);
    $result=(new \App\Deployment\ProductionReadiness())->evaluate($role,$capabilities);
    echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    exit($result['ready'] ? 0 : 1);
} catch (\Throwable) { fwrite(STDERR,"Não foi possível verificar os requisitos de produção.\n"); exit(1); }
