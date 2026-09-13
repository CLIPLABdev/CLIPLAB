<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Ai\AnalysisResponseValidator;
use App\Ai\ViralClipPrompt;
use App\Contracts\VideoAnalysisProvider;
use App\Core\Migrator;
use App\Gemini\GeminiFile;
use App\Gemini\GeminiHttpResponse;
use App\Gemini\GeminiTransport;
use App\Media\ProjectSource;
use App\Queue\AnalyzeVideoHandler;
use App\Queue\GenerateClipsHandler;
use App\Queue\LeaseProcessingEffectGuard;
use App\Repositories\AiAnalysisRepository;
use App\Repositories\ClipRepository;
use App\Repositories\CreditReservationRepository;
use App\Repositories\CreditTransactionRepository;
use App\Repositories\ProcessingJobRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\ProjectSourceRepository;
use App\Services\AiPipelineStarter;
use App\Services\CreditReservationService;
use App\Services\DatabaseJobDispatcher;
use App\Services\GeminiService;
use App\Services\QueueWorker;
use App\Storage\LocalPrivateStorage;
use PDO;
use PHPUnit\Framework\TestCase;

final class AiProjectWorkflowTest extends TestCase
{
    private PDO $pdo;
    private int $planId;
    private int $userId;
    private int $projectId;
    private int $sourceId;
    private string $queue;

    protected function setUp(): void
    {
        $dsn = getenv('TEST_DB_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('TEST_DB_DSN is not configured.');
        }
        $this->pdo = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec("SET time_zone = '+00:00'");
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();
        $suffix = bin2hex(random_bytes(7));
        $this->queue = 'ai-flow-' . $suffix;
        $this->pdo->prepare('INSERT INTO plans (slug, name, features) VALUES (?, ?, JSON_OBJECT())')
            ->execute(['ai-flow-' . $suffix, 'AI flow ' . $suffix]);
        $this->planId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id, credits) VALUES (?, ?, ?, ?, 10)')
            ->execute(['AI flow', 'ai-flow-' . $suffix . '@example.test', 'x', $this->planId]);
        $this->userId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO credit_transactions (user_id, type, amount, balance_after, reference_type, description) VALUES (?, 'credit', 10, 10, 'test_fixture', 'AI workflow credits')")
            ->execute([$this->userId]);
        $this->pdo->prepare("INSERT INTO projects (user_id, ingest_key, name, status, progress) VALUES (?, ?, 'AI workflow', 'ready', 70)")
            ->execute([$this->userId, hash('sha256', 'ai-flow-project-' . $suffix)]);
        $this->projectId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO project_sources (project_id, source_type, storage_disk, object_key, original_name, extension, mime_type, size_bytes, duration_seconds, width, height, video_codec, audio_codec, has_audio, status) VALUES (?, 'upload', 'local', ?, 'workflow.mp4', 'mp4', 'video/mp4', 1024, 121, 1280, 720, 'h264', 'aac', 1, 'ready')")
            ->execute([$this->projectId, 'imports/' . $this->projectId . '/workflow.mp4']);
        $this->sourceId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        $this->pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$this->projectId]);
        $this->pdo->prepare('DELETE FROM credit_transactions WHERE user_id = ?')->execute([$this->userId]);
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
        $this->pdo->prepare('DELETE FROM plans WHERE id = ?')->execute([$this->planId]);
    }

    public function testReadySourceBecomesSuggestionsAndConsumesOneReservationWithoutNetwork(): void
    {
        $projects = new ProjectRepository($this->pdo);
        $sources = new ProjectSourceRepository($this->pdo);
        $analyses = new AiAnalysisRepository($this->pdo);
        $clips = new ClipRepository($this->pdo);
        $reservations = new CreditReservationRepository($this->pdo);
        $transactions = new CreditTransactionRepository($this->pdo);
        $credits = new CreditReservationService($this->pdo, $reservations, $transactions, 1);
        $jobs = new DatabaseJobDispatcher($this->pdo, $this->queue, 3);
        $effects = new LeaseProcessingEffectGuard($this->pdo);
        $prompt = new ViralClipPrompt();
        $validator = new AnalysisResponseValidator();
        $provider = new WorkflowVideoProvider($this->validJson());
        $receipt = (new AiPipelineStarter(
            $this->pdo,
            $projects,
            $sources,
            $analyses,
            $credits,
            $jobs,
            ViralClipPrompt::VERSION,
            'fake-model'
        ))->schedule($this->projectId, $this->sourceId, 121);
        self::assertNotNull($receipt->reservationId());

        $worker = new QueueWorker(
            new ProcessingJobRepository($this->pdo),
            [
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
                    5,
                    2
                ),
                'generate_clips' => new GenerateClipsHandler(
                    $analyses,
                    $clips,
                    $projects,
                    $reservations,
                    $credits,
                    $validator,
                    $effects
                ),
            ],
            'workflow-worker-' . bin2hex(random_bytes(4)),
            300
        );

        $claimed = 0;
        for ($pass = 0; $pass < 8; ++$pass) {
            $this->pdo->prepare("UPDATE processing_jobs SET available_at = UTC_TIMESTAMP() WHERE project_id = ? AND queue_name = ? AND status = 'retry'")
                ->execute([$this->projectId, $this->queue]);
            $report = $worker->run($this->queue, 1, 5);
            $claimed += $report->claimed;
            self::assertSame(0, $report->operationalErrors);
            if ($report->claimed === 0) {
                break;
            }
        }

        self::assertSame(5, $claimed);
        self::assertSame(['upload', 'get', 'generate', 'delete'], $provider->operations);
        self::assertSame('suggestions_ready', $this->scalar('SELECT status FROM projects WHERE id = ?', [$this->projectId]));
        self::assertSame('completed', $this->scalar('SELECT status FROM ai_analyses WHERE id = ?', [$receipt->analysisId()]));
        self::assertSame('consumed', $this->scalar('SELECT status FROM credit_reservations WHERE id = ?', [$receipt->reservationId()]));
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM clips WHERE ai_analysis_id = ?', [$receipt->analysisId()]));
        self::assertSame(7, (int) $this->scalar('SELECT credits FROM users WHERE id = ?', [$this->userId]));
        self::assertSame(2, (int) $this->scalar("SELECT COUNT(*) FROM processing_jobs WHERE project_id = ? AND queue_name = ? AND status = 'completed'", [$this->projectId, $this->queue]));
    }

    public function testMissingGeminiConfigurationRefundsWithoutCallingTransport(): void
    {
        $projects = new ProjectRepository($this->pdo);
        $sources = new ProjectSourceRepository($this->pdo);
        $analyses = new AiAnalysisRepository($this->pdo);
        $reservations = new CreditReservationRepository($this->pdo);
        $credits = new CreditReservationService($this->pdo, $reservations, new CreditTransactionRepository($this->pdo), 1);
        $jobs = new DatabaseJobDispatcher($this->pdo, $this->queue, 3);
        $receipt = (new AiPipelineStarter(
            $this->pdo,
            $projects,
            $sources,
            $analyses,
            $credits,
            $jobs,
            ViralClipPrompt::VERSION,
            'unconfigured'
        ))->schedule($this->projectId, $this->sourceId, 121);
        $transport = new WorkflowNoNetworkTransport();
        $root = sys_get_temp_dir() . '/clipforge-ai-unconfigured-' . bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        try {
            $provider = new GeminiService(
                $transport,
                new LocalPrivateStorage($root, 1024),
                '',
                '',
                'https://generativelanguage.googleapis.com',
                30,
                16384
            );
            $worker = new QueueWorker(
                new ProcessingJobRepository($this->pdo),
                ['analyze_video' => new AnalyzeVideoHandler(
                    $analyses,
                    $sources,
                    $projects,
                    $reservations,
                    $credits,
                    $jobs,
                    $provider,
                    new ViralClipPrompt(),
                    new AnalysisResponseValidator(),
                    new LeaseProcessingEffectGuard($this->pdo),
                    5,
                    2
                )],
                'unconfigured-worker-' . bin2hex(random_bytes(4)),
                300
            );

            $report = $worker->run($this->queue, 1, 5);

            self::assertSame(1, $report->claimed);
            self::assertSame(1, $report->failed);
            self::assertSame(0, $report->operationalErrors);
            self::assertSame(0, $transport->calls);
            self::assertSame('refunded', $this->scalar('SELECT status FROM credit_reservations WHERE id = ?', [$receipt->reservationId()]));
            self::assertSame('failed', $this->scalar('SELECT status FROM ai_analyses WHERE id = ?', [$receipt->analysisId()]));
            self::assertSame('failed', $this->scalar('SELECT status FROM projects WHERE id = ?', [$this->projectId]));
            self::assertSame('ai_unconfigured', $this->scalar('SELECT error_code FROM projects WHERE id = ?', [$this->projectId]));
            self::assertSame(10, (int) $this->scalar('SELECT credits FROM users WHERE id = ?', [$this->userId]));
        } finally {
            @rmdir($root);
        }
    }

    /** @param list<mixed> $parameters */
    private function scalar(string $sql, array $parameters): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn();
    }

    private function validJson(): string
    {
        return '{"video_summary":"Resumo completo e seguro","clips":[{"title":"Primeiro corte","start_time":0,"end_time":30,"duration":30,"score":94,"reason":"Entrega valor rapidamente.","hook":"Abertura direta.","category":"insight"},{"title":"Segundo corte","start_time":40,"end_time":70,"duration":30,"score":88,"reason":"História com conclusão.","hook":"Pergunta forte.","category":"story"}]}';
    }
}

final class WorkflowVideoProvider implements VideoAnalysisProvider
{
    /** @var list<string> */
    public array $operations = [];

    public function __construct(private string $response)
    {
    }

    public function upload(ProjectSource $source): GeminiFile
    {
        $this->operations[] = 'upload';

        return new GeminiFile('files/workflow', 'https://generativelanguage.googleapis.com/v1beta/files/workflow', $source->mimeType(), 'PROCESSING');
    }

    public function getFile(string $resourceName): GeminiFile
    {
        $this->operations[] = 'get';

        return new GeminiFile($resourceName, 'https://generativelanguage.googleapis.com/v1beta/files/workflow', 'video/mp4', 'ACTIVE');
    }

    public function generate(GeminiFile $file, string $prompt, array $responseSchema): string
    {
        $this->operations[] = 'generate';

        return $this->response;
    }

    public function deleteFile(string $resourceName): void
    {
        $this->operations[] = 'delete';
    }
}

final class WorkflowNoNetworkTransport implements GeminiTransport
{
    public int $calls = 0;

    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeoutSeconds,
        int $responseLimitBytes
    ): GeminiHttpResponse {
        ++$this->calls;
        throw new \LogicException('The transport must not be called without configuration.');
    }

    public function upload(
        string $url,
        array $headers,
        string $absolutePath,
        int $sizeBytes,
        int $timeoutSeconds,
        int $responseLimitBytes
    ): GeminiHttpResponse {
        ++$this->calls;
        throw new \LogicException('The transport must not be called without configuration.');
    }
}
