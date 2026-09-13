<?php

declare(strict_types=1);
namespace Tests\Unit;

use App\Contracts\JobDispatcher;
use App\Exceptions\ClipRenderValidationException;
use App\Media\Editor\EditorOptions;
use App\Media\Reframe\{ReframePlanValidator,ReframeSubmission};
use App\Repositories\{ClipEditorRepository,ClipRepository,ClipRenderProfileRepository,ProjectRepository,ProjectSourceRepository};
use App\Services\{ClipEditService,SourceDurationPreflight};
use PHPUnit\Framework\TestCase;
use Tests\Support\{EofTestDatabase,PreciseMediaProcessor};

final class ClipEditPrecisionTest extends TestCase
{
    private function fixture(): array
    {
        $pdo=new EofTestDatabase();
        $probe=new PreciseMediaProcessor($pdo,45011);
        $jobs=new class implements JobDispatcher {
            public array $calls=[];
            public function dispatch(string $type,int $projectId,array $payload,string $idempotencyKey):int {
                $this->calls[]=[$type,$projectId,$payload,$idempotencyKey]; return count($this->calls);
            }
        };
        $editor=new ClipEditorRepository($pdo);
        $service=new ClipEditService($pdo,$editor,new ClipRepository($pdo),new ProjectRepository($pdo),
            new ClipRenderProfileRepository($pdo),new ReframePlanValidator(),$jobs,180,null,
            new SourceDurationPreflight($pdo,new ProjectSourceRepository($pdo),$probe));
        return [$pdo,$probe,$jobs,$editor,$service];
    }
    private function request(ClipEditService $service,string $end,int $owner=7,string $srt="1\n00:00:00,000 --> 00:00:01,000\nOriginal text"): mixed
    {
        return $service->request(41,$owner,str_repeat('b',64),'0',$end,EditorOptions::fromArray(['style'=>'minimal']),
            ReframeSubmission::original(),'manual',$srt);
    }
    public function testRejectsCeilingWithoutChangingSrtThenCreatesExactVersionOnce():void
    {
        [$pdo,$probe,$jobs,$editor,$service]=$this->fixture();
        try {
            $this->request($service,'46');
            self::fail('Editor accepted the rounded EOF.');
        } catch (ClipRenderValidationException $error) {
            self::assertStringContainsString('45.011',$error->errors()['end_time']);
        }
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM clips')->fetchColumn());
        self::assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM clip_editor_profiles')->fetchColumn());
        self::assertSame([],$jobs->calls);
        $receipt=$this->request($service,'45.011');
        self::assertTrue($receipt->created());
        $snapshot=$editor->snapshot($receipt->clipId(),1);
        self::assertSame(45011,$snapshot['duration_ms']);
        self::assertSame('Original text',$snapshot['transcript']->cues()[0]->text());
        self::assertSame(1000,$snapshot['transcript']->cues()[0]->endMs());
        self::assertSame(46.0,(float)$pdo->query('SELECT end_time FROM clips WHERE id=41')->fetchColumn());
        self::assertSame(46,(int)$pdo->query('SELECT duration_seconds FROM project_sources')->fetchColumn());
        self::assertSame(64,(int)$pdo->query('SELECT credits FROM users WHERE id=7')->fetchColumn());
        $probe->fail=true;
        self::assertFalse($this->request($service,'46')->created());
        self::assertSame(2,$probe->calls);
        self::assertCount(1,$jobs->calls);
    }
    public function testForeignOwnerNeverProbesAndProbeFailureNeverCreatesVersion():void
    {
        [$pdo,$probe,$jobs,$editor,$service]=$this->fixture();
        self::assertNull($this->request($service,'45.011',8));
        self::assertSame(0,$probe->calls);
        $probe->fail=true;
        try {
            $this->request($service,'45.011');
            self::fail('Missing precise measurement was accepted.');
        } catch (ClipRenderValidationException $error) {
            self::assertArrayHasKey('end_time',$error->errors());
            self::assertStringNotContainsString('Private',json_encode($error->errors()));
        }
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM clips')->fetchColumn());
        self::assertSame([],$jobs->calls);
    }
    public function testSourceChangedBetweenProbeAndLockIsRejected():void
    {
        [$pdo,$probe,$jobs,$editor,$service]=$this->fixture();
        $probe->afterInspect=static function()use($pdo):void { $pdo->exec("UPDATE project_sources SET object_key='changed.mp4'"); };
        try {
            $this->request($service,'45.011');
            self::fail('Changed source identity was accepted.');
        } catch (ClipRenderValidationException $error) {
            self::assertArrayHasKey('end_time',$error->errors());
        }
        self::assertSame([],$jobs->calls);
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM clips')->fetchColumn());
    }
    public function testSrtBeyondPreciseIntervalIsRejectedRatherThanClamped():void
    {
        [$pdo,$probe,$jobs,$editor,$service]=$this->fixture();
        try {
            $this->request($service,'45.011',7,"1\n00:00:44,000 --> 00:00:45,012\nKeep this unchanged");
            self::fail('Out-of-bounds SRT was silently clamped.');
        } catch (ClipRenderValidationException $error) {
            self::assertArrayHasKey('subtitles',$error->errors());
        }
        self::assertSame([],$jobs->calls);
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM clips')->fetchColumn());
    }

    public function testRejectsUncaptionedSubmissionBeforeCreatingAVersion(): void
    {
        [$pdo,$probe,$jobs,$editor,$service]=$this->fixture();
        try { $service->request(41,7,hash('sha256','uncaptioned'),'0','2',EditorOptions::fromArray([]),ReframeSubmission::original(),'none',''); self::fail('An uncaptioned submission was accepted.'); }
        catch (ClipRenderValidationException $error) { self::assertArrayHasKey('subtitles',$error->errors()); }
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM clips')->fetchColumn());
        self::assertSame([],$jobs->calls);
    }

    public function testRejectsNoneStyleAndEmptyManualSubmissionsBeforeClone(): void
    {
        [$pdo,$probe,$jobs,$editor,$service]=$this->fixture();
        $cases=[
            ['auto',EditorOptions::fromArray([]),''],
            ['manual',EditorOptions::fromArray([]),"1\n00:00:00,000 --> 00:00:01,000\nTexto"],
            ['manual',EditorOptions::fromArray(['style'=>'minimal']),''],
        ];
        foreach($cases as $index=>[$mode,$options,$srt]) {
            try { $service->request(41,7,hash('sha256','caption-rule-'.$index),'0','2',$options,ReframeSubmission::original(),$mode,$srt); self::fail('Invalid caption request was accepted.'); }
            catch(ClipRenderValidationException $error) { self::assertArrayHasKey('subtitles',$error->errors()); }
        }
        self::assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM clips')->fetchColumn());
        self::assertSame([],$jobs->calls);
    }

    public function testAutoSubmissionCreatesPendingTrackAndQueuesSubtitlePipeline(): void
    {
        [$pdo,$probe,$jobs,$editor,$service]=$this->fixture();
        $receipt=$service->request(41,7,hash('sha256','auto-pending'),'0','2',EditorOptions::fromArray(['style'=>'viral']),ReframeSubmission::original(),'auto','');
        self::assertTrue($receipt->created());
        self::assertSame('pending',$editor->snapshot($receipt->clipId(),1)['track_status']);
        self::assertSame(['generate_subtitles'],array_column($jobs->calls,0));
        self::assertSame('clip-subtitles:'.$receipt->clipId().':v1',$jobs->calls[0][3]);
    }

    public function testManualSameIntervalPreservesWordTimingsAndQueuesSubtitlePipeline(): void
    {
        [$pdo,$probe,$jobs,$editor,$service]=$this->fixture();
        $pdo->exec('UPDATE clips SET end_time=45.011, render_end_time=45.011 WHERE id=41');
        $original=new \App\Media\Subtitles\Transcript('pt-BR',[new \App\Media\Subtitles\SubtitleCue(0,1000,'Oi mundo',[['start_ms'=>0,'end_ms'=>400,'text'=>'Oi'],['start_ms'=>600,'end_ms'=>1000,'text'=>'mundo']])],45011);
        $pdo->beginTransaction();
        $editor->createProfile(41,1,41,7,hash('sha256','aligned-original'),EditorOptions::fromArray(['style'=>'karaoke']),'auto',45011);
        $editor->saveTranscript(41,1,$original);
        $pdo->commit();
        $receipt=$service->request(41,7,hash('sha256','aligned-manual'),'0','45.011',EditorOptions::fromArray(['style'=>'karaoke']),ReframeSubmission::original(),'manual',"1\n00:00:00,000 --> 00:00:01,000\nOlá planeta");
        $saved=$editor->snapshot($receipt->clipId(),1)['transcript'];
        self::assertSame('pt-BR',$saved->language());
        self::assertSame(['start_ms'=>0,'end_ms'=>400,'text'=>'Olá'],$saved->cues()[0]->words()[0]);
        self::assertSame(['generate_subtitles'],array_column($jobs->calls,0));
    }

    public function testNoAudioRejectsAutoButAcceptsNonEmptyManualCaptions(): void
    {
        [$pdo,$probe,$jobs,$editor,$service]=$this->fixture();
        $pdo->exec('UPDATE project_sources SET has_audio=0 WHERE id=21');
        try { $service->request(41,7,hash('sha256','no-audio-auto'),'0','2',EditorOptions::fromArray(['style'=>'minimal']),ReframeSubmission::original(),'auto',''); self::fail('Automatic captions without audio were accepted.'); }
        catch(ClipRenderValidationException $error) { self::assertArrayHasKey('subtitles',$error->errors()); }
        $receipt=$service->request(41,7,hash('sha256','no-audio-manual'),'0','2',EditorOptions::fromArray(['style'=>'minimal']),ReframeSubmission::original(),'manual',"1\n00:00:00,000 --> 00:00:01,000\nTexto revisado");
        self::assertTrue($receipt->created());
        self::assertSame('ready',$editor->snapshot($receipt->clipId(),1)['track_status']);
        self::assertSame(['generate_subtitles'],array_column($jobs->calls,0));
    }
}
