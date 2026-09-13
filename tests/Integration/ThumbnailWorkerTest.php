<?php
declare(strict_types=1);
namespace Tests\Integration;
use PHPUnit\Framework\TestCase;
use App\Core\Migrator;
use App\Queue\ClaimedJob;
use App\Queue\GenerateThumbnailCandidatesHandler;
use App\Queue\RenderThumbnailDesignHandler;
use App\Queue\LeaseProcessingEffectGuard;
use App\Repositories\ThumbnailRepository;
use App\Repositories\ThumbnailArtifactCleanupRepository;
use App\Services\ThumbnailStudioService;
use App\Services\DatabaseJobDispatcher;
use App\Storage\LocalPrivateStorage;
use App\Process\ProcessRunner;
use App\Media\Thumbnails\LocalThumbnailGenerator;
use Tests\Support\SafePhase5TestDatabase;
use PDO;
final class ThumbnailWorkerTest extends TestCase
{
 public function test_real_lease_idempotency_and_expired_lease_have_no_duplicate_artifacts(): void {
  self::assertTrue(class_exists(GenerateThumbnailCandidatesHandler::class),'Thumbnail worker is implemented');
  $bin=getenv('TEST_FFMPEG_BINARY');$dsn=getenv('TEST_DB_DSN');if(!$bin||!$dsn)$this->markTestSkipped('Requires isolated MySQL and local FFmpeg.');$p=new PDO(SafePhase5TestDatabase::validatedDsn($dsn),getenv('TEST_DB_USERNAME')?:'root',getenv('TEST_DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);(new Migrator($p,dirname(__DIR__,2).'/database/migrations'))->run();
  $suffix=bin2hex(random_bytes(8));$tmp=sys_get_temp_dir().'/thumb-worker-'.$suffix;mkdir($tmp,0700);$storage=new LocalPrivateStorage($tmp,20000000);$runner=new ProcessRunner([$bin],$tmp);$p->prepare("INSERT INTO plans(slug,name,features) VALUES(?,?,'{}')")->execute(['thumb-'.$suffix,'Thumb '.$suffix]);$plan=(int)$p->lastInsertId();$p->prepare('INSERT INTO users(name,email,password_hash,plan_id) VALUES(?,?,?,?)')->execute(['Thumb','thumb-'.$suffix.'@example.test','fixture',$plan]);$user=(int)$p->lastInsertId();$p->prepare("INSERT INTO projects(user_id,name,status) VALUES(?,'Thumbnail fixture','completed')")->execute([$user]);$project=(int)$p->lastInsertId();
  try { $r=$runner->run([$bin,'-v','error','-f','lavfi','-i','testsrc2=size=1280x720:rate=10','-t','4','-c:v','libx264',$tmp.'/source.mp4'],30,100000);self::assertSame(0,$r->exitCode);
   $p->prepare("INSERT INTO project_sources(project_id,source_type,storage_disk,object_key,extension,mime_type,size_bytes,duration_seconds,width,height,status) VALUES(?,'upload','local','source.mp4','mp4','video/mp4',1000,4,1280,720,'ready')")->execute([$project]);$p->prepare("INSERT INTO ai_analyses(project_id,prompt_version,model,status,validated_response_json) VALUES(?,'fixture','fixture','completed','{}')")->execute([$project]);$analysis=(int)$p->lastInsertId();$p->prepare("INSERT INTO clips(project_id,ai_analysis_id,suggestion_index,title,start_time,end_time,duration_seconds,viral_score,hook,reason,category,status,render_revision,render_start_time,render_end_time,output_file,output_size_bytes) VALUES(?,?,0,'Fixture',0,4,4,80,'x','x','insight','completed',1,0,4,'source.mp4',1000)")->execute([$project,$analysis]);$clip=(int)$p->lastInsertId();
   $repo=new ThumbnailRepository($p);$service=new ThumbnailStudioService($p,$repo,new DatabaseJobDispatcher($p));$set=$service->generate($clip,$user,str_repeat('a',64));$jobId=(int)$p->query('SELECT MAX(id) FROM processing_jobs WHERE project_id='.$project)->fetchColumn();$token='test-lease-'.$suffix;$p->prepare("UPDATE processing_jobs SET status='running',worker_id='thumbnail-test',lease_token_hash=?,leased_until=UTC_TIMESTAMP()+INTERVAL 10 MINUTE,attempts=1 WHERE id=?")->execute([hash('sha256',$token),$jobId]);$job=new ClaimedJob($jobId,'media','generate_thumbnail_candidates',$project,['thumbnail_request_id'=>$set],'thumbnail-test',$token,1,3);$cleanup=new ThumbnailArtifactCleanupRepository($p);$handler=new GenerateThumbnailCandidatesHandler($repo,new LocalThumbnailGenerator($storage,$runner,$bin,$tmp),$storage,new LeaseProcessingEffectGuard($p),$cleanup);
   self::assertSame('completed',$handler->handle($job)->status());self::assertCount(5,$repo->forClip($clip,$user));self::assertSame('completed',$handler->handle($job)->status());self::assertCount(5,$repo->forClip($clip,$user));self::assertSame(0,(int)$p->query("SELECT COUNT(*) FROM render_artifact_cleanups WHERE object_key LIKE 'thumbnails/$project/%'")->fetchColumn());
   $design=$service->save($clip,$user,str_repeat('b',64),'1',null,['title'=>'Teste']);$otherJob=(int)$p->query('SELECT MAX(id) FROM processing_jobs WHERE project_id='.$project)->fetchColumn();$p->prepare("UPDATE processing_jobs SET status='running',worker_id='thumbnail-test',lease_token_hash=?,leased_until=UTC_TIMESTAMP()-INTERVAL 1 SECOND,attempts=1 WHERE id=?")->execute([hash('sha256',$token),$otherJob]);$expired=new ClaimedJob($otherJob,'media','render_thumbnail_design',$project,['thumbnail_request_id'=>$design],'thumbnail-test',$token,1,3);$designer=new RenderThumbnailDesignHandler($repo,new LocalThumbnailGenerator($storage,$runner,$bin,$tmp),$storage,new LeaseProcessingEffectGuard($p),$cleanup);self::assertSame('deferred',$designer->handle($expired)->status());self::assertNull($repo->findOwned($design,$user)['object_key']);self::assertSame(5,count(glob($tmp.'/thumbnails/'.$project.'/*.jpg')));
   $p->prepare('UPDATE processing_jobs SET leased_until=UTC_TIMESTAMP()+INTERVAL 10 MINUTE WHERE id=?')->execute([$otherJob]);
   $expireOnWrite=new class($storage,$p,$otherJob) implements \App\Contracts\PrivateStorage {
    public function __construct(private $storage,private PDO $p,private int $jobId){}
    public function putUploaded(string $temporaryPath,string $objectKey):\App\Media\StoredObject{return $this->storage->putUploaded($temporaryPath,$objectKey);}
    public function putStream(mixed $stream,string $objectKey,int $maxBytes):\App\Media\StoredObject{$r=$this->storage->putStream($stream,$objectKey,$maxBytes);$this->p->prepare('UPDATE processing_jobs SET leased_until=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id=?')->execute([$this->jobId]);return $r;}
    public function absolutePath(string $objectKey):string{return $this->storage->absolutePath($objectKey);}
    public function delete(string $objectKey):void{$this->storage->delete($objectKey);}
   };
   $lost=new RenderThumbnailDesignHandler($repo,new LocalThumbnailGenerator($storage,$runner,$bin,$tmp),$expireOnWrite,new LeaseProcessingEffectGuard($p),$cleanup);
   self::assertSame('deferred',$lost->handle($expired)->status());self::assertNull($repo->findOwned($design,$user)['object_key']);self::assertSame(5,count(glob($tmp.'/thumbnails/'.$project.'/*.jpg')));self::assertSame([],glob($tmp.'/thumbnail-*.jpg'));self::assertSame(0,(int)$p->query("SELECT COUNT(*) FROM render_artifact_cleanups WHERE object_key LIKE 'thumbnails/$project/%'")->fetchColumn());
   $p->prepare('UPDATE processing_jobs SET leased_until=UTC_TIMESTAMP()+INTERVAL 10 MINUTE WHERE id=?')->execute([$otherJob]);
   $quotaDenied=new RenderThumbnailDesignHandler($repo,new LocalThumbnailGenerator($storage,$runner,$bin,$tmp),$storage,new LeaseProcessingEffectGuard($p),$cleanup,static function(int $owner,int $bytes)use($user):void{self::assertSame($user,$owner);self::assertGreaterThan(1000,$bytes);throw new \RuntimeException('Test storage quota refusal');});
   self::assertSame('failed',$quotaDenied->handle($expired)->status());self::assertSame('failed',$repo->findOwned($design,$user)['status']);self::assertNull($repo->findOwned($design,$user)['object_key']);self::assertSame(5,count(glob($tmp.'/thumbnails/'.$project.'/*.jpg')));self::assertSame(0,(int)$p->query("SELECT COUNT(*) FROM render_artifact_cleanups WHERE object_key LIKE 'thumbnails/$project/%'")->fetchColumn());
  }finally { $p->prepare('DELETE FROM clip_thumbnails WHERE user_id=?')->execute([$user]);$p->prepare('DELETE FROM clip_thumbnail_sets WHERE user_id=?')->execute([$user]);$p->prepare('DELETE FROM projects WHERE id=?')->execute([$project]);$p->prepare('DELETE FROM users WHERE id=?')->execute([$user]);$p->prepare('DELETE FROM plans WHERE id=?')->execute([$plan]);$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tmp,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($tmp); }
 }
}
