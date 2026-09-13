<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Queue\ClaimedJob;
use App\Queue\ProcessingErrorCatalog;
use App\Repositories\ProcessingJobRepository;
use PDO;
use PHPUnit\Framework\TestCase;
final class ThumbnailQueueContractTest extends TestCase
{
    /** @dataProvider failures */
    public function testThumbnailFailureCanBePersistedByTheRealQueueRepository(string $code, string $message): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => '2026-09-06 12:00:00');
        $pdo->exec('CREATE TABLE processing_jobs (id INTEGER PRIMARY KEY,status TEXT,finished_at TEXT,last_error_code TEXT,last_error_message TEXT,worker_id TEXT,lease_token_hash TEXT,leased_until TEXT)');
        $pdo->prepare('INSERT INTO processing_jobs VALUES (1,?,NULL,NULL,NULL,?,?,?)')
            ->execute(['running','test-worker',hash('sha256','test-lease'),'2026-09-06 12:10:00']);
        $job = new ClaimedJob(1,'media','generate_thumbnail_candidates',10,[],'test-worker','test-lease',1,3);
        self::assertSame($message, ProcessingErrorCatalog::requireMessage($code));
        self::assertTrue((new ProcessingJobRepository($pdo))->fail($job,$code,$message));
        self::assertSame('failed',$pdo->query('SELECT status FROM processing_jobs WHERE id=1')->fetchColumn());
        self::assertSame($code,$pdo->query('SELECT last_error_code FROM processing_jobs WHERE id=1')->fetchColumn());
    }
    public static function failures(): iterable
    {
        yield ['thumbnail_failed','Não foi possível gerar a capa. Revise o vídeo e tente uma nova variante.'];
        yield ['thumbnail_request_invalid','Pedido de capa inválido.'];
    }
}
