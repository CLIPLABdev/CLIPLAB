<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\ClipAudioExtractor;
use App\Contracts\TimedTranscriptionProvider;
use App\Contracts\JobDispatcher;
use App\Exceptions\SubtitleException;
use App\Gemini\GeminiException;
use App\Media\ProjectSource;
use App\Media\Editor\EditorOptions;
use App\Media\Subtitles\Transcript;
use App\Media\Subtitles\TranscriptValidator;
use App\Queue\ClaimedJob;
use App\Queue\GenerateSubtitlesHandler;
use App\Queue\ProcessingEffectGuard;
use PHPUnit\Framework\TestCase;

final class GenerateSubtitlesHandlerTest extends TestCase
{
    private function fixtures(): array
    {
        $editor=new class {
            public string $status='pending';
            public string $mode='auto';
            public int $duration=2000;
            public EditorOptions $options;
            public ?\Throwable $snapshotError=null;
            public ?\Throwable $saveError=null;
            public function __construct() { $this->options=EditorOptions::fromArray(['style'=>'viral']); }
            public ?Transcript $saved=null;
            public function snapshot(int $clip,int $revision): ?array { if ($this->snapshotError!==null) throw $this->snapshotError; return ['options'=>$this->options,'transcript'=>$this->saved,'track_status'=>$this->status,'mode'=>$this->mode,'duration_ms'=>$this->duration]; }
            public function saveTranscript(int $clip,int $revision,Transcript $transcript): void { if ($this->saveError!==null) throw $this->saveError; $this->saved=$transcript; $this->status='ready'; }
            public function markSubtitlesFailed(int $clip,int $revision,string $code): void { $this->status='failed'; }
        };
        $clips=new class {
            public bool $obsolete=false;
            public ?string $error=null;
            public function findForRenderJob(int $clip,int $revision): ?array { return $this->obsolete ? null : ['id'=>7,'project_id'=>4,'render_revision'=>1,'render_start_time'=>10.0,'render_end_time'=>12.0,'source'=>new ProjectSource(3,4,'local','source.mp4','video/mp4')]; }
            public function markRenderFailed(int $clip,int $revision,string $error): void { $this->error=$error; }
        };
        $projects=new class { public function synchronizeRenderState(int $project): void {} };
        $audio=new class implements ClipAudioExtractor {
            public int $calls=0;
            public ?string $failure=null;
            public function extract(ProjectSource $source,float $start,float $duration): string {
                ++$this->calls;
                if ($this->failure!==null) throw SubtitleException::withCode($this->failure);
                return 'fake-test-wav';
            }
        };
        $provider=new class implements TimedTranscriptionProvider {
            public ?string $failure=null;
            public ?Transcript $result=null;
            public int $calls=0;
            public function transcribe(string $bytes,int $duration): Transcript {
                ++$this->calls;
                if ($this->failure) throw GeminiException::withCode($this->failure);
                if ($this->result!==null) return $this->result;
                return TranscriptValidator::fromArray(['language'=>'pt','cues'=>[['start_ms'=>0,'end_ms'=>1000,'text'=>'Olá']]],$duration);
            }
        };
        $guard=new class implements ProcessingEffectGuard {
            public int $calls=0;
            public int $denyAt=0;
            public function apply(ClaimedJob $job,callable $effect): bool { ++$this->calls; if ($this->calls===$this->denyAt) return false; $effect(); return true; }
        };
        $dispatcher=new class($editor) implements JobDispatcher {
            public array $calls=[];
            public function __construct(private object $editor) {}
            public function dispatch(string $type,int $project,array $payload,string $key): int {
                if ($this->editor->status!=='ready') throw new \RuntimeException('Must persist transcript first.');
                $this->calls[]=compact('type','project','payload','key'); return 1;
            }
        };
        return [$editor,$clips,$projects,$audio,$provider,$guard,$dispatcher];
    }

    private function job(int $attempt=1): ClaimedJob { return new ClaimedJob(1,'media','generate_subtitles',4,['clip_id'=>7,'render_revision'=>1],'test','lease',$attempt,3); }

    public function testPersistsValidatedTranscriptBeforeDispatchAndReadyRetrySkipsProvider(): void
    {
        [$editor,$clips,$projects,$audio,$provider,$guard,$dispatcher]=$this->fixtures();
        $handler=new GenerateSubtitlesHandler($editor,$clips,$projects,$audio,$provider,$guard,$dispatcher);
        self::assertSame('completed',$handler->handle($this->job())->status());
        self::assertSame('Olá',$editor->saved->cues()[0]->text());
        self::assertSame('render_clip',$dispatcher->calls[0]['type']);
        self::assertSame('clip-render:7:v1',$dispatcher->calls[0]['key']);
        self::assertSame('completed',$handler->handle($this->job())->status());
        self::assertSame(1,$audio->calls);
        self::assertSame(1,$provider->calls);
    }

    public function testLostLeaseCannotPersistOrDispatch(): void
    {
        [$editor,$clips,$projects,$audio,$provider,$guard,$dispatcher]=$this->fixtures();
        $guard->denyAt=2;
        $outcome=(new GenerateSubtitlesHandler($editor,$clips,$projects,$audio,$provider,$guard,$dispatcher))->handle($this->job());
        self::assertSame('deferred',$outcome->status());
        self::assertNull($editor->saved);
        self::assertSame([],$dispatcher->calls);
    }

    public function testTransientRetryDoesNotFailClipUntilFinalAttempt(): void
    {
        [$editor,$clips,$projects,$audio,$provider,$guard,$dispatcher]=$this->fixtures();
        $provider->failure='ai_rate_limited';
        $handler=new GenerateSubtitlesHandler($editor,$clips,$projects,$audio,$provider,$guard,$dispatcher);
        self::assertSame('retry',$handler->handle($this->job())->status());
        self::assertSame('pending',$editor->status);
        self::assertNull($clips->error);
        self::assertSame('failed',$handler->handle($this->job(3))->status());
        self::assertSame('failed',$editor->status);
        self::assertSame('ai_rate_limited',$clips->error);
    }

    public function testUnavailableAudioProcessorRetriesBeforeTerminalFailure(): void
    {
        [$editor,$clips,$projects,$audio,$provider,$guard,$dispatcher]=$this->fixtures();
        $audio->failure='subtitle_unavailable';
        $handler=new GenerateSubtitlesHandler($editor,$clips,$projects,$audio,$provider,$guard,$dispatcher);

        $retry=$handler->handle($this->job());
        self::assertSame('retry',$retry->status());
        self::assertSame('subtitle_unavailable',$retry->code());
        self::assertSame('pending',$editor->status);
        self::assertNull($clips->error);

        $failed=$handler->handle($this->job(3));
        self::assertSame('failed',$failed->status());
        self::assertSame('subtitle_unavailable',$failed->code());
        self::assertSame('failed',$editor->status);
        self::assertSame('subtitle_unavailable',$clips->error);
        self::assertSame([],$dispatcher->calls);
    }

    public function testProviderDurationMismatchFailsTerminallyBeforePersistence(): void
    {
        [$editor,$clips,$projects,$audio,$provider,$guard,$dispatcher]=$this->fixtures();
        $provider->result=TranscriptValidator::fromArray(
            ['language'=>'pt','cues'=>[['start_ms'=>0,'end_ms'=>1000,'text'=>'Curto']]],
            1000
        );

        $outcome=(new GenerateSubtitlesHandler($editor,$clips,$projects,$audio,$provider,$guard,$dispatcher))->handle($this->job());

        self::assertSame('failed',$outcome->status());
        self::assertSame('subtitle_failed',$outcome->code());
        self::assertNull($editor->saved);
        self::assertSame('failed',$editor->status);
        self::assertSame('subtitle_failed',$clips->error);
        self::assertSame([],$dispatcher->calls);
    }

    public function testAutoTranscriptWithoutVisibleCuesFailsClosedWithoutDispatchingRender(): void
    {
        [$editor,$clips,$projects,$audio,$provider,$guard,$dispatcher]=$this->fixtures();
        $provider->result=TranscriptValidator::fromArray(['language'=>'und','cues'=>[]],2000);

        $outcome=(new GenerateSubtitlesHandler($editor,$clips,$projects,$audio,$provider,$guard,$dispatcher))->handle($this->job());

        self::assertSame('failed',$outcome->status());
        self::assertSame('subtitle_empty',$outcome->code());
        self::assertSame('failed',$editor->status);
        self::assertSame('subtitle_empty',$clips->error);
        self::assertSame([],$dispatcher->calls);
    }

    public function testReadyManualTranscriptIsReusedWithoutCallingTheProvider(): void
    {
        [$editor,$clips,$projects,$audio,$provider,$guard,$dispatcher]=$this->fixtures();
        $editor->mode='manual';
        $editor->status='ready';
        $editor->saved=TranscriptValidator::fromArray(['language'=>'pt','cues'=>[['start_ms'=>0,'end_ms'=>1000,'text'=>'Texto revisado']]],2000);

        $outcome=(new GenerateSubtitlesHandler($editor,$clips,$projects,$audio,$provider,$guard,$dispatcher))->handle($this->job());

        self::assertSame('completed',$outcome->status());
        self::assertSame(0,$audio->calls);
        self::assertSame(0,$provider->calls);
        self::assertSame('Texto revisado',$editor->saved->cues()[0]->text());
        self::assertSame('render_clip',$dispatcher->calls[0]['type']);
    }

    public function testPendingManualTrackFailsClosedWithoutCallingTheProvider(): void
    {
        [$editor,$clips,$projects,$audio,$provider,$guard,$dispatcher]=$this->fixtures();
        $editor->mode='manual';

        $outcome=(new GenerateSubtitlesHandler($editor,$clips,$projects,$audio,$provider,$guard,$dispatcher))->handle($this->job());

        self::assertSame('failed',$outcome->status());
        self::assertSame('subtitle_failed',$outcome->code());
        self::assertSame('failed',$editor->status);
        self::assertSame('subtitle_failed',$clips->error);
        self::assertSame(0,$audio->calls);
        self::assertSame(0,$provider->calls);
        self::assertSame([],$dispatcher->calls);
    }


    public function testMalformedSnapshotFailsClosedInsteadOfDeferringForever(): void
    {
        [$editor,$clips,$projects,$audio,$provider,$guard,$dispatcher]=$this->fixtures();
        $editor->snapshotError=new \JsonException('malformed profile');

        $outcome=(new GenerateSubtitlesHandler($editor,$clips,$projects,$audio,$provider,$guard,$dispatcher))->handle($this->job());

        self::assertSame('failed',$outcome->status());
        self::assertSame('subtitle_failed',$outcome->code());
        self::assertSame('failed',$editor->status);
        self::assertSame('subtitle_failed',$clips->error);
        self::assertSame([],$dispatcher->calls);
    }

    public function testPersistentTranscriptSaveFailureTerminalizesAtTheAttemptLimit(): void
    {
        [$editor,$clips,$projects,$audio,$provider,$guard,$dispatcher]=$this->fixtures();
        $editor->saveError=new \LogicException('invalid track state');

        $outcome=(new GenerateSubtitlesHandler($editor,$clips,$projects,$audio,$provider,$guard,$dispatcher))->handle($this->job(3));

        self::assertSame('failed',$outcome->status());
        self::assertSame('subtitle_failed',$outcome->code());
        self::assertSame('failed',$editor->status);
        self::assertSame('subtitle_failed',$clips->error);
        self::assertSame([],$dispatcher->calls);
    }

    public function testObsoleteJobHasNoSideEffects(): void
    {
        [$editor,$clips,$projects,$audio,$provider,$guard,$dispatcher]=$this->fixtures();
        $clips->obsolete=true;
        self::assertSame('completed',(new GenerateSubtitlesHandler($editor,$clips,$projects,$audio,$provider,$guard,$dispatcher))->handle($this->job())->status());
        self::assertSame(0,$provider->calls);
        self::assertSame(0,$guard->calls);
    }

    public function testInvalidPayloadFailsBeforeLookupOrProvider(): void
    {
        $items=$this->fixtures();
        $job=new ClaimedJob(1,'media','generate_subtitles',4,['clip_id'=>'7','render_revision'=>1],'test','lease',1,3);
        self::assertSame('failed',(new GenerateSubtitlesHandler(...$items))->handle($job)->status());
        self::assertSame(0,$items[4]->calls);
    }
}
