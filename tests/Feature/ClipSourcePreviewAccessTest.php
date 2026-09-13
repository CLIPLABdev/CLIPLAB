<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\PrivateStorage;
use App\Controllers\ClipSourcePreviewController;
use App\Core\Migrator;
use App\Core\Request;
use App\Media\StoredObject;
use App\Repositories\ClipRepository;
use App\Services\PrivateRangeResponseFactory;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SeekFailingPrivatePreviewStream
{
    public $context;
    public static int $opens = 0;
    public static int $closes = 0;
    public static int $size = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        ++self::$opens;

        return true;
    }

    /** @return array<int|string, int> */
    public function stream_stat(): array
    {
        $stat = array_fill(0, 13, 0);
        $stat[2] = 0100644;
        $stat[7] = self::$size;
        $stat['mode'] = 0100644;
        $stat['size'] = self::$size;

        return $stat;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return false;
    }

    public function stream_close(): void
    {
        ++self::$closes;
    }
}

final class ClipSourcePreviewAccessTest extends TestCase
{
    private string $file;
    private ?PDO $pdo = null;
    /** @var list<int> */
    private array $databaseUserIds = [];

    protected function setUp(): void
    {
        $_SESSION = ['user_id' => 7];
        $file = tempnam(sys_get_temp_dir(), 'source-preview-access-');
        self::assertIsString($file);
        $this->file = $file;
        file_put_contents($this->file, 'owner-private-source');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if ($this->pdo !== null && $this->databaseUserIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->databaseUserIds), '?'));
            $this->pdo->prepare('DELETE FROM users WHERE id IN (' . $placeholders . ')')->execute($this->databaseUserIds);
        }
        if (isset($this->file) && is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testGuestIsRedirectedBeforePreviewLookup(): void
    {
        $lookups = 0;
        $controller = new ClipSourcePreviewController(
            static function () use (&$lookups): ?array {
                ++$lookups;

                return null;
            },
            $this->storage(fn (): string => $this->file),
            new PrivateRangeResponseFactory()
        );
        $_SESSION = [];

        $response = $controller->show(Request::fake('GET', '/clips/71/source-preview'), ['id' => '71']);

        self::assertSame(302, $response->status());
        self::assertSame('/login', $response->header('Location'));
        self::assertSame(0, $lookups);
    }

    public function testOwnerCanPreviewAReadyLocalSource(): void
    {
        $lookups = [];
        $keys = [];
        $controller = new ClipSourcePreviewController(
            static function (int $clipId, int $userId) use (&$lookups): array {
                $lookups[] = [$clipId, $userId];

                return [
                    'storage_disk' => 'local',
                    'object_key' => 'sources/31/private.mp4',
                    'size_bytes' => strlen('owner-private-source'),
                    'mime_type' => 'application/mp4',
                ];
            },
            $this->storage(function (string $key) use (&$keys): string {
                $keys[] = $key;

                return $this->file;
            }),
            new PrivateRangeResponseFactory()
        );

        $response = $controller->show(Request::fake('GET', '/clips/71/source-preview'), ['id' => '71']);

        self::assertSame(200, $response->status());
        self::assertSame('video/mp4', $response->header('Content-Type'));
        self::assertSame([[71, 7]], $lookups);
        self::assertSame(['sources/31/private.mp4'], $keys);
        self::assertSame('owner-private-source', $this->send($response));
    }

    public function testForeignMissingMalformedMetadataAndPreflightFailuresShareOne404(): void
    {
        $privateKey = 'sources/31/private-secret.mp4';
        $secretDiagnostic = 'storage secret diagnostic';
        $valid = [
            'storage_disk' => 'local',
            'object_key' => $privateKey,
            'size_bytes' => strlen('owner-private-source'),
            'mime_type' => 'video/mp4',
        ];
        $reference = $this->controllerFor(null, $this->storage(fn (): string => $this->file))
            ->show(Request::fake('GET', '/clips/71/source-preview'), ['id' => '71']);
        $cases = [
            [array_replace($valid, ['storage_disk' => 's3']), $this->storage(fn (): string => $this->file)],
            [array_replace($valid, ['object_key' => '']), $this->storage(fn (): string => $this->file)],
            [array_replace($valid, ['object_key' => 123]), $this->storage(fn (): string => $this->file)],
            [array_replace($valid, ['size_bytes' => 0]), $this->storage(fn (): string => $this->file)],
            [array_replace($valid, ['size_bytes' => (string) strlen('owner-private-source')]), $this->storage(fn (): string => $this->file)],
            [array_replace($valid, ['mime_type' => 'image/jpeg']), $this->storage(fn (): string => $this->file)],
            [$valid, $this->storage(static fn (): string => 'relative/private.mp4')],
            [$valid, $this->storage(fn (): string => $this->file . '.missing')],
            [array_replace($valid, ['size_bytes' => strlen('owner-private-source') + 1]), $this->storage(fn (): string => $this->file)],
            [$valid, $this->storage(static function () use ($secretDiagnostic): string {
                throw new RuntimeException($secretDiagnostic);
            })],
        ];

        $responses = [$reference];
        foreach ($cases as [$source, $storage]) {
            $responses[] = $this->controllerFor($source, $storage)
                ->show(Request::fake('GET', '/clips/71/source-preview'), ['id' => '71']);
        }
        foreach (['', '01', '0', '-1', '999999999999999999999999'] as $id) {
            $responses[] = $this->controllerFor($valid, $this->storage(fn (): string => $this->file))
                ->show(Request::fake('GET', '/clips/' . $id . '/source-preview'), ['id' => $id]);
        }

        foreach ($responses as $response) {
            self::assertSame(404, $response->status());
            self::assertSame($reference->body(), $response->body());
            self::assertStringNotContainsString($privateKey, $response->body());
            self::assertStringNotContainsString($this->file, $response->body());
            self::assertStringNotContainsString($secretDiagnostic, $response->body());
        }
    }

    public function testMalformedRangeWithSeekFailingHandleMapsToStandard404AndClosesHandle(): void
    {
        $scheme = 'seekfailpreview';
        self::assertNotContains($scheme, stream_get_wrappers());
        SeekFailingPrivatePreviewStream::$opens = 0;
        SeekFailingPrivatePreviewStream::$closes = 0;
        SeekFailingPrivatePreviewStream::$size = strlen('owner-private-source');
        self::assertTrue(stream_wrapper_register($scheme, SeekFailingPrivatePreviewStream::class));
        $opened = null;
        $factory = new PrivateRangeResponseFactory(static function () use ($scheme, &$opened) {
            $opened = fopen($scheme . '://source', 'rb');

            return $opened;
        });
        $validSource = [
            'storage_disk' => 'local',
            'object_key' => 'sources/31/private-secret.mp4',
            'size_bytes' => strlen('owner-private-source'),
            'mime_type' => 'video/mp4',
        ];
        $reference = $this->controllerFor(null, $this->storage(fn (): string => $this->file))
            ->show(Request::fake('GET', '/clips/71/source-preview'), ['id' => '71']);

        try {
            $response = (new ClipSourcePreviewController(
                static fn (int $clipId, int $userId): array => $validSource,
                $this->storage(fn (): string => $this->file),
                $factory
            ))->show(
                Request::fake('GET', '/clips/71/source-preview', [], ['Range' => 'bytes=0-1,4-5']),
                ['id' => '71']
            );
        } finally {
            stream_wrapper_unregister($scheme);
        }

        self::assertSame(404, $response->status());
        self::assertSame($reference->body(), $response->body());
        self::assertStringNotContainsString($this->file, $response->body());
        self::assertStringNotContainsString($scheme, $response->body());
        self::assertSame(1, SeekFailingPrivatePreviewStream::$opens);
        self::assertSame(1, SeekFailingPrivatePreviewStream::$closes);
        self::assertFalse(is_resource($opened));
    }

    public function testRepositoryLimitsPreviewToOwnerCurrentAnalysisAndReadySource(): void
    {
        $dsn = getenv('TEST_DB_DSN');
        self::assertIsString($dsn, 'TEST_DB_DSN must target the isolated Task 4 test database.');
        self::assertNotSame('', $dsn, 'TEST_DB_DSN must target the isolated Task 4 test database.');
        self::assertSame(
            1,
            preg_match('/(?:^|;)dbname=clipforge_phase5_test(?:;|$)/D', $dsn),
            'TEST_DB_DSN must target clipforge_phase5_test.'
        );
        $this->pdo = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $base = dirname(__DIR__, 2);
        (new Migrator($this->pdo, $base . '/database/migrations'))->run();
        $this->pdo->exec((string) file_get_contents($base . '/database/seeds/plans.sql'));
        $planId = (int) $this->pdo->query("SELECT id FROM plans WHERE slug = 'free'")->fetchColumn();
        $ownerId = $this->databaseUser($planId, 'preview-owner');
        $otherId = $this->databaseUser($planId, 'preview-other');
        $this->pdo->prepare("INSERT INTO projects (user_id, ingest_key, name, status) VALUES (?, ?, 'Preview source project', 'suggestions_ready')")
            ->execute([$ownerId, hash('sha256', random_bytes(16))]);
        $projectId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO project_sources (project_id, source_type, storage_disk, object_key, mime_type, size_bytes, status) VALUES (?, 'upload', 'local', 'sources/private.webm', 'video/webm', 321, 'ready')")
            ->execute([$projectId]);
        $oldAnalysisId = $this->analysis($projectId, 'preview-old');
        $staleClipId = $this->clip($projectId, $oldAnalysisId, 0);
        $currentAnalysisId = $this->analysis($projectId, 'preview-current');
        $currentClipId = $this->clip($projectId, $currentAnalysisId, 0);
        $repository = new ClipRepository($this->pdo);

        self::assertSame([
            'storage_disk' => 'local',
            'object_key' => 'sources/private.webm',
            'size_bytes' => 321,
            'mime_type' => 'video/webm',
        ], $repository->sourceForOwnedPreview($currentClipId, $ownerId));
        self::assertNull($repository->sourceForOwnedPreview($currentClipId, $otherId));
        self::assertNull($repository->sourceForOwnedPreview(999999999, $ownerId));
        self::assertNull($repository->sourceForOwnedPreview($staleClipId, $ownerId));

        $this->pdo->prepare("UPDATE project_sources SET status = 'stored' WHERE project_id = ?")->execute([$projectId]);
        self::assertNull($repository->sourceForOwnedPreview($currentClipId, $ownerId));
    }

    private function controllerFor(?array $source, PrivateStorage $storage): ClipSourcePreviewController
    {
        return new ClipSourcePreviewController(
            static fn (int $clipId, int $userId): ?array => $source,
            $storage,
            new PrivateRangeResponseFactory()
        );
    }

    private function storage(callable $resolver): PrivateStorage
    {
        return new class ($resolver) implements PrivateStorage {
            private $resolver;

            public function __construct(callable $resolver)
            {
                $this->resolver = $resolver;
            }

            public function putUploaded(string $temporaryPath, string $objectKey): StoredObject
            {
                throw new \LogicException('Not used.');
            }

            public function putStream(mixed $stream, string $objectKey, int $maxBytes): StoredObject
            {
                throw new \LogicException('Not used.');
            }

            public function absolutePath(string $objectKey): string
            {
                return ($this->resolver)($objectKey);
            }

            public function delete(string $objectKey): void
            {
                throw new \LogicException('Not used.');
            }
        };
    }

    private function databaseUser(int $planId, string $prefix): int
    {
        $statement = $this->pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id) VALUES (?, ?, ?, ?)');
        $statement->execute(['Preview User', $prefix . '-' . bin2hex(random_bytes(7)) . '@example.test', 'x', $planId]);
        $id = (int) $this->pdo->lastInsertId();
        $this->databaseUserIds[] = $id;

        return $id;
    }

    private function analysis(int $projectId, string $promptVersion): int
    {
        $this->pdo->prepare("INSERT INTO ai_analyses (project_id, prompt_version, model, status) VALUES (?, ?, 'test-model', 'completed')")
            ->execute([$projectId, $promptVersion]);

        return (int) $this->pdo->lastInsertId();
    }

    private function clip(int $projectId, int $analysisId, int $index): int
    {
        $this->pdo->prepare("INSERT INTO clips (project_id, ai_analysis_id, suggestion_index, title, start_time, end_time, duration_seconds, viral_score, hook, reason, category, status) VALUES (?, ?, ?, 'Preview clip', 0, 30, 30, 90, 'Hook', 'Reason', 'insight', 'suggested')")
            ->execute([$projectId, $analysisId, $index]);

        return (int) $this->pdo->lastInsertId();
    }

    private function send(\App\Core\Response $response): string
    {
        ob_start();
        try {
            $response->send();

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
