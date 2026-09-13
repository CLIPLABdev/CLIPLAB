<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Ai\AnalysisResponseValidator;
use App\Ai\ViralClipPrompt;
use App\Contracts\VideoAnalysisProvider;
use App\Core\Migrator;
use App\Gemini\GeminiFile;
use App\Media\ProjectSource;
use App\Queue\AnalyzeVideoHandler;
use App\Queue\LeaseProcessingEffectGuard;
use App\Repositories\AiAnalysisRepository;
use App\Repositories\CreditReservationRepository;
use App\Repositories\CreditTransactionRepository;
use App\Repositories\ProcessingJobRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\ProjectSourceRepository;
use App\Services\AiPipelineStarter;
use App\Services\CreditReservationService;
use App\Services\DatabaseJobDispatcher;
use Closure;
use PDO;
use PHPUnit\Framework\TestCase;

final class AnalyzeVideoLeaseFencingTest extends TestCase
{
    private PDO $pdo;
    private int $planId;
    private int $userId;
    private int $projectId;
    private int $sourceId;
    private CreditReservationService $credits;

    protected function setUp(): void
    {
        $dsn = getenv('TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('TEST_DB_DSN is not configured.');
        }
        $this->pdo = $this->connection();
        $this->pdo->exec("SET time_zone = '+00:00'");
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();
        [$this->planId, $this->userId, $this->projectId, $this->sourceId] = $this->fixture();
        $this->credits = new CreditReservationService(
            $this->pdo,
            new CreditReservationRepository($this->pdo),
            new CreditTransactionRepository($this->pdo),
            1
        );
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

    public function testExpiredLeaseBeforePreflightPerformsNoProviderOrCreditEffect(): void
    {
        [$job, $reservationId] = $this->job();
        $this->expireLease($job->id());
        $provider = new LeaseFencingVideoProvider(static function (): void {
        });

        $outcome = $this->handler($provider)->handle($job);

        self::assertSame('deferred', $outcome->status());
        self::assertSame(0, $provider->uploadCalls);
        self::assertSame('reserved', $this->scalar('SELECT status FROM credit_reservations WHERE id = ?', [$reservationId]));
        self::assertSame('queued', $this->scalar('SELECT status FROM ai_analyses WHERE project_id = ?', [$this->projectId]));
    }

    public function testProviderCanExpireLeaseOutsideTransactionAndPostCheckpointIsFenced(): void
    {
        [$job, $reservationId] = $this->job();
        $provider = new LeaseFencingVideoProvider(function () use ($job): void {
            $other = $this->connection();
            $statement = $other->prepare('UPDATE processing_jobs SET leased_until = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = ?');
            $statement->execute([$job->id()]);
        });

        $outcome = $this->handler($provider)->handle($job);

        self::assertSame('deferred', $outcome->status());
        self::assertSame(1, $provider->uploadCalls);
        self::assertSame('reserved', $this->scalar('SELECT status FROM credit_reservations WHERE id = ?', [$reservationId]));
        $analysis = $this->row('SELECT status, gemini_file_name FROM ai_analyses WHERE project_id = ?', [$this->projectId]);
        self::assertSame('uploading', $analysis['status']);
        self::assertNull($analysis['gemini_file_name']);
    }

    private function handler(VideoAnalysisProvider $provider): AnalyzeVideoHandler
    {
        return new AnalyzeVideoHandler(
            new AiAnalysisRepository($this->pdo),
            new ProjectSourceRepository($this->pdo),
            new ProjectRepository($this->pdo),
            new CreditReservationRepository($this->pdo),
            $this->credits,
            new DatabaseJobDispatcher($this->pdo, 'media', 3),
            $provider,
            new ViralClipPrompt(),
            new AnalysisResponseValidator(),
            new LeaseProcessingEffectGuard($this->pdo),
            15,
            2
        );
    }

    /** @return array{\App\Queue\ClaimedJob, int} */
    private function job(): array
    {
        $starter = new AiPipelineStarter(
            $this->pdo,
            new ProjectRepository($this->pdo),
            new ProjectSourceRepository($this->pdo),
            new AiAnalysisRepository($this->pdo),
            $this->credits,
            new DatabaseJobDispatcher($this->pdo, 'media', 3),
            ViralClipPrompt::VERSION,
            'gemini-2.5-flash'
        );
        $receipt = $starter->schedule($this->projectId, $this->sourceId, 126);
        self::assertNotNull($receipt->reservationId());
        $job = (new ProcessingJobRepository($this->pdo))->claimNext('media', 'lease-fencing-worker', 120);
        self::assertNotNull($job);

        return [$job, (int) $receipt->reservationId()];
    }

    private function expireLease(int $jobId): void
    {
        $statement = $this->pdo->prepare('UPDATE processing_jobs SET leased_until = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = ?');
        $statement->execute([$jobId]);
    }

    /** @return array{int, int, int, int} */
    private function fixture(): array
    {
        $suffix = bin2hex(random_bytes(8));
        $this->pdo->prepare('INSERT INTO plans (slug, name, features) VALUES (?, ?, JSON_OBJECT())')->execute(['lease-ai-' . $suffix, 'Lease AI ' . $suffix]);
        $planId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id, credits) VALUES (?, ?, ?, ?, 10)')->execute(['Lease AI fixture', 'lease-ai-' . $suffix . '@example.test', 'not-a-real-hash', $planId]);
        $userId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO credit_transactions (user_id, type, amount, balance_after, reference_type, description) VALUES (?, 'credit', 10, 10, 'test_fixture', 'Lease AI credits')")->execute([$userId]);
        $this->pdo->prepare("INSERT INTO projects (user_id, name, status, progress) VALUES (?, 'Lease AI project', 'ready', 70)")->execute([$userId]);
        $projectId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO project_sources (project_id, source_type, storage_disk, object_key, extension, mime_type, size_bytes, duration_seconds, width, height, video_codec, has_audio, status) VALUES (?, 'upload', 'local', ?, 'mp4', 'video/mp4', 1000, 126, 1920, 1080, 'h264', 0, 'ready')")->execute([$projectId, 'imports/' . $projectId . '/video.mp4']);

        return [$planId, $userId, $projectId, (int) $this->pdo->lastInsertId()];
    }

    private function connection(): PDO
    {
        return new PDO(
            (string) getenv('TEST_DB_DSN'),
            getenv('TEST_DB_USERNAME') ?: null,
            getenv('TEST_DB_PASSWORD') ?: null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params)
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }

    /** @param list<mixed> $params @return array<string, mixed> */
    private function row(string $sql, array $params): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }
}

final class LeaseFencingVideoProvider implements VideoAnalysisProvider
{
    public int $uploadCalls = 0;
    private Closure $duringUpload;

    public function __construct(callable $duringUpload)
    {
        $this->duringUpload = Closure::fromCallable($duringUpload);
    }

    public function upload(ProjectSource $source): GeminiFile
    {
        ++$this->uploadCalls;
        ($this->duringUpload)();

        return new GeminiFile(
            'files/lease-video',
            'https://generativelanguage.googleapis.com/v1beta/files/lease-video',
            'video/mp4',
            'PROCESSING'
        );
    }

    public function getFile(string $resourceName): GeminiFile
    {
        throw new \LogicException('Not used.');
    }

    public function generate(GeminiFile $file, string $prompt, array $responseSchema): string
    {
        throw new \LogicException('Not used.');
    }

    public function deleteFile(string $resourceName): void
    {
        throw new \LogicException('Not used.');
    }
}
