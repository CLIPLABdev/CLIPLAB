<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Migrator;
use App\Media\Editor\EditorOptions;
use App\Media\Reframe\ReframePlanValidator;
use App\Media\Reframe\ReframeSubmission;
use App\Repositories\ClipEditorRepository;
use App\Repositories\ClipRepository;
use App\Repositories\ClipRenderProfileRepository;
use App\Repositories\ProjectRepository;
use App\Services\ClipEditService;
use App\Services\DatabaseJobDispatcher;
use App\Exceptions\ClipRenderValidationException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SafePhase5TestDatabase;

final class ClipEditorWorkflowTest extends TestCase
{
    private PDO $pdo;
    private int $owner;
    private int $foreign;
    private int $project;
    private int $clip;
    private int $plan;
    private ClipEditorRepository $editor;
    private ClipEditService $service;

    protected function setUp(): void
    {
        $dsn = SafePhase5TestDatabase::validatedDsn(getenv('TEST_DB_DSN'));
        $this->pdo = SafePhase5TestDatabase::using($dsn, static fn(string $safe): PDO => new PDO($safe,getenv('TEST_DB_USERNAME') ?: 'root',getenv('TEST_DB_PASSWORD') ?: '',[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,
        ]));
        $this->pdo->exec("SET time_zone = '+00:00'");
        (new Migrator($this->pdo,dirname(__DIR__,2).'/database/migrations'))->run();
        $suffix=bin2hex(random_bytes(8));
        $this->pdo->prepare("INSERT INTO plans (slug,name,features) VALUES (?, 'Editor test', JSON_OBJECT())")->execute(['editor-'.$suffix]);
        $this->plan=(int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO users (name,email,password_hash,plan_id,credits) VALUES ('Editor',?,'test-only',?,10)")->execute(['editor-'.$suffix.'@example.test',$this->plan]);
        $this->owner=(int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO users (name,email,password_hash,plan_id) VALUES ('Foreign',?,'test-only',?)")->execute(['foreign-editor-'.$suffix.'@example.test',$this->plan]);
        $this->foreign=(int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO projects (user_id,ingest_key,name,status,progress) VALUES (?,?,'Editor fixture','completed',100)")->execute([$this->owner,hash('sha256',$suffix)]);
        $this->project=(int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO project_sources (project_id,source_type,storage_disk,object_key,original_name,extension,mime_type,size_bytes,width,height,duration_seconds,video_codec,audio_codec,has_audio,status) VALUES (?,'upload','local','sources/editor-test.mp4','editor.mp4','mp4','video/mp4',2000,1280,720,120,'h264','aac',1,'ready')")->execute([$this->project]);
        $this->pdo->prepare('UPDATE project_sources SET sha256=? WHERE project_id=?')->execute([str_repeat('a',64),$this->project]);
        $this->pdo->prepare("INSERT INTO ai_analyses (project_id,prompt_version,model,status) VALUES (?,'editor-test','test','completed')")->execute([$this->project]);
        $analysis=(int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO clips (project_id,ai_analysis_id,suggestion_index,title,start_time,end_time,duration_seconds,viral_score,hook,reason,category,status,render_start_time,render_end_time,render_revision,output_file,output_size_bytes) VALUES (?,?,0,'Original',10,20,10,90,'Hook','Reason','insight','completed',10,20,1,'processed/original.mp4',4000)")->execute([$this->project,$analysis]);
        $this->clip=(int)$this->pdo->lastInsertId();
        $this->editor=new ClipEditorRepository($this->pdo);
        $this->service=new ClipEditService($this->pdo,$this->editor,new ClipRepository($this->pdo),new ProjectRepository($this->pdo),new ClipRenderProfileRepository($this->pdo),new ReframePlanValidator(),new DatabaseJobDispatcher($this->pdo),180,null,
            new \App\Services\SourceDurationPreflight($this->pdo,new \App\Repositories\ProjectSourceRepository($this->pdo),new \Tests\Support\PreciseMediaProcessor($this->pdo,120000)));
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) return;
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->project)) $this->pdo->prepare('DELETE FROM projects WHERE id=?')->execute([$this->project]);
        if (isset($this->owner,$this->foreign)) $this->pdo->prepare('DELETE FROM users WHERE id IN (?,?)')->execute([$this->owner,$this->foreign]);
        if (isset($this->plan)) $this->pdo->prepare('DELETE FROM plans WHERE id=?')->execute([$this->plan]);
    }

    public function testManualVersionPreservesOriginalAndDuplicateSubmissionQueuesOnce(): void
    {
        $key=hash('sha256','manual-editor-request');
        $args=[$this->clip,$this->owner,$key,'10','12',EditorOptions::fromArray(['style'=>'minimal','title'=>'Novo título']),ReframeSubmission::original(),'manual',"1\n00:00:00,000 --> 00:00:01,000\nOlá"];
        $receipt=$this->service->request(...$args);
        self::assertNotSame($this->clip,$receipt->clipId());
        self::assertTrue($receipt->created());
        $again=$this->service->request(...$args);
        self::assertSame($receipt->clipId(),$again->clipId());
        self::assertFalse($again->created());
        $original=$this->editor->findOwned($this->clip,$this->owner);
        self::assertSame('completed',$original['status']);
        self::assertSame('processed/original.mp4',$this->pdo->query('SELECT output_file FROM clips WHERE id='.$this->clip)->fetchColumn());
        $snapshot=$this->editor->snapshot($receipt->clipId(),1);
        self::assertSame('ready',$snapshot['track_status']);
        self::assertSame('Olá',$snapshot['transcript']->cues()[0]->text());
        self::assertSame('Novo título',$snapshot['options']->title());
        self::assertSame(['generate_subtitles'],$this->pdo->query('SELECT type FROM processing_jobs WHERE project_id='.$this->project)->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(10,(int)$this->pdo->query('SELECT credits FROM users WHERE id='.$this->owner)->fetchColumn());
    }

    public function testAutoCaptionsQueueBeforeRenderAndForeignOwnerCannotReadOrWrite(): void
    {
        self::assertNull($this->editor->findOwned($this->clip,$this->foreign));
        self::assertNull($this->service->request($this->clip,$this->foreign,hash('sha256','foreign'),'10','12',EditorOptions::fromArray([]),ReframeSubmission::original(),'none',''));
        $receipt=$this->service->request($this->clip,$this->owner,hash('sha256','auto'),'10','12',EditorOptions::fromArray(['style'=>'viral']),ReframeSubmission::original(),'auto','');
        self::assertSame('pending',$this->editor->snapshot($receipt->clipId(),1)['track_status']);
        self::assertSame(['generate_subtitles'],$this->pdo->query('SELECT type FROM processing_jobs WHERE project_id='.$this->project)->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testInvalidSrtRollsBackEntireVersionAndQueue(): void
    {
        try {
            $this->service->request($this->clip,$this->owner,hash('sha256','invalid'),'10','12',EditorOptions::fromArray(['style'=>'minimal']),ReframeSubmission::original(),'manual',"1\n00:00:00,000 --> 00:00:03,000\nfora");
            self::fail('Expected invalid transcript.');
        } catch (ClipRenderValidationException $error) {
            self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM clips WHERE project_id='.$this->project)->fetchColumn());
            self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM processing_jobs WHERE project_id='.$this->project)->fetchColumn());
        }
    }

    public function testReopenedVersionPersistsWordAlignmentAndDoesNotRetranscribe(): void
    {
        $original=new \App\Media\Subtitles\Transcript('pt-BR',[new \App\Media\Subtitles\SubtitleCue(0,1000,'Oi mundo',[
            ['start_ms'=>0,'end_ms'=>400,'text'=>'Oi'],['start_ms'=>600,'end_ms'=>1000,'text'=>'mundo'],
        ])],10000);
        $this->pdo->beginTransaction();
        $this->editor->createProfile($this->clip,1,$this->clip,$this->owner,hash('sha256','original-aligned'),EditorOptions::fromArray(['style'=>'karaoke']),'auto',10000);
        $this->editor->saveTranscript($this->clip,1,$original);
        $this->pdo->commit();
        $srt=str_replace('Oi mundo','Olá mundo',\App\Media\Subtitles\SrtCodec::format($original));
        $receipt=$this->service->request($this->clip,$this->owner,hash('sha256','corrected'),'10','20',EditorOptions::fromArray(['style'=>'karaoke']),ReframeSubmission::original(),'manual',$srt);
        $snapshot=(new ClipEditorRepository($this->pdo))->snapshot($receipt->clipId(),1);
        self::assertSame('pt-BR',$snapshot['transcript']->language());
        self::assertSame(['start_ms'=>0,'end_ms'=>400,'text'=>'Olá'],$snapshot['transcript']->cues()[0]->words()[0]);
        self::assertStringContainsString('{\kf40}Olá',\App\Media\Subtitles\AssDocumentBuilder::build($snapshot['transcript'],$snapshot['options'],1080,1920,10000));
        self::assertSame(['generate_subtitles'],$this->pdo->query('SELECT type FROM processing_jobs WHERE project_id='.$this->project)->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame($original->toArray(),$this->editor->snapshot($this->clip,1)['transcript']->toArray());
    }

    public function testRequestedLogoRequiresAnOwnedAdapterBeforePersisting(): void
    {
        foreach ([null,static fn(int $asset,int $user): bool=>false] as $owned) {
            $service=new ClipEditService($this->pdo,$this->editor,new ClipRepository($this->pdo),new ProjectRepository($this->pdo),new ClipRenderProfileRepository($this->pdo),new ReframePlanValidator(),new DatabaseJobDispatcher($this->pdo),180,$owned,
                new \App\Services\SourceDurationPreflight($this->pdo,new \App\Repositories\ProjectSourceRepository($this->pdo),new \Tests\Support\PreciseMediaProcessor($this->pdo,120000)));
            try {
                $service->request($this->clip,$this->owner,hash('sha256','foreign-logo'),'10','12',EditorOptions::fromArray(['logo_asset_id'=>123]),ReframeSubmission::original(),'none','');
                self::fail('An unresolved logo was queued.');
            } catch (ClipRenderValidationException) {
                self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM clips WHERE project_id='.$this->project)->fetchColumn());
            }
        }
    }
}
