#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Ai\AnalysisResponseValidator;
use App\Ai\ViralClipPrompt;
use App\Core\Config;
use App\Core\Database;
use App\Gemini\CurlGeminiTransport;
use App\OpusClip\OpusClipClient;
use App\Queue\OpusClipProcessHandler;
use App\Media\DirectUrlValidator;
use App\Media\LocalFfprobeProcessor;
use App\Media\LocalFfmpegClipRenderer;
use App\Media\PinnedHttpDownloader;
use App\Media\UploadValidator;
use App\Process\ProcessRunner;
use App\Queue\AnalyzeVideoHandler;
use App\Queue\FetchAndProbeHandler;
use App\Queue\GenerateClipsHandler;
use App\Queue\JobHandler;
use App\Queue\LeaseProcessingEffectGuard;
use App\Queue\ProbeSourceHandler;
use App\Queue\RenderClipHandler;
use App\Queue\WorkerLeaseBudget;
use App\Repositories\AiAnalysisRepository;
use App\Repositories\ClipRenderProfileRepository;
use App\Repositories\ClipRepository;
use App\Repositories\CreditReservationRepository;
use App\Repositories\CreditTransactionRepository;
use App\Repositories\ProcessingJobRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\ProjectSourceRepository;
use App\Repositories\RenderArtifactCleanupRepository;
use App\Services\AiPipelineStarter;
use App\Services\CreditReservationService;
use App\Services\DatabaseJobDispatcher;
use App\Services\GeminiService;
use App\Services\QueueWorker;
use App\Services\RenderMaintenance;
use App\Storage\LocalPrivateStorage;

$bootstrapPath = getenv('PROCESS_JOBS_BOOTSTRAP_PATH');
if (!is_string($bootstrapPath) || $bootstrapPath === '') {
    $bootstrapPath = dirname(__DIR__) . '/bootstrap/app.php';
}
try {
    if (!is_file($bootstrapPath)) {
        throw new RuntimeException('Worker bootstrap is unavailable.');
    }
    @require $bootstrapPath;
} catch (Throwable $exception) {
    fwrite(STDERR, "{\"error\":\"worker_bootstrap_failed\"}" . PHP_EOL);
    exit(1);
}

/** @return array{queue:string, limit:int, time_budget:int}|null */
function processJobsArguments(array $arguments): ?array
{
    $values = ['queue' => 'media', 'limit' => '1', 'time-budget' => '50'];
    $provided = [];
    foreach ($arguments as $argument) {
        if (!is_string($argument) || !str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            return null;
        }
        [$name, $value] = explode('=', substr($argument, 2), 2);
        if (!in_array($name, ['queue', 'limit', 'time-budget'], true) || array_key_exists($name, $provided)) {
            return null;
        }
        $values[$name] = $value;
        $provided[$name] = true;
    }
    if ($values['queue'] !== 'media' || !ctype_digit($values['limit']) || !ctype_digit($values['time-budget'])) {
        return null;
    }
    $limit = (int) $values['limit'];
    $timeBudget = (int) $values['time-budget'];
    if ($limit < 1 || $limit > 10 || $timeBudget < 5 || $timeBudget > 240) {
        return null;
    }

    return ['queue' => 'media', 'limit' => $limit, 'time_budget' => $timeBudget];
}

function processJobsUsage(): void
{
    fwrite(STDERR, "Uso: php bin/process-jobs.php [--queue=media] [--limit=1..10] [--time-budget=5..240]\n");
}

$arguments = processJobsArguments(array_slice($argv, 1));
if ($arguments === null) {
    processJobsUsage();
    exit(2);
}

try {
    $media = (array) Config::get('media', []);
    $gemini = (array) Config::get('gemini', []);
    $queueConfig = is_array($media['queue'] ?? null) ? $media['queue'] : [];
    $leaseSeconds = (int) ($queueConfig['lease_seconds'] ?? 300);
    $httpTimeoutSeconds = (int) ($gemini['http_timeout_seconds'] ?? 180);
    $renderTimeoutSeconds = (int) ($media['render_timeout_seconds'] ?? 240);
    $downloadTimeoutSeconds = (int) ($media['download_timeout_seconds'] ?? 120);
    $processTimeoutSeconds = (int) ($media['process_timeout_seconds'] ?? 60);
    $requiredLease = WorkerLeaseBudget::requiredSeconds(
        $httpTimeoutSeconds,
        $renderTimeoutSeconds,
        $downloadTimeoutSeconds,
        $processTimeoutSeconds,
        30,
        true,
        ($media['youtube_import_enabled'] ?? false) ? (int) ($media['yt_dlp_timeout_seconds'] ?? 60) : 0,
        ($media['youtube_import_enabled'] ?? false) ? (int) ($media['process_timeout_seconds'] ?? 60) : 0
    );
    if ($leaseSeconds < $requiredLease) {
        throw new RuntimeException('Worker lease is shorter than the provider or render operation budget.');
    }
    $workerId = substr((string) gethostname() . '-' . getmypid(), 0, 100);
    $pdo = Database::connection();
    $gemini = (new \App\Services\AdminGeminiSettingsService(
        $pdo,
        $gemini,
        (string) \App\Core\Env::get('APP_ENCRYPTION_KEY', '')
    ))->effective();
    $systemLogs = new \App\Repositories\SystemLogRepository($pdo);
    $projects = new ProjectRepository($pdo);
    $sources = new ProjectSourceRepository($pdo);
    $maxBytes = (int) ($media['effective_upload_bytes'] ?? $media['max_upload_bytes'] ?? 524288000);
    $privateRoot = (string) ($media['private_root'] ?? '');
    $storage = new LocalPrivateStorage($privateRoot, $maxBytes);
    $uploads = new UploadValidator($maxBytes);
    $ffprobe = (string) ($media['ffprobe_binary'] ?? 'ffprobe');
    $ffmpeg = (string) ($media['ffmpeg_binary'] ?? 'ffmpeg');
    $runner = new ProcessRunner([$ffprobe, $ffmpeg], $privateRoot);
    $downloader = new PinnedHttpDownloader(
        new DirectUrlValidator(),
        $uploads,
        $downloadTimeoutSeconds,
        (int) ($media['max_redirects'] ?? 2),
        $storage,
        null,
        $runner,
        $ffmpeg,
        $processTimeoutSeconds,
        (int) ($media['process_output_limit_bytes'] ?? 1048576)
    );
    $youtubeResolver = null;
    if ($media['youtube_import_enabled'] ?? false) {
        $youtubeBinary = (string) ($media['yt_dlp_binary'] ?? 'yt-dlp');
        $youtubeResolver = new \App\Media\YtDlpYoutubeResolver(
            new ProcessRunner([$youtubeBinary], $privateRoot),
            new DirectUrlValidator(),
            $youtubeBinary,
            (int) ($media['yt_dlp_timeout_seconds'] ?? 60),
            (int) ($media['yt_dlp_output_limit_bytes'] ?? 1048576),
            is_string($media['yt_dlp_js_runtime'] ?? null) ? $media['yt_dlp_js_runtime'] : null,
            (bool) ($media['yt_dlp_force_ipv4'] ?? true),
            static function (array $context) use ($systemLogs): void {
                $systemLogs->tryRecord(
                    ($context['result_code'] ?? '') === 'metadata_validated' ? 'info' : 'warning',
                    'youtube.import_diagnostic',
                    $context
                );
            },
            is_string($media['yt_dlp_cookies_file'] ?? null) ? $media['yt_dlp_cookies_file'] : null
        );
    }
    $mediaProcessor = new LocalFfprobeProcessor(
        $storage,
        $runner,
        $ffprobe,
        $processTimeoutSeconds,
        (int) ($media['process_output_limit_bytes'] ?? 1048576)
    );
    $renderMaxBytes = (int) ($media['render_max_output_bytes'] ?? 524288000);
    $renderMaxDurationSeconds = (int) ($media['render_max_duration_seconds'] ?? 180);
    $renderThumbnailMaxBytes = (int) ($media['render_thumbnail_max_bytes'] ?? 10485760);
    $editorLibrary = new \App\Repositories\EditorLibraryRepository($pdo);
    $renderer = new LocalFfmpegClipRenderer(
        $storage,
        $runner,
        $ffmpeg,
        $privateRoot,
        $renderTimeoutSeconds,
        (int) ($media['process_output_limit_bytes'] ?? 1048576),
        $renderMaxBytes,
        $renderThumbnailMaxBytes,
        null,
        [$editorLibrary, 'resolveLogoForProject'],
        $ffprobe
    );
    $effects = new LeaseProcessingEffectGuard($pdo);
    $cleanups = new RenderArtifactCleanupRepository($pdo);
    $sourceCleanups = new \App\Repositories\SourceArtifactCleanupRepository($pdo);
    $maxAttempts = (int) ($queueConfig['max_attempts'] ?? 3);
    $jobs = new DatabaseJobDispatcher($pdo, 'media', $maxAttempts);
    $reservations = new CreditReservationRepository($pdo);
    $credits = new CreditReservationService(
        $pdo,
        $reservations,
        new CreditTransactionRepository($pdo),
        (int) ($gemini['credits_per_minute'] ?? 1)
    );
    $analyses = new AiAnalysisRepository($pdo);
    $clips = new ClipRepository($pdo);
    $profiles = new ClipRenderProfileRepository($pdo);
    $editor = new \App\Repositories\ClipEditorRepository($pdo);
    $quotas = new \App\Services\PlanQuotaService($pdo);
    $audioExtractor = new \App\Media\LocalFfmpegAudioExtractor(
        $storage, $runner, $ffmpeg, $privateRoot, $processTimeoutSeconds,
        (int) ($media['process_output_limit_bytes'] ?? 1048576)
    );
    $transcriber = new \App\Services\GeminiTimedTranscriber(
        new CurlGeminiTransport(),
        (string) ($gemini['api_key'] ?? ''),
        (string) ($gemini['model'] ?? ''),
        $httpTimeoutSeconds,
        (int) ($gemini['response_limit_bytes'] ?? 1048576)
    );
    $prompt = new ViralClipPrompt();
    $validator = new AnalysisResponseValidator();
    $configuredModel = (string) ($gemini['model'] ?? '');
    $analysisModel = trim($configuredModel) === '' ? 'unconfigured' : $configuredModel;
    $provider = new \App\Services\OpenAiVideoAnalysisService(
        new CurlGeminiTransport(),
        $storage,
        (string) ($gemini['api_key'] ?? ''),
        $configuredModel,
        (string) ($gemini['base_url'] ?? 'https://api.openai.com/v1'),
        $httpTimeoutSeconds,
        (int) ($gemini['response_limit_bytes'] ?? 1048576)
    );
    $aiPipeline = new AiPipelineStarter(
        $pdo,
        $projects,
        $sources,
        $analyses,
        $credits,
        new DatabaseJobDispatcher($pdo, 'media', max(1, min(8, (int) ($gemini['analysis_max_attempts'] ?? 6)))),
        'opusclip-v1',
        'opusclip',
        $quotas
    );
    $sourceDurations = new \App\Services\SourceDurationPreflight($pdo,$sources,$mediaProcessor);
    $renderRequests = new \App\Services\ClipRenderRequestService(
        $pdo, $clips, $projects, $profiles,
        new \App\Media\Reframe\ReframePlanValidator(
            (int) ($media['reframe_max_duration_seconds'] ?? 90),
            (int) ($media['reframe_max_keyframes'] ?? 32)
        ),
        $jobs, $renderMaxDurationSeconds, $sourceDurations
    );
    $automaticExports = new \App\Services\AutoRenderScheduler($pdo,
        static fn (int $clipId,int $userId,string $start,string $end,\App\Media\PreciseSourceDuration $snapshot)
            => $renderRequests->request($clipId,$userId,$start,$end,null,$snapshot),
        3,$sourceDurations);
    $thumbnailRepository = new \App\Repositories\ThumbnailRepository($pdo);
    $thumbnailGenerator = new \App\Media\Thumbnails\LocalThumbnailGenerator(
        $storage, $runner, $ffmpeg, $privateRoot, [$editorLibrary, 'resolveLogoForProject']
    );
    $thumbnailCleanups = new \App\Repositories\ThumbnailArtifactCleanupRepository($pdo);
    /** @var array<string, JobHandler> $handlers */
    $handlers = [
        'generate_thumbnail_candidates' => new \App\Queue\GenerateThumbnailCandidatesHandler(
            $thumbnailRepository, $thumbnailGenerator, $storage, $effects, $thumbnailCleanups,
            [$quotas, 'assertAdditionalStorageAvailable']
        ),
        'render_thumbnail_design' => new \App\Queue\RenderThumbnailDesignHandler(
            $thumbnailRepository, $thumbnailGenerator, $storage, $effects, $thumbnailCleanups,
            [$quotas, 'assertAdditionalStorageAvailable']
        ),
        'generate_subtitles' => new \App\Queue\GenerateSubtitlesHandler(
            $editor, $clips, $projects, $audioExtractor, $transcriber, $effects, $jobs
        ),
        'probe_source' => new ProbeSourceHandler($projects, $sources, $mediaProcessor, $effects, $aiPipeline),
        'fetch_and_probe' => new FetchAndProbeHandler($projects, $sources, $downloader, $storage, $mediaProcessor, $effects, $maxBytes, $aiPipeline, $quotas, $sourceCleanups, $youtubeResolver, new \App\Media\YoutubeUrlValidator()),
        'analyze_video' => new AnalyzeVideoHandler(
            $analyses,
            $sources,
            $projects,
            $reservations,
            $credits,
            $jobs,
            $provider,
            $prompt,
            $validator,
            $effects,
            (int) ($gemini['file_poll_seconds'] ?? 15),
            (int) ($gemini['validation_attempts'] ?? 2),
            static function (array $event) use ($systemLogs): void {
                $systemLogs->tryRecord('warning', 'ai.validation_rejected', $event, null, 'job', $event['job_id']);
            },
            static function (array $event) use ($systemLogs): void {
                $systemLogs->tryRecord('warning', 'ai.provider_failure', $event, null, 'job', $event['job_id']);
            }
        ),
        'opusclip_process' => new OpusClipProcessHandler(
            $pdo,
            $analyses,
            $sources,
            $storage,
            new OpusClipClient((string) \App\Core\Env::get('OPUSCLIP_API_KEY', '')),
            $effects,
            $credits,
            static function (array $event) use ($systemLogs): void {
                $systemLogs->tryRecord('info', 'opusclip.raw_clip', $event);
            }
        ),
        'generate_clips' => new GenerateClipsHandler(
            $analyses,
            $clips,
            $projects,
            $reservations,
            $credits,
            $validator,
            $effects,
            [$automaticExports, 'schedule'],
            [$automaticExports, 'prepare']
        ),
        'render_clip' => new RenderClipHandler(
            $clips,
            $projects,
            $renderer,
            $storage,
            $effects,
            $renderMaxBytes,
            $renderThumbnailMaxBytes,
            $cleanups,
            $profiles,
            $renderMaxDurationSeconds,
            $editor,
            static function (int $clipId, int $bytes) use ($editor, $quotas): void {
                $ownerId = $editor->ownerId($clipId);
                if ($ownerId === null) {
                    throw new RuntimeException('Render owner no longer exists.');
                }
                $quotas->assertAdditionalStorageAvailable($ownerId, $bytes);
            }
        ),
    ];
    $maintenance = new \App\Services\CompositeWorkerMaintenance([
        new RenderMaintenance($cleanups, $storage, $privateRoot, max(60, $requiredLease)),
        new \App\Services\SourceMaintenance($sourceCleanups, $storage),
    ]);
    $worker = new QueueWorker(
        new ProcessingJobRepository($pdo),
        $handlers,
        $workerId,
        max(1, $leaseSeconds),
        null,
        $maintenance,
        static function (array $event) use ($systemLogs): void {
            $level = $event['status'] === 'failed' ? 'error' : ($event['status'] === 'retry' ? 'warning' : 'info');
            $systemLogs->tryRecord($level, 'queue.' . $event['status'], $event, null, 'job', $event['job_id']);
        }
    );
    $report = $worker->run($arguments['queue'], $arguments['limit'], $arguments['time_budget']);
    echo json_encode([
        'claimed' => $report->claimed,
        'completed' => $report->completed,
        'retried' => $report->retried,
        'deferred' => $report->deferred,
        'failed' => $report->failed,
        'operational_errors' => $report->operationalErrors,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($report->operationalErrors > 0 ? 1 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Falha ao iniciar o worker.\n");
    fwrite(STDERR, "[DIAGNOSTICO] " . get_class($exception) . ': ' . $exception->getMessage() . "\n");
    fwrite(STDERR, $exception->getTraceAsString() . "\n");
    exit(1);
}
