<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\ClipRenderer;
use App\Contracts\ClipRenderProfileStore;
use App\Contracts\PrivateStorage;
use App\Contracts\RenderArtifactCleanupStore;
use App\Exceptions\ClipRenderException;
use App\Media\ProjectSource;
use App\Media\Reframe\AspectRatio;
use App\Media\Reframe\ReframeKeyframe;
use App\Media\Reframe\ReframePlan;
use App\Media\Reframe\ReframePlanResolution;
use App\Media\RenderClipRequest;
use App\Media\RenderedClipArtifacts;
use App\Media\StoredObject;
use App\Queue\ClaimedJob;
use App\Queue\ProcessingEffectGuard;
use App\Queue\ProcessingErrorCatalog;
use App\Queue\RenderClipHandler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RenderClipHandlerTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testPublishesWithReadOnlyStreamsAndConfiguredLimitsThenCompletesInsideGuard(): void
    {
        $clips = new RenderHandlerClips($this->context());
        $projects = new RenderHandlerProjects();
        $renderer = new RenderHandlerRenderer($this->artifacts('video-bytes', 'thumb-bytes'));
        $storage = new RenderHandlerStorage();
        $guard = new RenderHandlerGuard();

        $profiles = new RenderHandlerProfiles();
        $outcome = (new RenderClipHandler($clips, $projects, $renderer, $storage, $guard, 100, 20, new RenderHandlerCleanups(), $profiles, 180, $this->captionedEditor()))->handle($this->job());

        self::assertSame('completed', $outcome->status());
        self::assertSame(['rendering', 'completed'], $clips->events);
        self::assertSame([4], $projects->synchronized);
        self::assertSame(2, $guard->calls);
        self::assertSame(1, $renderer->calls);
        self::assertSame(12.5, $renderer->request?->startTime());
        self::assertSame(22.75, $renderer->request?->durationSeconds());
        self::assertSame('original', $renderer->request?->reframePlan()->mode());
        self::assertSame([[7, 1]], $profiles->requests);
        self::assertSame(['rb', 'rb'], $storage->streamModes);
        self::assertSame([100, 20], $storage->limits);
        self::assertSame(['video-bytes', 'thumb-bytes'], $storage->contents);
        self::assertMatchesRegularExpression('#^processed/4/7-[a-f0-9]{32}\.mp4$#D', $storage->keys[0]);
        self::assertMatchesRegularExpression('#^thumbnails/4/7-[a-f0-9]{32}\.jpg$#D', $storage->keys[1]);
        self::assertSame($storage->keys[0], $clips->video?->objectKey());
        self::assertSame($storage->keys[1], $clips->thumbnail?->objectKey());
        self::assertSame([], $storage->deleted);
        self::assertFileDoesNotExist($renderer->videoPath);
        self::assertFileDoesNotExist($renderer->thumbnailPath);
    }

    public function testEditorSnapshotIsPassedToRendererAndQuotaRunsBeforeCompletion(): void
    {
        $clips=new RenderHandlerClips($this->context());
        $renderer=new RenderHandlerRenderer($this->artifacts('video-bytes','thumb-bytes'));
        $editor=new class {
            public function snapshot(int $clip,int $revision): array {
                return ['options'=>\App\Media\Editor\EditorOptions::fromArray(['style'=>'viral','title'=>'Title']),'mode'=>'auto','track_status'=>'ready','duration_ms'=>22750,
                    'transcript'=>new \App\Media\Subtitles\Transcript('pt',[new \App\Media\Subtitles\SubtitleCue(0,1000,'Legenda')],22750)];
            }
        };
        $calls=[];
        $guard=new RenderHandlerGuard();
        $quota=function(int $clip,int $bytes) use (&$calls,$clips,$guard): void {
            self::assertSame(['rendering'],$clips->events);
            self::assertSame(2,$guard->calls);
            $calls[]=[$clip,$bytes];
        };
        $outcome=(new RenderClipHandler($clips,new RenderHandlerProjects(),$renderer,new RenderHandlerStorage(),$guard,100,20,new RenderHandlerCleanups(),new RenderHandlerProfiles(),180,$editor,$quota))->handle($this->job());
        self::assertSame('completed',$outcome->status());
        self::assertSame('Title',$renderer->request->editorOptions()->title());
        self::assertSame([[7,22]],$calls);
    }

    public function testPendingEditorTranscriptNeverRendersWithoutItsRequestedSubtitles(): void
    {
        $renderer=new RenderHandlerRenderer($this->artifacts());
        $editor=new class {
            public function snapshot(int $clip,int $revision): array {
                return ['options'=>\App\Media\Editor\EditorOptions::fromArray(['style'=>'viral']),'mode'=>'auto','track_status'=>'pending','duration_ms'=>22750,'transcript'=>null];
            }
        };
        $outcome=(new RenderClipHandler(new RenderHandlerClips($this->context()),new RenderHandlerProjects(),$renderer,new RenderHandlerStorage(),new RenderHandlerGuard(),100,20,new RenderHandlerCleanups(),new RenderHandlerProfiles(),180,$editor))->handle($this->job());
        self::assertSame('deferred',$outcome->status());
        self::assertSame(0,$renderer->calls);
    }

    public function testMissingEditorSnapshotFailsClosedBeforeRenderingOrPublication(): void
    {
        $clips=new RenderHandlerClips($this->context());
        $renderer=new RenderHandlerRenderer($this->artifacts());

        $outcome=(new RenderClipHandler(
            $clips,new RenderHandlerProjects(),$renderer,new RenderHandlerStorage(),new RenderHandlerGuard(),100,20,
            new RenderHandlerCleanups(),new RenderHandlerProfiles(),180,null
        ))->handle($this->job());

        self::assertSame('failed',$outcome->status());
        self::assertSame('subtitle_failed',$outcome->code());
        self::assertSame(['failed:subtitle_failed'],$clips->events);
        self::assertSame(0,$renderer->calls);
    }

    public function testEditorSnapshotDatabaseFailureDefersWithoutTerminalizingClip(): void
    {
        $clips=new RenderHandlerClips($this->context());
        $projects=new RenderHandlerProjects();
        $renderer=new RenderHandlerRenderer($this->artifacts());
        $editor=new class {
            public function snapshot(int $clip,int $revision): array {
                throw new \PDOException('database unavailable');
            }
        };

        $outcome=(new RenderClipHandler(
            $clips,$projects,$renderer,new RenderHandlerStorage(),new RenderHandlerGuard(),100,20,
            new RenderHandlerCleanups(),new RenderHandlerProfiles(),180,$editor
        ))->handle($this->job());

        self::assertSame('deferred',$outcome->status());
        self::assertSame(15,$outcome->delaySeconds());
        self::assertSame([],$clips->events);
        self::assertSame([],$projects->synchronized);
        self::assertSame(0,$renderer->calls);
    }

    public function testStorageQuotaFailureRemovesOnlyNewArtifactsAndFailsWithoutRetry(): void
    {
        $clips=new RenderHandlerClips($this->context());
        $storage=new RenderHandlerStorage();
        $quota=static function(int $clip,int $bytes): void {
            throw new \App\Plans\PlanLimitExceeded('storage_limit_exceeded',10,10,$bytes);
        };
        $outcome=(new RenderClipHandler($clips,new RenderHandlerProjects(),new RenderHandlerRenderer($this->artifacts()),$storage,new RenderHandlerGuard(),100,20,new RenderHandlerCleanups(),new RenderHandlerProfiles(),180,$this->captionedEditor(),$quota))->handle($this->job());
        self::assertSame('failed',$outcome->status());
        self::assertSame('storage_limit_exceeded',$outcome->code());
        self::assertSame(['rendering','failed:storage_limit_exceeded'],$clips->events);
        self::assertSame($storage->keys,$storage->deleted);
        self::assertNull($clips->video);
    }

    public function testInactiveAccountQuotaFailureIsTerminalWithoutRerendering(): void
    {
        $clips=new RenderHandlerClips($this->context());
        $storage=new RenderHandlerStorage();
        $quota=static function(int $clip,int $bytes): void {
            throw new \App\Plans\PlanLimitExceeded('account_inactive',0,0,$bytes);
        };

        $outcome=(new RenderClipHandler(
            $clips,new RenderHandlerProjects(),new RenderHandlerRenderer($this->artifacts()),$storage,
            new RenderHandlerGuard(),100,20,new RenderHandlerCleanups(),new RenderHandlerProfiles(),180,$this->captionedEditor(),$quota
        ))->handle($this->job());

        self::assertSame('failed',$outcome->status());
        self::assertSame('account_inactive',$outcome->code());
        self::assertSame(['rendering','failed:account_inactive'],$clips->events);
        self::assertSame($storage->keys,$storage->deleted);
        self::assertNull($clips->video);
    }

    /** @dataProvider invalidPayloadProvider */
    public function testRejectsPayloadUnlessItContainsExactlyTwoRealPositiveIntegers(array $payload): void
    {
        $clips = new RenderHandlerClips($this->context());
        $renderer = new RenderHandlerRenderer($this->artifacts());
        $profiles = new RenderHandlerProfiles();
        $handler = new RenderClipHandler($clips, new RenderHandlerProjects(), $renderer, new RenderHandlerStorage(), new RenderHandlerGuard(), 100, 20, new RenderHandlerCleanups(), $profiles);

        $outcome = $handler->handle($this->job(1, 3, $payload));

        self::assertSame('failed', $outcome->status());
        self::assertSame('render_failed', $outcome->code());
        self::assertSame(0, $clips->lookups);
        self::assertSame([], $profiles->requests);
        self::assertSame(0, $renderer->calls);
    }

    public function invalidPayloadProvider(): iterable
    {
        yield 'missing' => [['clip_id' => 7]];
        yield 'extra' => [['clip_id' => 7, 'render_revision' => 1, 'path' => 'x']];
        yield 'string clip' => [['clip_id' => '7', 'render_revision' => 1]];
        yield 'string revision' => [['clip_id' => 7, 'render_revision' => '1']];
        yield 'zero' => [['clip_id' => 0, 'render_revision' => 1]];
        yield 'boolean' => [['clip_id' => 7, 'render_revision' => true]];
    }

    public function testRejectsProjectMismatchBeforeRenderer(): void
    {
        $context = $this->context();
        $context['project_id'] = 99;
        $clips = new RenderHandlerClips($context);
        $renderer = new RenderHandlerRenderer($this->artifacts());

        $profiles = new RenderHandlerProfiles();
        $outcome = (new RenderClipHandler($clips, new RenderHandlerProjects(), $renderer, new RenderHandlerStorage(), new RenderHandlerGuard(), 100, 20, new RenderHandlerCleanups(), $profiles))->handle($this->job());

        self::assertSame('failed', $outcome->status());
        self::assertSame('render_failed', $outcome->code());
        self::assertSame(0, $renderer->calls);
        self::assertSame([], $clips->events);
        self::assertSame([], $profiles->requests);
    }

    public function testRejectsAQueuedIntervalAboveTheConfiguredDurationLimitBeforeRendering(): void
    {
        $clips = new RenderHandlerClips($this->context());
        $renderer = new RenderHandlerRenderer($this->artifacts());
        $handler = new RenderClipHandler(
            $clips,
            new RenderHandlerProjects(),
            $renderer,
            new RenderHandlerStorage(),
            new RenderHandlerGuard(),
            100,
            20,
            new RenderHandlerCleanups(),
            new RenderHandlerProfiles(),
            20
        );

        $outcome = $handler->handle($this->job());

        self::assertSame('failed', $outcome->status());
        self::assertSame('render_failed', $outcome->code());
        self::assertSame(0, $renderer->calls);
        self::assertSame([], $clips->events);
    }

    public function testStaleRevisionOldAnalysisUnreadySourceAndCompletedReplayAreIdempotentNoOps(): void
    {
        $clips = new RenderHandlerClips(null);
        $renderer = new RenderHandlerRenderer($this->artifacts());

        $profiles = new RenderHandlerProfiles();
        $outcome = (new RenderClipHandler($clips, new RenderHandlerProjects(), $renderer, new RenderHandlerStorage(), new RenderHandlerGuard(), 100, 20, new RenderHandlerCleanups(), $profiles))->handle($this->job());

        self::assertSame('completed', $outcome->status());
        self::assertSame(0, $renderer->calls);
        self::assertSame([], $clips->events);
        self::assertSame([], $profiles->requests);
    }

    public function testInitialLeaseRejectionDefersWithoutRendering(): void
    {
        $clips = new RenderHandlerClips($this->context());
        $renderer = new RenderHandlerRenderer($this->artifacts());
        $profiles = new RenderHandlerProfiles();
        $outcome = (new RenderClipHandler($clips, new RenderHandlerProjects(), $renderer, new RenderHandlerStorage(), new RenderHandlerGuard([false]), 100, 20, new RenderHandlerCleanups(), $profiles))->handle($this->job());

        self::assertSame('deferred', $outcome->status());
        self::assertSame(15, $outcome->delaySeconds());
        self::assertSame(0, $renderer->calls);
        self::assertSame([], $clips->events);
        self::assertSame([[7, 1]], $profiles->requests);
    }

    public function testUnavailableRendererReturnsQueuedAndDefersForFiveMinutes(): void
    {
        $clips = new RenderHandlerClips($this->context());
        $renderer = new RenderHandlerRenderer(null, ClipRenderException::withCode('render_unavailable'));
        $outcome = (new RenderClipHandler($clips, new RenderHandlerProjects(), $renderer, new RenderHandlerStorage(), new RenderHandlerGuard(), 100, 20, new RenderHandlerCleanups(), new RenderHandlerProfiles(), 180, $this->captionedEditor()))->handle($this->job());

        self::assertSame('deferred', $outcome->status());
        self::assertSame(300, $outcome->delaySeconds());
        self::assertSame(['rendering', 'queued'], $clips->events);
    }

    public function testTransientFailureReturnsQueuedAndRetry(): void
    {
        $clips = new RenderHandlerClips($this->context());
        $renderer = new RenderHandlerRenderer(null, ClipRenderException::withCode('render_timeout'));
        $outcome = (new RenderClipHandler($clips, new RenderHandlerProjects(), $renderer, new RenderHandlerStorage(), new RenderHandlerGuard(), 100, 20, new RenderHandlerCleanups(), new RenderHandlerProfiles(), 180, $this->captionedEditor()))->handle($this->job());

        self::assertSame('retry', $outcome->status());
        self::assertSame('render_timeout', $outcome->code());
        self::assertSame('A renderização demorou mais que o esperado.', $outcome->publicMessage());
        self::assertSame(['rendering', 'queued'], $clips->events);
    }

    public function testExhaustedFailureMarksFailedAndSynchronizes(): void
    {
        $clips = new RenderHandlerClips($this->context());
        $projects = new RenderHandlerProjects();
        $renderer = new RenderHandlerRenderer(null, ClipRenderException::withCode('render_output_invalid'));
        $outcome = (new RenderClipHandler($clips, $projects, $renderer, new RenderHandlerStorage(), new RenderHandlerGuard(), 100, 20, new RenderHandlerCleanups(), new RenderHandlerProfiles(), 180, $this->captionedEditor()))->handle($this->job(3, 3));

        self::assertSame('failed', $outcome->status());
        self::assertSame('render_output_invalid', $outcome->code());
        self::assertSame('O vídeo gerado não passou pela validação.', $outcome->publicMessage());
        self::assertSame(['rendering', 'failed:render_output_invalid'], $clips->events);
        self::assertSame([4], $projects->synchronized);
    }

    public function testStorageFailureDeletesPartialPublicationCleansTempsAndRetries(): void
    {
        $clips = new RenderHandlerClips($this->context());
        $renderer = new RenderHandlerRenderer($this->artifacts());
        $storage = new RenderHandlerStorage(2);
        $outcome = (new RenderClipHandler($clips, new RenderHandlerProjects(), $renderer, $storage, new RenderHandlerGuard(), 100, 20, new RenderHandlerCleanups(), new RenderHandlerProfiles(), 180, $this->captionedEditor()))->handle($this->job());

        self::assertSame('retry', $outcome->status());
        self::assertSame('render_storage_failed', $outcome->code());
        self::assertSame('Não foi possível armazenar o vídeo gerado.', $outcome->publicMessage());
        self::assertSame([$storage->keys[0]], $storage->deleted);
        self::assertSame(['rendering', 'queued'], $clips->events);
        self::assertFileDoesNotExist($renderer->videoPath);
        self::assertFileDoesNotExist($renderer->thumbnailPath);
    }

    public function testLostLeaseAfterPublicationDeletesBothAndDefersWithoutCompletionOrProjectChange(): void
    {
        $clips = new RenderHandlerClips($this->context());
        $projects = new RenderHandlerProjects();
        $renderer = new RenderHandlerRenderer($this->artifacts());
        $storage = new RenderHandlerStorage();
        $outcome = (new RenderClipHandler($clips, $projects, $renderer, $storage, new RenderHandlerGuard([true, false]), 100, 20, new RenderHandlerCleanups(), new RenderHandlerProfiles(), 180, $this->captionedEditor()))->handle($this->job());

        self::assertSame('deferred', $outcome->status());
        self::assertSame(15, $outcome->delaySeconds());
        self::assertNull($outcome->code());
        self::assertSame(['rendering'], $clips->events);
        self::assertSame([], $projects->synchronized);
        self::assertSame($storage->keys, $storage->deleted);
        self::assertFileDoesNotExist($renderer->videoPath);
        self::assertFileDoesNotExist($renderer->thumbnailPath);
    }

    public function testDoesNotPublishWhenWriteAheadReservationsCannotBePersisted(): void
    {
        $clips = new RenderHandlerClips($this->context());
        $renderer = new RenderHandlerRenderer($this->artifacts());
        $storage = new RenderHandlerStorage();
        $cleanups = new RenderHandlerCleanups();
        $cleanups->reserveResult = false;
        $handler = new RenderClipHandler(
            $clips,
            new RenderHandlerProjects(),
            $renderer,
            $storage,
            new RenderHandlerGuard(),
            100,
            20,
            $cleanups,
            new RenderHandlerProfiles(),
            180,
            $this->captionedEditor()
        );

        $outcome = $handler->handle($this->job());

        self::assertSame('deferred', $outcome->status());
        self::assertSame(15, $outcome->delaySeconds());
        self::assertSame(1, $cleanups->reserveCalls);
        self::assertSame([], $storage->keys);
        self::assertFileDoesNotExist($renderer->videoPath);
        self::assertFileDoesNotExist($renderer->thumbnailPath);
    }

    public function testMatchedProfilePassesTheExactManualPlanWithoutChangingTheJobPayload(): void
    {
        $plan = ReframePlan::manual(
            AspectRatio::fromString('9:16'),
            new ReframeKeyframe(0, 0.25, 0.75, 'manual')
        );
        $profiles = new RenderHandlerProfiles(ReframePlanResolution::matched($plan));
        $renderer = new RenderHandlerRenderer($this->artifacts());
        $job = $this->job();

        $outcome = (new RenderClipHandler(
            new RenderHandlerClips($this->context()),
            new RenderHandlerProjects(),
            $renderer,
            new RenderHandlerStorage(),
            new RenderHandlerGuard(),
            100,
            20,
            new RenderHandlerCleanups(),
            $profiles,
            180,
            $this->captionedEditor()
        ))->handle($job);

        self::assertSame('completed', $outcome->status());
        self::assertSame($plan, $renderer->request?->reframePlan());
        self::assertSame([[7, 1]], $profiles->requests);
        self::assertSame(['clip_id', 'render_revision'], array_keys($job->payload()));
        self::assertSame(['clip_id' => 7, 'render_revision' => 1], $job->payload());
    }

    public function testProfileRepositoryFailureDefersBeforeTheFirstGuardAndRenderer(): void
    {
        $clips = new RenderHandlerClips($this->context());
        $projects = new RenderHandlerProjects();
        $renderer = new RenderHandlerRenderer($this->artifacts());
        $guard = new RenderHandlerGuard();
        $profiles = new RenderHandlerProfiles(null, new RuntimeException('repository unavailable'));

        $outcome = (new RenderClipHandler(
            $clips,
            $projects,
            $renderer,
            new RenderHandlerStorage(),
            $guard,
            100,
            20,
            new RenderHandlerCleanups(),
            $profiles
        ))->handle($this->job());

        self::assertSame('deferred', $outcome->status());
        self::assertSame(15, $outcome->delaySeconds());
        self::assertSame([[7, 1]], $profiles->requests);
        self::assertSame(0, $guard->calls);
        self::assertSame(0, $renderer->calls);
        self::assertSame([], $clips->events);
        self::assertSame([], $projects->synchronized);
    }

    public function testPersistentProfileMismatchFailsSanitizedWithoutRenderingOrRetry(): void
    {
        $clips = new RenderHandlerClips($this->context());
        $projects = new RenderHandlerProjects();
        $renderer = new RenderHandlerRenderer($this->artifacts());
        $guard = new RenderHandlerGuard();
        $profiles = new RenderHandlerProfiles(ReframePlanResolution::mismatch());

        $outcome = (new RenderClipHandler(
            $clips,
            $projects,
            $renderer,
            new RenderHandlerStorage(),
            $guard,
            100,
            20,
            new RenderHandlerCleanups(),
            $profiles
        ))->handle($this->job(1, 3));

        self::assertSame('failed', $outcome->status());
        self::assertSame('render_output_invalid', $outcome->code());
        self::assertSame('O vídeo gerado não passou pela validação.', $outcome->publicMessage());
        self::assertSame(0, $renderer->calls);
        self::assertSame(['failed:render_output_invalid'], $clips->events);
        self::assertSame([4], $projects->synchronized);
        self::assertSame(1, $guard->calls);
    }

    public function testProfileMismatchLeaseLossDefersWithoutChangingClipOrProject(): void
    {
        $clips = new RenderHandlerClips($this->context());
        $projects = new RenderHandlerProjects();
        $renderer = new RenderHandlerRenderer($this->artifacts());
        $guard = new RenderHandlerGuard([false]);

        $outcome = (new RenderClipHandler(
            $clips,
            $projects,
            $renderer,
            new RenderHandlerStorage(),
            $guard,
            100,
            20,
            new RenderHandlerCleanups(),
            new RenderHandlerProfiles(ReframePlanResolution::mismatch())
        ))->handle($this->job());

        self::assertSame('deferred', $outcome->status());
        self::assertSame(15, $outcome->delaySeconds());
        self::assertSame(0, $renderer->calls);
        self::assertSame([], $clips->events);
        self::assertSame([], $projects->synchronized);
    }

    public function testProfileMismatchDatabaseFailureDefersWithoutChangingClipOrProject(): void
    {
        $clips = new RenderHandlerClips($this->context());
        $projects = new RenderHandlerProjects();
        $renderer = new RenderHandlerRenderer($this->artifacts());
        $guard = new RenderHandlerGuard([new RuntimeException('database unavailable')]);

        $outcome = (new RenderClipHandler(
            $clips,
            $projects,
            $renderer,
            new RenderHandlerStorage(),
            $guard,
            100,
            20,
            new RenderHandlerCleanups(),
            new RenderHandlerProfiles(ReframePlanResolution::mismatch())
        ))->handle($this->job());

        self::assertSame('deferred', $outcome->status());
        self::assertSame(15, $outcome->delaySeconds());
        self::assertSame(0, $renderer->calls);
        self::assertSame([], $clips->events);
        self::assertSame([], $projects->synchronized);
    }

    /** @dataProvider catalogProvider */
    public function testCatalogContainsExactRenderMessages(string $code, string $message): void
    {
        self::assertSame($message, ProcessingErrorCatalog::requireMessage($code));
    }

    public function catalogProvider(): iterable
    {
        yield ['render_timeout', 'A renderização demorou mais que o esperado.'];
        yield ['render_failed', 'Não foi possível renderizar este corte.'];
        yield ['render_output_invalid', 'O vídeo gerado não passou pela validação.'];
        yield ['render_storage_failed', 'Não foi possível armazenar o vídeo gerado.'];
    }

    private function captionedEditor(): object
    {
        return new class {
            public function snapshot(int $clip, int $revision): array {
                return ['options'=>\App\Media\Editor\EditorOptions::fromArray(['style'=>'viral']),
                    'mode'=>'auto','track_status'=>'ready','duration_ms'=>22750,
                    'transcript'=>new \App\Media\Subtitles\Transcript('pt',[new \App\Media\Subtitles\SubtitleCue(0,1000,'Legenda')],22750)];
            }
            public function markSubtitlesFailed(int $clip, int $revision, string $code): void {}
        };
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return ['id' => 7, 'project_id' => 4, 'status' => 'queued', 'render_revision' => 1,
            'render_start_time' => 12.5, 'render_end_time' => 35.25,
            'source' => new ProjectSource(10, 4, 'local', 'imports/4/source.mp4', 'video/mp4')];
    }

    private function artifacts(string $video = 'video', string $thumbnail = 'thumb'): RenderedClipArtifacts
    {
        $videoPath = tempnam(sys_get_temp_dir(), 'render-video-');
        $thumbnailPath = tempnam(sys_get_temp_dir(), 'render-thumb-');
        self::assertIsString($videoPath);
        self::assertIsString($thumbnailPath);
        file_put_contents($videoPath, $video);
        file_put_contents($thumbnailPath, $thumbnail);
        $this->temporaryFiles[] = $videoPath;
        $this->temporaryFiles[] = $thumbnailPath;
        return new RenderedClipArtifacts($videoPath, strlen($video), 'video/mp4', $thumbnailPath, strlen($thumbnail), 'image/jpeg');
    }

    private function job(int $attempts = 1, int $maxAttempts = 3, ?array $payload = null): ClaimedJob
    {
        return new ClaimedJob(41, 'media', 'render_clip', 4, $payload ?? ['clip_id' => 7, 'render_revision' => 1], 'worker-test', str_repeat('a', 64), $attempts, $maxAttempts);
    }
}

final class RenderHandlerClips
{
    public int $lookups = 0;
    public array $events = [];
    public ?StoredObject $video = null;
    public ?StoredObject $thumbnail = null;
    public function __construct(private ?array $context) {}
    public function findForRenderJob(int $clipId, int $revision): ?array { ++$this->lookups; return $this->context; }
    public function markRendering(int $clipId, int $revision): void { $this->events[] = 'rendering'; }
    public function markRenderQueued(int $clipId, int $revision): void { $this->events[] = 'queued'; }
    public function markRenderFailed(int $clipId, int $revision, string $code): void { $this->events[] = 'failed:' . $code; }
    public function completeRender(int $clipId, int $revision, StoredObject $video, StoredObject $thumbnail): void
    { $this->events[] = 'completed'; $this->video = $video; $this->thumbnail = $thumbnail; }
}

final class RenderHandlerProfiles implements ClipRenderProfileStore
{
    /** @var list<array{int,int}> */
    public array $requests = [];

    public function __construct(
        private ?ReframePlanResolution $resolution = null,
        private ?\Throwable $exception = null
    ) {
    }

    public function resolveForJob(int $clipId, int $renderRevision): ReframePlanResolution
    {
        $this->requests[] = [$clipId, $renderRevision];
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->resolution ?? ReframePlanResolution::legacy();
    }
}

final class RenderHandlerProjects
{
    public array $synchronized = [];
    public function synchronizeRenderState(int $projectId): void { $this->synchronized[] = $projectId; }
}

final class RenderHandlerRenderer implements ClipRenderer
{
    public int $calls = 0;
    public ?RenderClipRequest $request = null;
    public string $videoPath = '';
    public string $thumbnailPath = '';
    public function __construct(private ?RenderedClipArtifacts $artifacts, private ?\Throwable $exception = null)
    { if ($artifacts !== null) { $this->videoPath = $artifacts->videoPath(); $this->thumbnailPath = $artifacts->thumbnailPath(); } }
    public function render(RenderClipRequest $request): RenderedClipArtifacts
    {
        ++$this->calls; $this->request = $request;
        if ($this->exception !== null) { throw $this->exception; }
        if ($this->artifacts === null) { throw new RuntimeException('No artifacts.'); }
        return $this->artifacts;
    }
}

final class RenderHandlerStorage implements PrivateStorage
{
    public array $keys = [];
    public array $limits = [];
    public array $streamModes = [];
    public array $contents = [];
    public array $deleted = [];
    private int $puts = 0;
    public function __construct(private ?int $failOnPut = null) {}
    public function putUploaded(string $temporaryPath, string $objectKey): StoredObject { throw new RuntimeException('Not used.'); }
    public function putStream(mixed $stream, string $objectKey, int $maxBytes): StoredObject
    {
        ++$this->puts; $this->keys[] = $objectKey; $this->limits[] = $maxBytes;
        $this->streamModes[] = (string) (stream_get_meta_data($stream)['mode'] ?? '');
        if ($this->failOnPut === $this->puts) { throw new RuntimeException('storage failure'); }
        $contents = stream_get_contents($stream);
        if (!is_string($contents)) { throw new RuntimeException('stream failure'); }
        $this->contents[] = $contents;
        return new StoredObject($objectKey, strlen($contents), hash('sha256', $contents));
    }
    public function absolutePath(string $objectKey): string { return 'C:/private/' . $objectKey; }
    public function delete(string $objectKey): void { $this->deleted[] = $objectKey; }
}

final class RenderHandlerGuard implements ProcessingEffectGuard
{
    public int $calls = 0;
    public function __construct(private array $results = []) {}
    public function apply(ClaimedJob $job, callable $effect): bool
    {
        $result = $this->results[$this->calls] ?? true; ++$this->calls;
        if ($result instanceof \Throwable) { throw $result; }
        if (!$result) { return false; }
        $effect(); return true;
    }
}

final class RenderHandlerCleanups implements RenderArtifactCleanupStore
{
    public bool $reserveResult = true;
    public int $reserveCalls = 0;
    /** @var list<string> */
    private array $keys = [];

    /** @param list<string> $objectKeys */
    public function reserve(ClaimedJob $job, array $objectKeys): bool
    {
        ++$this->reserveCalls;
        if ($this->reserveResult) {
            foreach ($objectKeys as $key) {
                if (!in_array($key, $this->keys, true)) {
                    $this->keys[] = $key;
                }
            }
        }

        return $this->reserveResult;
    }

    /** @param list<string> $objectKeys */
    public function markForCleanup(ClaimedJob $job, array $objectKeys): void
    {
    }

    /** @param list<string> $objectKeys */
    public function release(ClaimedJob $job, array $objectKeys): void
    {
        foreach ($objectKeys as $key) {
            $this->forget($key);
        }
    }

    public function pending(int $limit = 25): array
    {
        return array_slice($this->keys, 0, $limit);
    }

    public function forget(string $objectKey): void
    {
        $this->keys = array_values(array_filter(
            $this->keys,
            static fn (string $key): bool => $key !== $objectKey
        ));
    }
}
