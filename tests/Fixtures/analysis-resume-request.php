<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';

$path = realpath($argv[1] ?? '');
$root = realpath(dirname(__DIR__,2).'/.local-history');
// Windows tempnam retains only the first three prefix characters.
if ($path === false || $root === false || dirname($path) !== $root || !str_starts_with(basename($path),'res')) exit(2);
$pdo = new \Tests\Support\AnalysisResumeDatabase('sqlite:'.$path, false);
echo "READY\n";
flush();
echo $pdo->service()->resume(11,7,isset($argv[2]) ? (int)$argv[2] : null)."\n";
