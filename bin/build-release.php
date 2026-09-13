#!/usr/bin/env php
<?php

declare(strict_types=1);

$output=null;
foreach (array_slice($argv,1) as $argument) {
    if ($output===null && str_starts_with($argument,'--output=')) $output=substr($argument,9);
    else { fwrite(STDERR,"Uso: build-release.php --output=/caminho/novo.zip\n"); exit(2); }
}
if (!is_string($output) || $output==='') { fwrite(STDERR,"Uso: build-release.php --output=/caminho/novo.zip\n"); exit(2); }
require dirname(__DIR__).'/vendor/autoload.php';

try {
    $result=(new \App\Deployment\ReleaseBuilder(dirname(__DIR__)))->build($output);
    echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
} catch (\Throwable) { fwrite(STDERR,"Falha ao gerar artefato de release. Use um ZIP novo fora da origem, public e storage.\n"); exit(1); }
