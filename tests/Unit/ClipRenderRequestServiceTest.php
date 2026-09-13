<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\JobDispatcher;
use App\Exceptions\ClipRenderValidationException;
use App\Media\ClipRenderReceipt;
use App\Media\Reframe\ReframePlanValidator;
use App\Media\Reframe\ReframeSubmission;
use App\Repositories\ClipRenderProfileRepository;
use App\Repositories\ClipRepository;
use App\Repositories\ProjectRepository;
use App\Services\ClipRenderRequestService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ClipRenderRequestServiceTest extends TestCase
{
    private ClipRenderSqlitePdo $pdo;
    private RecordingRenderDispatcher $dispatcher;
    private ClipRenderProfileRepository $profiles;
    private ClipRenderRequestService $service;
    private \Tests\Support\PreciseMediaProcessor $precise;

    protected function setUp(): void
    {
        $this->pdo = new ClipRenderSqlitePdo();
        $this->createSchema();
        $this->seedClip();
        $this->precise = new \Tests\Support\PreciseMediaProcessor($this->pdo,200000);
        $this->dispatcher = new RecordingRenderDispatcher();
        $this->profiles = new ClipRenderProfileRepository($this->pdo);
        $this->service = $this->service($this->dispatcher);
    }

    public function testQueuesAValidatedRenderWithExactRevisionPayloadAndIdempotencyKey(): void
    {
        $receipt = $this->service->request(7, 3, '12.500', '35.250');

        self::assertNotNull($receipt);
        self::assertSame(7, $receipt->clipId());
        self::assertSame(9, $receipt->projectId());
        self::assertSame(1, $receipt->revision());
        self::assertTrue($receipt->created());
        self::assertSame('generate_subtitles', $this->dispatcher->type);
        self::assertSame(9, $this->dispatcher->projectId);
        self::assertSame(['clip_id' => 7, 'render_revision' => 1], $this->dispatcher->payload);
        self::assertSame('clip-subtitles:7:v1', $this->dispatcher->idempotencyKey);
        self::assertSame(1, $this->dispatcher->calls);
        self::assertSame(
            ['queued', 12.5, 35.25, 1, null],
            $this->clipState()
        );
        self::assertSame(['rendering', 96], $this->projectState());
        $plan = $this->profiles->findForClipRevision(7, 1);
        self::assertNotNull($plan);
        self::assertSame('original', $plan->aspectRatio()->value());
        self::assertSame('original', $plan->mode());
        $captions = (new \App\Repositories\ClipEditorRepository($this->pdo))->snapshot(7, 1);
        self::assertNotNull($captions);
        self::assertSame('auto', $captions['mode']);
        self::assertSame('minimal', $captions['options']->toArray()['style']);
        self::assertSame('pending', $captions['track_status']);
        self::assertSame(22750, $captions['duration_ms']);
        self::assertNull($captions['parent_clip_id']);
        self::assertNull($captions['transcript']);
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testCreatesAnExactManualSnapshotBeforeQueueDispatchSyncAndCommit(): void
    {
        $this->dispatcher->onDispatch = function (): void {
            $this->pdo->events[] = 'dispatch';
        };
        $this->pdo->startRecording();

        $receipt = $this->service->request(
            7,
            3,
            '12.500',
            '14.500',
            new ReframeSubmission('9:16', 'manual', '0.250000', '0.500000', '')
        );

        self::assertNotNull($receipt);
        self::assertSame(1, $receipt->revision());
        self::assertSame(['clip_id' => 7, 'render_revision' => 1], $this->dispatcher->payload);
        $plan = $this->profiles->findForClipRevision(7, 1);
        self::assertNotNull($plan);
        self::assertSame('9:16', $plan->aspectRatio()->value());
        self::assertSame('manual', $plan->mode());
        self::assertSame('0.250000', $plan->keyframes()[0]->centerXDecimal());
        self::assertSame('0.500000', $plan->keyframes()[0]->centerYDecimal());
        self::assertSame(
            ['lock', 'profile', 'keyframe', 'queue', 'dispatch', 'sync', 'commit'],
            $this->pdo->events
        );
    }

    public function testRoundsTheValidatedRenderIntervalToAnIntegerDurationForAutomaticKeyframes(): void
    {
        $receipt = $this->service->request(
            7,
            3,
            '12.001',
            '13.002',
            new ReframeSubmission(
                '1:1',
                'auto',
                '',
                '',
                '[{"at_ms":0,"center_x":0.250000,"center_y":0.500000},{"at_ms":1001,"center_x":0.750000,"center_y":0.500000}]'
            )
        );

        self::assertNotNull($receipt);
        $plan = $this->profiles->findForClipRevision(7, 1);
        self::assertNotNull($plan);
        self::assertSame('auto', $plan->mode());
        self::assertSame([0, 1001], array_map(
            static fn ($keyframe): int => $keyframe->atMs(),
            $plan->keyframes()
        ));
    }

    /** @dataProvider malformedDecimalProvider */
    public function testRejectsAnythingOutsideTheCompleteDecimalGrammar(
        string $start,
        string $end,
        string $expectedField
    ): void {
        $this->assertValidationError($start, $end, $expectedField);
        self::assertSame(['suggested', null, null, 0, null], $this->clipState());
        self::assertSame(0, $this->dispatcher->calls);
    }

    /** @return iterable<string, array{string, string, string}> */
    public function malformedDecimalProvider(): iterable
    {
        yield 'NaN' => ['NaN', '20', 'start_time'];
        yield 'infinity' => ['INF', '20', 'start_time'];
        yield 'exponent' => ['1e1', '20', 'start_time'];
        yield 'comma' => ['1,5', '20', 'start_time'];
        yield 'negative' => ['-1', '20', 'start_time'];
        yield 'leading zero' => ['01', '20', 'start_time'];
        yield 'missing fraction' => ['1.', '20', 'start_time'];
        yield 'too many decimals' => ['1.0000', '20', 'start_time'];
        yield 'leading whitespace' => [' 1', '20', 'start_time'];
        yield 'trailing newline' => ["1\n", '20', 'start_time'];
        yield 'integer too wide' => ['1000000', '1000001', 'start_time'];
        yield 'malformed end' => ['1', '2e0', 'end_time'];
    }

    /** @dataProvider invalidIntervalProvider */
    public function testRejectsInvalidIntervalRelationships(string $start, string $end): void
    {
        $this->assertValidationError($start, $end, 'end_time');
        self::assertSame(['suggested', null, null, 0, null], $this->clipState());
        self::assertSame(0, $this->dispatcher->calls);
    }

    public function testRejectsAnIntervalAboveTheConfiguredDurationLimit(): void
    {
        $this->service = $this->service($this->dispatcher, 30);

        $this->assertValidationError('10', '40.001', 'end_time');

        self::assertSame(['suggested', null, null, 0, null], $this->clipState());
        self::assertSame(0, $this->dispatcher->calls);
    }

    /** @return iterable<string, array{string, string}> */
    public function invalidIntervalProvider(): iterable
    {
        yield 'equal boundaries' => ['10', '10'];
        yield 'reversed boundaries' => ['10', '9'];
        yield 'shorter than one second' => ['10', '10.999'];
        yield 'longer than 180 seconds' => ['0', '180.001'];
        yield 'past source duration' => ['190', '200.001'];
    }

    /** @dataProvider inclusiveBoundaryProvider */
    public function testAcceptsInclusiveDurationAndSourceBoundaries(string $start, string $end): void
    {
        $receipt = $this->service->request(7, 3, $start, $end);

        self::assertNotNull($receipt);
        self::assertTrue($receipt->created());
        self::assertSame(1, $receipt->revision());
    }

    /** @return iterable<string, array{string, string}> */
    public function inclusiveBoundaryProvider(): iterable
    {
        yield 'one second' => ['0', '1'];
        yield '180 seconds ending at source boundary' => ['20', '200'];
    }

    /** @dataProvider activeStateProvider */
    public function testActiveRenderReturnsExistingReceiptWithoutValidatingOrDispatching(string $status): void
    {
        $this->pdo->exec(
            "UPDATE clips SET status = '{$status}', render_start_time = 4.5, render_end_time = 9.5, render_revision = 4 WHERE id = 7"
        );

        $receipt = $this->service->request(
            7,
            3,
            'not-a-number',
            'also-invalid',
            new ReframeSubmission('invalid', 'invalid', '', '', '', true)
        );

        self::assertNotNull($receipt);
        self::assertSame(7, $receipt->clipId());
        self::assertSame(9, $receipt->projectId());
        self::assertSame(4, $receipt->revision());
        self::assertFalse($receipt->created());
        self::assertSame(0, $this->dispatcher->calls);
        self::assertSame([$status, 4.5, 9.5, 4, null], $this->clipState());
    }

    /** @return iterable<string, array{string}> */
    public function activeStateProvider(): iterable
    {
        yield 'queued' => ['queued'];
        yield 'rendering' => ['rendering'];
    }

    public function testCompletedClipIsImmutable(): void
    {
        $this->pdo->exec(
            "UPDATE clips SET status = 'completed', render_start_time = 10, render_end_time = 40, render_revision = 2 WHERE id = 7"
        );

        $this->assertValidationError(
            '12.500',
            '35.250',
            'clip',
            new ReframeSubmission('invalid', 'invalid', '', '', '', true)
        );

        self::assertSame(['completed', 10.0, 40.0, 2, null], $this->clipState());
        self::assertSame(0, $this->dispatcher->calls);
    }

    public function testFailedClipQueuesANewRevisionAndClearsItsError(): void
    {
        $this->pdo->exec(
            "UPDATE clips SET status = 'failed', render_start_time = 10, render_end_time = 40, render_revision = 2, render_error_code = 'render_failed' WHERE id = 7"
        );

        $receipt = $this->service->request(7, 3, '15', '30');

        self::assertNotNull($receipt);
        self::assertSame(3, $receipt->revision());
        self::assertTrue($receipt->created());
        self::assertSame(['clip_id' => 7, 'render_revision' => 3], $this->dispatcher->payload);
        self::assertSame('clip-subtitles:7:v3', $this->dispatcher->idempotencyKey);
        self::assertSame(['queued', 15.0, 30.0, 3, null], $this->clipState());
    }

    public function testForeignAndStaleClipsAreIndistinguishable(): void
    {
        $invalid = new ReframeSubmission('invalid', 'invalid', '', '', '', true);
        self::assertNull($this->service->request(7, 4, '12.500', '35.250', $invalid));

        $this->pdo->exec('INSERT INTO ai_analyses (id, project_id) VALUES (12, 9)');
        self::assertNull($this->service->request(7, 3, '12.500', '35.250', $invalid));

        self::assertSame(0, $this->dispatcher->calls);
        self::assertSame(['suggested', null, null, 0, null], $this->clipState());
    }

    public function testInvalidIdentifiersReturnNullWithoutOpeningAJob(): void
    {
        self::assertNull($this->service->request(0, 3, '12.500', '35.250'));
        self::assertNull($this->service->request(7, 0, '12.500', '35.250'));
        self::assertSame(0, $this->dispatcher->calls);
    }

    public function testDispatcherFailureRollsBackClipAndProjectTogether(): void
    {
        $this->dispatcher->failure = new RuntimeException('dispatch failed');

        try {
            $this->service->request(
                7,
                3,
                '12.500',
                '35.250',
                new ReframeSubmission('9:16', 'manual', '0.250000', '0.500000', '')
            );
            self::fail('A dispatcher failure was swallowed.');
        } catch (RuntimeException $exception) {
            self::assertSame('dispatch failed', $exception->getMessage());
        }

        self::assertSame(['suggested', null, null, 0, null], $this->clipState());
        self::assertSame(['suggestions_ready', 92], $this->projectState());
        self::assertSame(0, $this->profileCount());
        self::assertSame(0, $this->keyframeCount());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM clip_editor_profiles')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM clip_subtitle_tracks')->fetchColumn());
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testRejectsAutomaticCaptionsWithoutAnAudioStreamBeforeQueueing(): void
    {
        $this->pdo->exec('UPDATE project_sources SET has_audio=0');
        $this->assertValidationError('0', '10', 'form');
        self::assertSame(0, $this->dispatcher->calls);
        self::assertSame(0, $this->profileCount());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM clip_editor_profiles')->fetchColumn());
    }

    public function testRepeatedRequestReusesOneAutomaticCaptionTrack(): void
    {
        self::assertTrue($this->service->request(7, 3, '0', '10')->created());
        self::assertFalse($this->service->request(7, 3, '0', '10')->created());
        self::assertSame(1, $this->dispatcher->calls);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM clip_editor_profiles')->fetchColumn());
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM clip_subtitle_tracks')->fetchColumn());
    }

    public function testValidatorFailuresAreGenericAndDoNotPersistOrDispatch(): void
    {
        $submission = new ReframeSubmission('9:16', 'manual', 'private-invalid', '0.500000', '');

        try {
            $this->service->request(7, 3, '12.500', '14.500', $submission);
            self::fail('An invalid reframe submission was accepted.');
        } catch (ClipRenderValidationException $exception) {
            self::assertSame(['reframe' => 'Configuração de enquadramento inválida.'], $exception->errors());
            self::assertSame(9, $exception->projectId());
            self::assertStringNotContainsString('private-invalid', $exception->getMessage());
        }

        self::assertSame(['suggested', null, null, 0, null], $this->clipState());
        self::assertSame(0, $this->profileCount());
        self::assertSame(0, $this->keyframeCount());
        self::assertSame(0, $this->dispatcher->calls);
    }

    public function testOriginalAndReframedRequestsUseDistinctDurationLimits(): void
    {
        $this->service = $this->service($this->dispatcher, 180, new ReframePlanValidator(30));

        $original = $this->service->request(7, 3, '10', '50', ReframeSubmission::original());
        self::assertNotNull($original);
        self::assertSame(1, $original->revision());
        $this->pdo->exec("UPDATE clips SET status = 'failed' WHERE id = 7");

        try {
            $this->service->request(
                7,
                3,
                '10',
                '50',
                new ReframeSubmission('9:16', 'center', '', '', '')
            );
            self::fail('A reframed interval above its independent limit was accepted.');
        } catch (ClipRenderValidationException $exception) {
            self::assertSame(['reframe' => 'Configuração de enquadramento inválida.'], $exception->errors());
            self::assertSame(9, $exception->projectId());
        }

        self::assertSame(1, $this->profileCount());
        self::assertSame('original', $this->profiles->findForClipRevision(7, 1)?->mode());
    }

    public function testReceiptAndValidationExceptionExposeOnlyTheirContracts(): void
    {
        $receipt = new ClipRenderReceipt(7, 9, 2, false);
        self::assertSame(7, $receipt->clipId());
        self::assertSame(9, $receipt->projectId());
        self::assertSame(2, $receipt->revision());
        self::assertFalse($receipt->created());

        $exception = new ClipRenderValidationException(['end_time' => 'Intervalo inválido.'], 9);
        self::assertSame(['end_time' => 'Intervalo inválido.'], $exception->errors());
        self::assertSame(9, $exception->projectId());
    }

    public function testRejectsRoundedEofThenAcceptsExactEndAndReplaysWithoutProbe(): void
    {
        $this->pdo->exec('UPDATE project_sources SET duration_seconds=46');
        $this->precise->milliseconds=45011;
        try {
            $this->service->request(7,3,'0','46');
            self::fail('Rounded EOF was accepted.');
        } catch (ClipRenderValidationException $error) {
            self::assertStringContainsString('45.011',$error->errors()['end_time']);
        }
        self::assertSame(0,$this->dispatcher->calls);
        self::assertSame(0,$this->profileCount());
        $receipt=$this->service->request(7,3,'0','45.011');
        self::assertTrue($receipt->created());
        self::assertSame(['queued',0.0,45.011,1,null],$this->clipState());
        self::assertSame(46,(int)$this->pdo->query('SELECT duration_seconds FROM project_sources')->fetchColumn());
        self::assertSame(2,$this->precise->calls);
        $this->precise->fail=true;
        self::assertFalse($this->service->request(7,3,'0','46')->created());
        self::assertSame(2,$this->precise->calls);
        self::assertSame(1,$this->dispatcher->calls);
    }

    public function testPreciseProbeFailureOrIdentityChangeCannotQueue(): void
    {
        $this->precise->fail=true;
        $this->assertValidationError('0','10','end_time');
        self::assertSame(0,$this->dispatcher->calls);
        $this->precise->fail=false;
        $this->precise->afterInspect=function (): void {
            $this->pdo->exec("UPDATE project_sources SET object_key='imports/9/replaced.mp4'");
        };
        $this->assertValidationError('0','10','end_time');
        self::assertSame(0,$this->profileCount());
        self::assertSame(0,$this->dispatcher->calls);
    }

    private function service(
        JobDispatcher $dispatcher,
        int $maxDurationSeconds = 180,
        ?ReframePlanValidator $validator = null
    ): ClipRenderRequestService
    {
        return new ClipRenderRequestService(
            $this->pdo,
            new ClipRepository($this->pdo),
            new ProjectRepository($this->pdo),
            $this->profiles,
            $validator ?? new ReframePlanValidator(),
            $dispatcher,
            $maxDurationSeconds,
            new \App\Services\SourceDurationPreflight($this->pdo,new \App\Repositories\ProjectSourceRepository($this->pdo),$this->precise)
        );
    }

    private function assertValidationError(
        string $start,
        string $end,
        string $expectedField,
        ?ReframeSubmission $reframe = null
    ): void
    {
        try {
            $this->service->request(7, 3, $start, $end, $reframe);
            self::fail('An invalid render request was accepted.');
        } catch (ClipRenderValidationException $exception) {
            self::assertArrayHasKey($expectedField, $exception->errors());
            self::assertSame(9, $exception->projectId());
        }
    }

    private function createSchema(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE projects (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    status TEXT NOT NULL,
    progress INTEGER NOT NULL
);
CREATE TABLE project_sources (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL,
    status TEXT NOT NULL,
    duration_seconds INTEGER NULL,
    has_audio INTEGER NOT NULL DEFAULT 1,
    storage_disk TEXT NOT NULL DEFAULT 'local',
    sha256 TEXT NOT NULL DEFAULT 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    size_bytes INTEGER NOT NULL DEFAULT 1024,
    object_key TEXT NULL,
    mime_type TEXT NULL
);
CREATE TABLE ai_analyses (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL
);
CREATE TABLE clips (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL,
    ai_analysis_id INTEGER NOT NULL,
    status TEXT NOT NULL,
    start_time REAL NOT NULL,
    end_time REAL NOT NULL,
    render_start_time REAL NULL,
    render_end_time REAL NULL,
    render_revision INTEGER NOT NULL DEFAULT 0,
    render_error_code TEXT NULL,
    approved_at TEXT NULL,
    render_requested_at TEXT NULL
);
CREATE TABLE clip_render_profiles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    clip_id INTEGER NOT NULL,
    render_revision INTEGER NOT NULL,
    aspect_ratio TEXT NOT NULL,
    reframe_mode TEXT NOT NULL,
    output_width INTEGER NULL,
    output_height INTEGER NULL,
    detector_version TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (clip_id, render_revision)
);
CREATE TABLE clip_reframe_keyframes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    render_profile_id INTEGER NOT NULL,
    sequence_index INTEGER NOT NULL,
    at_ms INTEGER NOT NULL,
    center_x NUMERIC NOT NULL,
    center_y NUMERIC NOT NULL,
    source TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (render_profile_id, sequence_index),
    UNIQUE (render_profile_id, at_ms)
);
CREATE TABLE clip_editor_profiles (
    id INTEGER PRIMARY KEY AUTOINCREMENT, clip_id INTEGER NOT NULL, render_revision INTEGER NOT NULL,
    parent_clip_id INTEGER NULL, user_id INTEGER NOT NULL, request_key TEXT NOT NULL,
    options_json TEXT NOT NULL, transcript_mode TEXT NOT NULL, duration_ms INTEGER NOT NULL,
    UNIQUE (clip_id, render_revision), UNIQUE (user_id, request_key)
);
CREATE TABLE clip_subtitle_tracks (
    id INTEGER PRIMARY KEY AUTOINCREMENT, editor_profile_id INTEGER NOT NULL UNIQUE,
    status TEXT NOT NULL DEFAULT 'pending', language TEXT NULL, error_code TEXT NULL, completed_at TEXT NULL
);
CREATE TABLE clip_subtitle_cues (
    id INTEGER PRIMARY KEY AUTOINCREMENT, track_id INTEGER NOT NULL, cue_index INTEGER NOT NULL,
    start_ms INTEGER NOT NULL, end_ms INTEGER NOT NULL, text TEXT NOT NULL, words_json TEXT NULL
);
SQL);
    }

    private function seedClip(): void
    {
        $this->pdo->exec("INSERT INTO projects (id, user_id, status, progress) VALUES (9, 3, 'suggestions_ready', 92)");
        $this->pdo->exec("INSERT INTO project_sources (id, project_id, status, duration_seconds, object_key, mime_type) VALUES (5, 9, 'ready', 200, 'imports/9/source.mp4', 'video/mp4')");
        $this->pdo->exec('INSERT INTO ai_analyses (id, project_id) VALUES (11, 9)');
        $this->pdo->exec("INSERT INTO clips (id, project_id, ai_analysis_id, status, start_time, end_time) VALUES (7, 9, 11, 'suggested', 10, 40)");
    }

    /** @return array{string, ?float, ?float, int, ?string} */
    private function clipState(): array
    {
        $row = $this->pdo->query(
            'SELECT status, render_start_time, render_end_time, render_revision, render_error_code FROM clips WHERE id = 7'
        )->fetch(PDO::FETCH_ASSOC);

        return [
            (string) $row['status'],
            $row['render_start_time'] === null ? null : (float) $row['render_start_time'],
            $row['render_end_time'] === null ? null : (float) $row['render_end_time'],
            (int) $row['render_revision'],
            $row['render_error_code'] === null ? null : (string) $row['render_error_code'],
        ];
    }

    /** @return array{string, int} */
    private function projectState(): array
    {
        $row = $this->pdo->query('SELECT status, progress FROM projects WHERE id = 9')->fetch(PDO::FETCH_ASSOC);

        return [(string) $row['status'], (int) $row['progress']];
    }

    private function profileCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM clip_render_profiles')->fetchColumn();
    }

    private function keyframeCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM clip_reframe_keyframes')->fetchColumn();
    }
}

final class ClipRenderSqlitePdo extends PDO
{
    /** @var list<string> */
    public array $events = [];
    private bool $recording = false;

    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => '2026-09-04 12:00:00');
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->recording) {
            if ($this->inTransaction() && str_contains($query, 's.duration_seconds AS source_duration_seconds')) {
                $this->events[] = 'lock';
            } elseif (str_contains($query, 'INSERT INTO clip_render_profiles')) {
                $this->events[] = 'profile';
            } elseif (str_contains($query, 'INSERT INTO clip_reframe_keyframes')) {
                $this->events[] = 'keyframe';
            } elseif (str_contains($query, "UPDATE clips SET status = 'queued'")) {
                $this->events[] = 'queue';
            } elseif (str_contains($query, 'UPDATE projects p')) {
                $this->events[] = 'sync';
            }
        }
        $query = str_replace('UPDATE projects p', 'UPDATE projects AS p', $query);

        return parent::prepare($query, $options);
    }

    public function commit(): bool
    {
        if ($this->recording) {
            $this->events[] = 'commit';
            $this->recording = false;
        }

        return parent::commit();
    }

    public function startRecording(): void
    {
        $this->events = [];
        $this->recording = true;
    }
}

final class RecordingRenderDispatcher implements JobDispatcher
{
    public int $calls = 0;
    public ?string $type = null;
    public ?int $projectId = null;
    /** @var array<string, mixed>|null */
    public ?array $payload = null;
    public ?string $idempotencyKey = null;
    public ?RuntimeException $failure = null;
    /** @var callable|null */
    public $onDispatch = null;

    public function dispatch(string $type, int $projectId, array $payload, string $idempotencyKey): int
    {
        ++$this->calls;
        $this->type = $type;
        $this->projectId = $projectId;
        $this->payload = $payload;
        $this->idempotencyKey = $idempotencyKey;
        if ($this->onDispatch !== null) {
            ($this->onDispatch)();
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return 41;
    }
}
