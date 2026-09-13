<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php'; // ajuste este caminho se o autoload estiver em outro lugar

use App\Core\Migrator;

// --- Ajuste estas credenciais conforme seu .env ---
$host = '127.0.0.1';
$port = '3306';
$db   = 'clipforge';
$user = 'root';
$pass = ''; // coloque sua senha do MySQL aqui, se tiver
// ----------------------------------------------------

$pdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$statement = $pdo->prepare(
    'SELECT cc.CHECK_CLAUSE, tc.ENFORCED FROM information_schema.TABLE_CONSTRAINTS tc '
    . 'INNER JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME '
    . "WHERE tc.TABLE_SCHEMA = ? AND tc.TABLE_NAME = 'clip_render_profiles' AND tc.CONSTRAINT_NAME = 'chk_clip_render_profiles_shape'"
);
$statement->execute([$db]);
$row = $statement->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo "Constraint não encontrada!\n";
    exit(1);
}

$actualClause = (string) $row['CHECK_CLAUSE'];
$enforced = (string) $row['ENFORCED'];

echo "=== Clause real do banco (bytes exatos) ===\n";
echo $actualClause . "\n\n";
echo "ENFORCED: {$enforced}\n\n";

// Este é o DIMENSIONS_CHECK exatamente como está no Migrator.php
$expectedClause = "(aspect_ratio = 'original' AND reframe_mode = 'original' AND output_width IS NULL AND output_height IS NULL) OR (reframe_mode IN ('center','manual','auto') AND output_width IS NOT NULL AND output_height IS NOT NULL AND ((aspect_ratio = '9:16' AND ((output_width = 720 AND output_height = 1280) OR (output_width = 1080 AND output_height = 1920))) OR (aspect_ratio = '1:1' AND ((output_width = 720 AND output_height = 720) OR (output_width = 1080 AND output_height = 1080))) OR (aspect_ratio = '16:9' AND ((output_width = 1280 AND output_height = 720) OR (output_width = 1920 AND output_height = 1080))) OR (aspect_ratio = '4:5' AND ((output_width = 720 AND output_height = 900) OR (output_width = 1080 AND output_height = 1350)))))";

$result = Migrator::checkConstraintMetadataMatches($actualClause, $expectedClause, $enforced);

echo "=== Resultado de checkConstraintMetadataMatches ===\n";
var_dump($result);

// Vamos também inspecionar via Reflection a função canonicalCheck (privada) para os dois lados
$reflection = new ReflectionClass(Migrator::class);
$method = $reflection->getMethod('canonicalCheck');
$method->setAccessible(true);

$canonicalActual = $method->invoke(null, $actualClause);
$canonicalExpected = $method->invoke(null, $expectedClause);

echo "\n=== Canonical ACTUAL ===\n";
echo $canonicalActual . "\n";

echo "\n=== Canonical EXPECTED ===\n";
echo $canonicalExpected . "\n";

echo "\n=== São idênticos? ===\n";
var_dump($canonicalActual === $canonicalExpected);
