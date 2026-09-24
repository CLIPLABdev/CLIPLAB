<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Contracts\TimedTranscriptionProvider;
use App\Controllers\ClipAssetController;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Session;
use App\Media\Editor\EditorOptions;
use App\Media\LocalFfmpegAudioExtractor;
use App\Media\LocalFfmpegClipRenderer;
use App\Media\Reframe\ReframePlanValidator;
use App\Media\Reframe\ReframeSubmission;
use App\Media\Subtitles\Transcript;
use App\Media\Subtitles\TranscriptValidator;
use App\Process\ProcessRunner;
use App\Queue\GenerateSubtitlesHandler;
use App\Queue\LeaseProcessingEffectGuard;
use App\Queue\RenderClipHandler;
use App\Repositories\ClipEditorRepository;
use App\Repositories\ClipRenderProfileRepository;
use App\Repositories\ClipRepository;
use App\Repositories\ClipSubtitleStatusRepository;
use App\Repositories\ProcessingJobRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\RenderArtifactCleanupRepository;
use App\Services\ClipEditService;
use App\Services\DatabaseJobDispatcher;
use App\Services\PrivateFileResponseFactory;
use App\Storage\LocalPrivateStorage;
use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\SafePhase5TestDatabase;

final class ClipEditorRenderedDownloadWorkflowTest extends TestCase
{
    private PDO $pdo;
    private int $planId;
    private int $userId;
    private int $foreignUserId;
    private int $projectId;
    private int $originalClipId;
    private string $root;
    private string $storageRoot;
    private string $renderRoot;
    private string $ffmpeg;
    private string $ffprobe;
    private LocalPrivateStorage $storage;
    private ProcessRunner $runner;

    protected function setUp(): void
    {
        $this->ffmpeg=$this->requiredBinary('TEST_FFMPEG_BIN');
        $this->ffprobe=$this->requiredBinary('TEST_FFPROBE_BIN');
        $this->pdo=SafePhase5TestDatabase::using(
            getenv('TEST_DB_DSN'),
            static fn(string $dsn): PDO => new PDO($dsn,getenv('TEST_DB_USERNAME') ?: 'root',getenv('TEST_DB_PASSWORD') ?: '',[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES=>false,
            ])
        );
        $this->pdo->exec("SET time_zone = '+00:00'");
        (new Migrator($this->pdo,dirname(__DIR__,2).'/database/migrations'))->run();

        $this->root=sys_get_temp_dir().DIRECTORY_SEPARATOR.'cliplab-editor-e2e-'.bin2hex(random_bytes(8));
        $this->storageRoot=$this->root.DIRECTORY_SEPARATOR.'private-storage';
        $this->renderRoot=$this->root.DIRECTORY_SEPARATOR.'render-work';
        self::assertTrue(mkdir($this->storageRoot,0700,true));
        self::assertTrue(mkdir($this->renderRoot,0700,true));
        $this->storage=new LocalPrivateStorage($this->storageRoot,64*1024*1024);
        $this->runner=new ProcessRunner([$this->ffmpeg,$this->ffprobe],$this->renderRoot);
        $this->seedRealSource();
    }

    protected function tearDown(): void
    {
        $_SESSION=[];
        if (isset($this->pdo)) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if (isset($this->projectId)) $this->pdo->prepare('DELETE FROM projects WHERE id=?')->execute([$this->projectId]);
            if (isset($this->userId,$this->foreignUserId)) {
                $this->pdo->prepare('DELETE FROM users WHERE id IN (?,?)')->execute([$this->userId,$this->foreignUserId]);
            }
            if (isset($this->planId)) $this->pdo->prepare('DELETE FROM plans WHERE id=?')->execute([$this->planId]);
        }
        $this->removeDirectory($this->root ?? '');
    }

    public function testEditorAutoSubtitleRealFfmpegRenderAndPrivateDownload(): void
    {
        $clips=new ClipRepository($this->pdo);
        $projects=new ProjectRepository($this->pdo);
        $editor=new ClipEditorRepository($this->pdo);
        $profiles=new ClipRenderProfileRepository($this->pdo);
        $jobs=new ProcessingJobRepository($this->pdo);
        $queue='editor-e2e-'.$this->projectId;
        $dispatcher=new DatabaseJobDispatcher($this->pdo,$queue);
        $service=new ClipEditService(
            $this->pdo,$editor,$clips,$projects,$profiles,new ReframePlanValidator(),$dispatcher,180,null,
            new \App\Services\SourceDurationPreflight($this->pdo,new \App\Repositories\ProjectSourceRepository($this->pdo),
                new \App\Media\LocalFfprobeProcessor($this->storage,$this->runner,$this->ffprobe,15,1048576))
        );

        $receipt=$service->request(
            $this->originalClipId,
            $this->userId,
            hash('sha256','editor-render-download-'.$this->projectId),
            '0',
            '2',
            EditorOptions::fromArray(['style'=>'minimal','title'=>'Fluxo completo']),
            ReframeSubmission::original(),
            'auto',
            ''
        );
        self::assertNotNull($receipt);
        self::assertTrue($receipt->created());
        $subtitleStatus=new ClipSubtitleStatusRepository($this->pdo);
        self::assertSame('queued',$subtitleStatus->forOwnedClip($receipt->clipId(),$this->userId));
        self::assertNull($subtitleStatus->forOwnedClip($receipt->clipId(),$this->foreignUserId));

        $subtitleJob=$jobs->claimNext($queue,'editor-e2e-subtitles',120);
        self::assertNotNull($subtitleJob);
        self::assertSame('generate_subtitles',$subtitleJob->type());
        self::assertSame('processing',$subtitleStatus->forOwnedClip($receipt->clipId(),$this->userId));
        self::assertNull($subtitleStatus->forOwnedClip($receipt->clipId(),$this->foreignUserId));
        $provider=new WorkflowTimedTranscriptionProvider();
        $subtitles=new GenerateSubtitlesHandler(
            $editor,
            $clips,
            $projects,
            new LocalFfmpegAudioExtractor($this->storage,$this->runner,$this->ffmpeg,$this->renderRoot,30,1024*1024),
            $provider,
            new LeaseProcessingEffectGuard($this->pdo),
            $dispatcher
        );

        self::assertSame('completed',$subtitles->handle($subtitleJob)->status());
        self::assertTrue($jobs->complete($subtitleJob));
        self::assertTrue($provider->receivedWave);
        $snapshot=$editor->snapshot($receipt->clipId(),$receipt->revision());
        self::assertSame('ready',$snapshot['track_status']);
        self::assertSame(2000,$snapshot['transcript']->durationMs());
        self::assertNull($subtitleStatus->forOwnedClip($receipt->clipId(),$this->userId));

        $renderJob=$jobs->claimNext($queue,'editor-e2e-render',120);
        self::assertNotNull($renderJob);
        self::assertSame('render_clip',$renderJob->type());
        $renderer=new LocalFfmpegClipRenderer(
            $this->storage,$this->runner,$this->ffmpeg,$this->renderRoot,60,1024*1024,
            64*1024*1024,4*1024*1024,null,null,$this->ffprobe
        );
        $render=new RenderClipHandler(
            $clips,$projects,$renderer,$this->storage,new LeaseProcessingEffectGuard($this->pdo),
            64*1024*1024,4*1024*1024,new RenderArtifactCleanupRepository($this->pdo),$profiles,180,$editor
        );

        self::assertSame('completed',$render->handle($renderJob)->status());
        self::assertTrue($jobs->complete($renderJob));
        $artifact=$clips->artifactForOwnedClip($receipt->clipId(),$this->userId,'video');
        self::assertNotNull($artifact);
        $privatePath=$this->storage->absolutePath($artifact['object_key']);
        self::assertFileExists($privatePath);
        self::assertSame($artifact['size_bytes'],filesize($privatePath));

        $probe=$this->runner->run([
            $this->ffprobe,'-v','error','-show_entries','format=format_name,duration','-of','json',$privatePath,
        ],15,1024*1024);
        self::assertSame(0,$probe->exitCode);
        $metadata=json_decode($probe->stdout,true,64,JSON_THROW_ON_ERROR);
        self::assertStringContainsString('mp4',(string)($metadata['format']['format_name'] ?? ''));
        self::assertEqualsWithDelta(2.0,(float)($metadata['format']['duration'] ?? 0),0.35);

        Session::put('user_id',$this->userId);
        $response=(new ClipAssetController($clips,$this->storage,new PrivateFileResponseFactory()))
            ->download(Request::fake('GET','/clips/'.$receipt->clipId().'/download'),['id'=>(string)$receipt->clipId()]);
        self::assertSame(200,$response->status());
        self::assertSame('video/mp4',$response->header('Content-Type'));
        self::assertSame('private, no-store',$response->header('Cache-Control'));
        self::assertSame('attachment; filename="clip-'.$receipt->clipId().'.mp4"',$response->header('Content-Disposition'));
        ob_start();
        $response->send();
        $downloaded=(string)ob_get_clean();
        self::assertSame($artifact['size_bytes'],strlen($downloaded));
        self::assertSame(hash_file('sha256',$privatePath),hash('sha256',$downloaded));

        $original=$this->pdo->prepare('SELECT status,output_file FROM clips WHERE id=?');
        $original->execute([$this->originalClipId]);
        self::assertSame(['status'=>'suggested','output_file'=>null],$original->fetch(PDO::FETCH_ASSOC));
    }

    private function seedRealSource(): void
    {
        $suffix=bin2hex(random_bytes(8));
        $this->pdo->prepare("INSERT INTO plans (slug,name,features,is_active) VALUES (?,'Editor E2E',JSON_OBJECT(),1)")
            ->execute(['editor-e2e-'.$suffix]);
        $this->planId=(int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO users (name,email,password_hash,plan_id,credits,status) VALUES ('Editor E2E',?,'test-only',?,10,'active')")
            ->execute(['editor-e2e-'.$suffix.'@example.test',$this->planId]);
        $this->userId=(int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO users (name,email,password_hash,plan_id,credits,status) VALUES ('Foreign E2E',?,'test-only',?,10,'active')")
            ->execute(['foreign-editor-e2e-'.$suffix.'@example.test',$this->planId]);
        $this->foreignUserId=(int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO projects (user_id,ingest_key,name,status,progress) VALUES (?,?,'Editor E2E','suggestions_ready',92)")
            ->execute([$this->userId,hash('sha256','editor-e2e-project-'.$suffix)]);
        $this->projectId=(int)$this->pdo->lastInsertId();

        $sourceKey='sources/'.$this->projectId.'/source.mp4';
        $sourcePath=$this->storage->absolutePath($sourceKey);
        self::assertTrue(mkdir(dirname($sourcePath),0700,true));
        $generated=$this->runner->run([
            $this->ffmpeg,'-y','-nostdin','-hide_banner','-loglevel','error',
            '-f','lavfi','-i','testsrc=size=640x360:rate=30:duration=4',
            '-f','lavfi','-i','sine=frequency=880:sample_rate=48000:duration=4',
            '-map','0:v:0','-map','1:a:0','-t','4.000','-c:v','libx264','-preset','ultrafast',
            '-crf','23','-pix_fmt','yuv420p','-c:a','aac','-b:a','128k','-movflags','+faststart',$sourcePath,
        ],30,1024*1024);
        self::assertSame(0,$generated->exitCode,'Synthetic FFmpeg source generation failed.');
        $sourceSize=filesize($sourcePath);
        self::assertIsInt($sourceSize);

        $this->pdo->prepare("INSERT INTO project_sources (project_id,source_type,storage_disk,object_key,original_name,extension,mime_type,size_bytes,width,height,duration_seconds,video_codec,audio_codec,has_audio,status) VALUES (?,'upload','local',?,'source.mp4','mp4','video/mp4',?,640,360,4,'h264','aac',1,'ready')")
            ->execute([$this->projectId,$sourceKey,$sourceSize]);
        $this->pdo->prepare('UPDATE project_sources SET sha256=? WHERE project_id=?')->execute([hash_file('sha256',$sourcePath),$this->projectId]);
        $this->pdo->prepare("INSERT INTO ai_analyses (project_id,prompt_version,model,status) VALUES (?,'editor-e2e','test','completed')")
            ->execute([$this->projectId]);
        $analysisId=(int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO clips (project_id,ai_analysis_id,suggestion_index,title,start_time,end_time,duration_seconds,viral_score,hook,reason,category,status) VALUES (?,?,0,'Original',0,4,4,90,'Hook','Reason','insight','suggested')")
            ->execute([$this->projectId,$analysisId]);
        $this->originalClipId=(int)$this->pdo->lastInsertId();
    }

    private function requiredBinary(string $variable): string
    {
        $configured=getenv($variable);
        if (!is_string($configured) || trim($configured)==='') self::fail($variable.' is required.');
        $resolved=realpath($configured);
        if ($resolved===false || !is_file($resolved) || !is_executable($resolved)) self::fail($variable.' must be executable.');
        return $resolved;
    }

    private function removeDirectory(string $directory): void
    {
        if ($directory==='' || !is_dir($directory)) return;
        $iterator=new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory,RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($directory);
    }
}

final class WorkflowTimedTranscriptionProvider implements TimedTranscriptionProvider
{
    public bool $receivedWave=false;

    public function transcribe(string $wavBytes,int $durationMs): Transcript
    {
        $this->receivedWave=str_starts_with($wavBytes,'RIFF') && substr($wavBytes,8,4)==='WAVE';
        return TranscriptValidator::fromArray([
            'language'=>'pt-BR',
            'cues'=>[['start_ms'=>0,'end_ms'=>$durationMs-100,'text'=>'Legenda integrada']],
        ],$durationMs);
    }
}
