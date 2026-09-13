#!/usr/bin/env php
<?php
declare(strict_types=1);

$usage = "Uso: php bin/project-media-notifications.php --initialize\n     php bin/project-media-notifications.php [--source=all|projects|clips|jobs] [--limit=1..100] [--batches=1..10]\n";
$source = 'all'; $limit = 25; $batches = 1; $initialize = false;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help') { echo $usage; exit(0); }
    if ($argument === '--initialize') { $initialize = true; continue; }
    if (preg_match('/^--source=(all|projects|clips|jobs)$/D', $argument, $match)) { $source = $match[1]; continue; }
    if (preg_match('/^--(limit|batches)=([0-9]+)$/D', $argument, $match)) { if ($match[1] === 'limit') $limit = (int) $match[2]; else $batches = (int) $match[2]; continue; }
    fwrite(STDERR, $usage); exit(2);
}
if ($limit < 1 || $limit > 100 || $batches < 1 || $batches > 10) { fwrite(STDERR, $usage); exit(2); }

require dirname(__DIR__) . '/bootstrap/app.php';

try {
    $pdo = \App\Core\Database::connection();
    $emitter = new \App\Communications\CommunicationEmitterService($pdo, new \App\Security\SecretCipher((string) \App\Core\Env::get('APP_ENCRYPTION_KEY','')), new \App\Communications\CommunicationEventCatalog());
    $projector = new \App\Communications\MediaNotificationProjector($pdo, $emitter);
    if ($initialize) { echo json_encode(['initialized' => $projector->initialize(), 'emitted' => 0], JSON_THROW_ON_ERROR) . PHP_EOL; exit(0); }
    $sources = $source === 'all' ? ['projects','clips','jobs'] : [$source];
    for ($batch = 0; $batch < $batches; ++$batch) foreach ($sources as $current) echo json_encode($projector->runBatch($current, $limit), JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
} catch (\Throwable) {
    fwrite(STDERR, "Falha na projeção de mídia. Verifique migração 023, chave de criptografia e checkpoints; inicialize antes do primeiro lote. Nenhum detalhe privado foi registrado.\n");
    exit(1);
}
