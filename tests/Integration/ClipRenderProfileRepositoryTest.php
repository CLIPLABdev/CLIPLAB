<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use App\Media\Reframe\AspectRatio;
use App\Media\Reframe\ReframeKeyframe;
use App\Media\Reframe\ReframePlan;
use App\Repositories\ClipRenderProfileRepository;
use App\Repositories\UserConsentRepository;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ClipRenderProfileRepositoryTest extends TestCase
{
    private PDO $pdo;
    private int $planId;
    private int $userId;
    private int $projectId;
    private int $analysisId;
    private int $clipId;
    private int $legacyClipId;

    protected function setUp(): void
    {
        $dsn = $this->safeTestDsn();
        $this->pdo = new PDO($dsn, getenv('TEST_DB_USERNAME') ?: null, getenv('TEST_DB_PASSWORD') ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/database/migrations'))->run();

        $suffix = bin2hex(random_bytes(8));
        $this->pdo->prepare('INSERT INTO plans (slug, name, features) VALUES (?, ?, JSON_OBJECT())')
            ->execute(['reframe-' . $suffix, 'Reframe ' . $suffix]);
        $this->planId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO users (name, email, password_hash, plan_id) VALUES (?, ?, ?, ?)')
            ->execute(['Reframe user', 'reframe-' . $suffix . '@example.test', 'not-a-real-hash', $this->planId]);
        $this->userId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO projects (user_id, name, status, progress) VALUES (?, 'Reframe fixture', 'suggestions_ready', 92)")
            ->execute([$this->userId]);
        $this->projectId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO ai_analyses (project_id, prompt_version, model, status, validated_response_json) VALUES (?, 'reframe-test', 'test-model', 'completed', JSON_OBJECT('clips', JSON_ARRAY()))")
            ->execute([$this->projectId]);
        $this->analysisId = (int) $this->pdo->lastInsertId();
        $this->clipId = $this->createClip(0, 'Profile clip');
        $this->legacyClipId = $this->createClip(1, 'Legacy clip');
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
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
        $this->pdo->prepare('DELETE FROM plans WHERE id = ?')->execute([$this->planId]);
    }

    public function testRoundTripsAllModesAndPreservesOrderedDecimalKeyframes(): void
    {
        $repository = new ClipRenderProfileRepository($this->pdo);
        $plans = [
            1 => ReframePlan::original(),
            2 => ReframePlan::center(AspectRatio::fromString('9:16')),
            3 => ReframePlan::manual(AspectRatio::fromString('1:1'), new ReframeKeyframe(0, 0.1234564, 0.9876544, 'manual')),
            4 => ReframePlan::automatic(
                AspectRatio::fromString('4:5'),
                ReframePlan::DETECTOR_VERSION,
                [
                    new ReframeKeyframe(0, 0.111111, 0.222222, 'detected'),
                    new ReframeKeyframe(750, 0.333333, 0.444444, 'detected'),
                    new ReframeKeyframe(180000, 0.555555, 0.666666, 'detected'),
                ]
            ),
        ];

        foreach ($plans as $revision => $plan) {
            self::assertGreaterThan(0, $repository->create($this->clipId, $revision, $plan));
            self::assertEquals($plan, $repository->findForClipRevision($this->clipId, $revision));
        }

        $rows = $this->pdo->query('SELECT sequence_index, at_ms, center_x, center_y, source FROM clip_reframe_keyframes ORDER BY render_profile_id, sequence_index')->fetchAll();
        self::assertSame([
            ['sequence_index' => 0, 'at_ms' => 0, 'center_x' => '0.123456', 'center_y' => '0.987654', 'source' => 'manual'],
            ['sequence_index' => 0, 'at_ms' => 0, 'center_x' => '0.111111', 'center_y' => '0.222222', 'source' => 'detected'],
            ['sequence_index' => 1, 'at_ms' => 750, 'center_x' => '0.333333', 'center_y' => '0.444444', 'source' => 'detected'],
            ['sequence_index' => 2, 'at_ms' => 180000, 'center_x' => '0.555555', 'center_y' => '0.666666', 'source' => 'detected'],
        ], array_map(static fn (array $row): array => [
            'sequence_index' => (int) $row['sequence_index'],
            'at_ms' => (int) $row['at_ms'],
            'center_x' => (string) $row['center_x'],
            'center_y' => (string) $row['center_y'],
            'source' => (string) $row['source'],
        ], $rows));
    }

    public function testResolvesExactRevisionMismatchAndLegacyStates(): void
    {
        $repository = new ClipRenderProfileRepository($this->pdo);
        $autoPlan = ReframePlan::automatic(AspectRatio::fromString('16:9'), ReframePlan::DETECTOR_VERSION, [
            new ReframeKeyframe(0, 0.25, 0.5, 'detected'),
            new ReframeKeyframe(1000, 0.75, 0.5, 'detected'),
        ]);
        $profileId = $repository->create($this->clipId, 2, $autoPlan);

        self::assertGreaterThan(0, $profileId);
        self::assertEquals($autoPlan, $repository->findForClipRevision($this->clipId, 2));
        self::assertSame('matched', $repository->resolveForJob($this->clipId, 2)->state());
        self::assertEquals($autoPlan, $repository->resolveForJob($this->clipId, 2)->plan());
        self::assertSame('mismatch', $repository->resolveForJob($this->clipId, 3)->state());
        self::assertSame('legacy', $repository->resolveForJob($this->legacyClipId, 1)->state());
    }

    public function testCreateUsesCallerTransactionAndDatabaseEnforcesRevisionAndForeignKey(): void
    {
        $repository = new ClipRenderProfileRepository($this->pdo);
        $this->pdo->beginTransaction();
        $repository->create($this->clipId, 1, ReframePlan::original());
        self::assertTrue($this->pdo->inTransaction());
        self::assertTrue($repository->hasForClip($this->clipId));
        $this->pdo->rollBack();
        self::assertFalse($repository->hasForClip($this->clipId));

        $repository->create($this->clipId, 1, ReframePlan::original());
        try {
            $repository->create($this->clipId, 1, ReframePlan::original());
            self::fail('Duplicate clip revisions must be rejected.');
        } catch (PDOException) {
            self::assertTrue(true);
        }
        $this->expectException(PDOException::class);
        $repository->create(PHP_INT_MAX, 1, ReframePlan::original());
    }

    public function testDeletingClipCascadesProfilesAndKeyframes(): void
    {
        $repository = new ClipRenderProfileRepository($this->pdo);
        $profileId = $repository->create($this->clipId, 1, ReframePlan::manual(
            AspectRatio::fromString('9:16'),
            new ReframeKeyframe(0, 0.5, 0.5, 'manual')
        ));
        $this->pdo->prepare('DELETE FROM clips WHERE id = ?')->execute([$this->clipId]);

        self::assertSame(0, $this->countById('clip_render_profiles', $profileId));
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM clip_reframe_keyframes WHERE render_profile_id = ?');
        $statement->execute([$profileId]);
        self::assertSame(0, (int) $statement->fetchColumn());
    }

    public function testRefusesIncoherentPersistedKeyframeRows(): void
    {
        $repository = new ClipRenderProfileRepository($this->pdo);
        $profileId = $repository->create($this->clipId, 1, ReframePlan::manual(
            AspectRatio::fromString('1:1'),
            new ReframeKeyframe(0, 0.5, 0.5, 'manual')
        ));
        $this->pdo->prepare("UPDATE clip_reframe_keyframes SET source = 'detected' WHERE render_profile_id = ?")
            ->execute([$profileId]);

        try {
            $repository->findForClipRevision($this->clipId, 1);
            self::fail('Incoherent rows must not be rehydrated.');
        } catch (RuntimeException $exception) {
            self::assertSame('Stored reframe profile is inconsistent.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testRejectsInvalidProfileIdentifiersBeforeSql(): void
    {
        $repository = new ClipRenderProfileRepository($this->pdo);
        foreach ([[0, 1], [$this->clipId, 0], [-1, 1]] as [$clipId, $revision]) {
            try {
                $repository->findForClipRevision($clipId, $revision);
                self::fail('Invalid identifiers must be rejected.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testConsentGrantRevokeAndRegrantAreVersionedAndIdempotent(): void
    {
        $repository = new UserConsentRepository($this->pdo);
        $first = new DateTimeImmutable('2026-09-04 10:00:00', new DateTimeZone('UTC'));
        $later = new DateTimeImmutable('2026-09-04 11:00:00', new DateTimeZone('UTC'));
        $revoked = new DateTimeImmutable('2026-09-04 12:00:00', new DateTimeZone('UTC'));
        $regranted = new DateTimeImmutable('2026-09-04 13:00:00', new DateTimeZone('UTC'));

        self::assertFalse($repository->isActive($this->userId, 'face_detection', '2026-09-04'));
        $repository->grant($this->userId, 'face_detection', '2026-09-04', $first);
        $repository->grant($this->userId, 'face_detection', '2026-09-04', $later);
        self::assertTrue($repository->isActive($this->userId, 'face_detection', '2026-09-04'));
        self::assertSame(['2026-09-04 10:00:00', null], $this->consentDates());

        $repository->revoke($this->userId, 'face_detection', '2026-09-04', $revoked);
        $repository->revoke($this->userId, 'face_detection', '2026-09-04', $regranted);
        self::assertFalse($repository->isActive($this->userId, 'face_detection', '2026-09-04'));
        self::assertSame(['2026-09-04 10:00:00', '2026-09-04 12:00:00'], $this->consentDates());

        $repository->grant($this->userId, 'face_detection', '2026-09-04', $regranted);
        self::assertTrue($repository->isActive($this->userId, 'face_detection', '2026-09-04'));
        self::assertSame(['2026-09-04 13:00:00', null], $this->consentDates());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM user_consents')->fetchColumn());
    }

    public function testConsentValidationRunsBeforeSqlAndUserDeletionCascades(): void
    {
        $repository = new UserConsentRepository($this->pdo);
        $at = new DateTimeImmutable('2026-09-04 10:00:00', new DateTimeZone('UTC'));
        foreach ([[0, 'face_detection', '2026-09-04'], [$this->userId, 'Face Detection', '2026-09-04'], [$this->userId, 'face_detection', 'v1']] as [$userId, $purpose, $version]) {
            try {
                $repository->grant($userId, $purpose, $version, $at);
                self::fail('Invalid consent identity must be rejected.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        $repository->grant($this->userId, 'face_detection', '2026-09-04', $at);
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM user_consents')->fetchColumn());
    }

    private function safeTestDsn(): string
    {
        $dsn = getenv('TEST_DB_DSN');
        self::assertIsString($dsn, 'TEST_DB_DSN must be configured for integration tests.');
        self::assertStringStartsWith('mysql:', strtolower(trim($dsn)));
        self::assertSame(1, preg_match_all('/(?:^|[;:])dbname=([^;]+)/i', $dsn, $matches));
        $database = strtolower(trim($matches[1][0], " \t\n\r\0\x0B`'\""));
        self::assertMatchesRegularExpression('/_test\z/D', $database, 'Integration tests require a dedicated *_test database.');
        return $dsn;
    }

    public function testReadsHistorical720ProfileAndCreatesNew1080WithoutAcceptingCorruptDimensions(): void
    {
        $this->pdo->prepare("INSERT INTO clip_render_profiles (clip_id,render_revision,aspect_ratio,reframe_mode,output_width,output_height) VALUES (?,1,'9:16','center',720,1280)")->execute([$this->clipId]);
        $repository=new ClipRenderProfileRepository($this->pdo);
        self::assertSame(720,$repository->findForClipRevision($this->clipId,1)->aspectRatio()->outputWidth());
        $repository->create($this->clipId,2,ReframePlan::center(AspectRatio::fromString('9:16')));
        self::assertSame(1080,$repository->findForClipRevision($this->clipId,2)->aspectRatio()->outputWidth());
        $this->expectException(PDOException::class);
        $this->pdo->prepare("INSERT INTO clip_render_profiles (clip_id,render_revision,aspect_ratio,reframe_mode,output_width,output_height) VALUES (?,3,'9:16','center',720,1282)")->execute([$this->clipId]);
    }

    private function createClip(int $suggestionIndex, string $title): int
    {
        $this->pdo->prepare('INSERT INTO clips (project_id, ai_analysis_id, suggestion_index, title, start_time, end_time, duration_seconds, viral_score, hook, reason, category) VALUES (?, ?, ?, ?, 0, 20, 20, 90, ?, ?, ?)')
            ->execute([$this->projectId, $this->analysisId, $suggestionIndex, $title, 'Hook', 'Reason', 'other']);
        return (int) $this->pdo->lastInsertId();
    }

    private function countById(string $table, int $id): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE id = ?');
        $statement->execute([$id]);
        return (int) $statement->fetchColumn();
    }

    /** @return array{string,string|null} */
    private function consentDates(): array
    {
        $statement = $this->pdo->prepare('SELECT granted_at, revoked_at FROM user_consents WHERE user_id = ? AND purpose = ? AND policy_version = ?');
        $statement->execute([$this->userId, 'face_detection', '2026-09-04']);
        $row = $statement->fetch();
        self::assertIsArray($row);
        return [(string) $row['granted_at'], $row['revoked_at'] === null ? null : (string) $row['revoked_at']];
    }
}
