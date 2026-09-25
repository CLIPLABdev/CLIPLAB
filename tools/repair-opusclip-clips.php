<?php

declare(strict_types=1);

/*
 * Corrige cortes da OpusClip gerados antes desta versão:
 *  - projeto parado em 75% passa para "Concluído";
 *  - capa refeita a partir do próprio corte (não do vídeo inteiro);
 *  - início/fim no vídeo de origem (timeRanges), para a prévia do editor mostrar o trecho certo.
 * Uso: php tools/repair-opusclip-clips.php          (aplica)
 *      php tools/repair-opusclip-clips.php --dry-run (só mostra)
 */

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Media\ClipFrameThumbnail;
use App\OpusClip\OpusClipClient;
use App\Process\ProcessRunner;
use App\Queue\OpusClipTimeRanges;
use App\Repositories\ProjectRepository;
use App\Storage\LocalPrivateStorage;

require dirname(__DIR__) . '/bootstrap/app.php';

$dryRun = in_array('--dry-run', $argv, true);
$pdo = Database::connection();
$media = (array) Config::get('media', []);
$privateRoot = (string) $media['private_root'];
$ffmpeg = (string) ($media['ffmpeg_binary'] ?? 'ffmpeg');
$storage = new LocalPrivateStorage($privateRoot, (int) ($media['effective_upload_bytes'] ?? 524288000));
$thumbnails = new ClipFrameThumbnail(new ProcessRunner([$ffmpeg], $privateRoot), $ffmpeg);
$projects = new ProjectRepository($pdo);
$apiKey = (string) Env::get('OPUSCLIP_API_KEY', '');
$opus = $apiKey !== '' ? new OpusClipClient($apiKey) : null;

$analyses = $pdo->query(
    "SELECT DISTINCT a.id, a.project_id, a.provider_request_id
     FROM ai_analyses a INNER JOIN clips c ON c.ai_analysis_id = a.id
     WHERE a.provider_request_id IS NOT NULL
       AND (a.model = 'opusclip' OR c.category = 'opusclip' OR c.reason = 'Gerado automaticamente pela OpusClip.')
     ORDER BY a.id"
)->fetchAll(PDO::FETCH_ASSOC);

$summary = ['projetos' => 0, 'capas' => 0, 'tempos' => 0];
foreach ($analyses as $analysis) {
    $projectId = (int) $analysis['project_id'];
    $raw = [];
    if ($opus !== null) {
        try {
            $raw = array_values($opus->getClipsForProject((string) $analysis['provider_request_id']));
        } catch (Throwable) {
            echo "Projeto {$projectId}: não foi possível consultar a OpusClip (tempos mantidos)\n";
        }
    }

    $clips = $pdo->prepare('SELECT id, suggestion_index, output_file, thumbnail, render_start_time FROM clips WHERE ai_analysis_id = :id ORDER BY suggestion_index');
    $clips->execute(['id' => (int) $analysis['id']]);
    foreach ($clips->fetchAll(PDO::FETCH_ASSOC) as $clip) {
        $clipId = (int) $clip['id'];
        $source = $raw[(int) $clip['suggestion_index']] ?? null;
        $window = is_array($source) ? OpusClipTimeRanges::sourceWindow($source['timeRanges'] ?? null) : null;
        $genre = is_array($source) && is_string($source['genre'] ?? null) && trim($source['genre']) !== '' ? $source['genre'] : 'Corte automático';

        if ($window !== null && $clip['render_start_time'] === null) {
            echo "Corte {$clipId}: trecho {$window[0]}s–{$window[1]}s\n";
            if (!$dryRun) {
                $pdo->prepare('UPDATE clips SET start_time = :s, end_time = :e WHERE id = :id')
                    ->execute(['s' => number_format($window[0], 3, '.', ''), 'e' => number_format($window[1], 3, '.', ''), 'id' => $clipId]);
            }
            $summary['tempos']++;
        }
        if (!$dryRun) {
            $pdo->prepare("UPDATE clips SET category = :category, reason = :reason WHERE id = :id AND (category = 'opusclip' OR reason = 'Gerado automaticamente pela OpusClip.')")
                ->execute([
                    'category' => mb_substr($genre, 0, 32),
                    'reason' => 'Trecho escolhido automaticamente pela IA por ter começo, meio e fim com potencial de engajamento.',
                    'id' => $clipId,
                ]);
        }

        if (is_string($clip['output_file']) && $clip['output_file'] !== '') {
            echo "Corte {$clipId}: nova capa a partir do próprio corte\n";
            if (!$dryRun) {
                $thumb = $thumbnails->fromVideo($storage, $clip['output_file'], 'clips/opusclip/' . $projectId . '/' . bin2hex(random_bytes(16)) . '.jpg');
                if ($thumb !== null) {
                    $pdo->prepare('UPDATE clips SET thumbnail = :t, thumbnail_size_bytes = :s WHERE id = :id')
                        ->execute(['t' => $thumb->objectKey(), 's' => $thumb->sizeBytes(), 'id' => $clipId]);
                    if (is_string($clip['thumbnail']) && $clip['thumbnail'] !== '' && $clip['thumbnail'] !== $thumb->objectKey()) {
                        try { $storage->delete($clip['thumbnail']); } catch (Throwable) {}
                    }
                    $summary['capas']++;
                } else {
                    echo "Corte {$clipId}: arquivo do corte não encontrado no disco (capa mantida)\n";
                }
            }
        }
    }

    if (!$dryRun) {
        $projects->synchronizeRenderState($projectId);
    }
    $summary['projetos']++;
}

echo ($dryRun ? '[simulação] ' : '') . json_encode($summary, JSON_UNESCAPED_UNICODE) . PHP_EOL;
