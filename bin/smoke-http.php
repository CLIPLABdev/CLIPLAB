#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

$base=null;
foreach (array_slice($argv,1) as $argument) {
    if ($base===null && str_starts_with($argument,'--base-url=')) $base=substr($argument,11);
    else { fwrite(STDERR,"Uso: smoke-http.php --base-url=https://seu-dominio\n"); exit(2); }
}
if (!is_string($base) || $base==='') { fwrite(STDERR,"Uso: smoke-http.php --base-url=https://seu-dominio\n"); exit(2); }
try {
    $result=(new \App\Deployment\HttpSmoke())->run($base);
    echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    exit($result['ready'] ? 0 : 1);
} catch (\InvalidArgumentException) { fwrite(STDERR,"Informe apenas a origem HTTPS, sem caminho ou credenciais.\n"); exit(2); }
catch (\Throwable) { fwrite(STDERR,"Não foi possível concluir o smoke HTTP.\n"); exit(1); }
