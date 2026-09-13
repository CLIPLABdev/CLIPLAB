<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Repositories\ThumbnailRepository;
use App\Repositories\PublicationPreparationRepository;
use App\Services\ThumbnailStudioService;
use App\Services\PublicationPreparationService;
use App\Contracts\JobDispatcher;
use PHPUnit\Framework\TestCase;
use Tests\Support\ThumbnailTestDatabase;
final class ThumbnailPublicationTest extends TestCase
{
 private $pdo; private ThumbnailRepository $thumbs; private ThumbnailStudioService $studio; private PublicationPreparationService $pub;
 protected function setUp(): void { $this->pdo=ThumbnailTestDatabase::create(); $this->thumbs=new ThumbnailRepository($this->pdo); $dispatcher=new class implements JobDispatcher { public function dispatch(string $type,int $projectId,array $payload,string $idempotencyKey): int {return 1;} }; $this->studio=new ThumbnailStudioService($this->pdo,$this->thumbs,$dispatcher); $this->pub=new PublicationPreparationService($this->pdo,new PublicationPreparationRepository($this->pdo),$this->thumbs); }
 public function test_foreign_requests_write_nothing(): void { self::assertNull($this->studio->generate(1,8,str_repeat('a',64))); self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM clip_thumbnail_sets')->fetchColumn()); }
 public function test_repeated_generation_is_idempotent(): void { $a=$this->studio->generate(1,7,str_repeat('a',64)); $b=$this->studio->generate(1,7,str_repeat('a',64)); self::assertSame($a,$b); self::assertSame(1,(int)$this->pdo->query('SELECT COUNT(*) FROM clip_thumbnail_sets')->fetchColumn()); }
 public function test_foreign_candidate_cannot_be_used_and_paths_are_rejected(): void { $this->pdo->exec("INSERT INTO clip_thumbnails(clip_id,user_id,render_revision,kind,status,offset_seconds,options_json) VALUES(2,8,1,'candidate','ready',2,'{}')"); self::assertNull($this->thumbs->findOwned(1,7)); $this->expectException(\InvalidArgumentException::class); $this->studio->save(1,7,str_repeat('c',64),'1',1,[]); }
 public function test_metadata_persists_and_conflicting_version_does_not_add_event(): void { $id=$this->pub->save(1,7,0,0,['platform'=>'youtube','title'=>'Olá'],null); self::assertSame('Olá',$this->pub->findOwned($id,7)['metadata']['title']); $this->pub->transition($id,7,1,'ready'); try { $this->pub->transition($id,7,1,'marked_published'); self::fail('Stale version accepted'); } catch(\App\Media\Thumbnails\PublicationVersionConflict $e) {} self::assertCount(2,$this->pub->history($id,7)); self::assertSame('ready',$this->pub->findOwned($id,7)['status']); }
 public function test_foreign_thumbnail_is_never_bound(): void { $this->pdo->exec("INSERT INTO clip_thumbnails(clip_id,user_id,render_revision,kind,status,offset_seconds,options_json) VALUES(2,8,1,'design','ready',2,'{}')"); $this->expectException(\InvalidArgumentException::class); $this->pub->save(1,7,0,0,['platform'=>'youtube'],1); }
 public function test_nojs_candidate_selection_uses_its_actual_frame(): void { $this->pdo->exec("INSERT INTO clip_thumbnails(clip_id,user_id,render_revision,kind,status,offset_seconds,options_json,object_key) VALUES(1,7,1,'candidate','ready',3.25,'{}','thumbnails/candidate.jpg')"); $id=$this->studio->save(1,7,str_repeat('d',64),'1',1,[]);self::assertSame(3.25,(float)$this->thumbs->findOwned($id,7)['offset_seconds']);self::assertSame($id,$this->studio->save(1,7,str_repeat('d',64),'1',1,[])); }
 public function test_archived_and_stale_source_block_package(): void { $id=$this->pub->save(1,7,0,0,['platform'=>'youtube'],null); $this->pub->transition($id,7,1,'ready'); $this->pdo->exec("UPDATE projects SET status='archived' WHERE id=1"); self::assertNull($this->pub->package($id,7,'json')); }
 public function test_package_contains_private_links_and_manual_history(): void { $id=$this->pub->save(1,7,0,0,['platform'=>'instagram','caption'=>'Oi'],null); $this->pub->transition($id,7,1,'ready'); $this->pub->transition($id,7,2,'marked_published'); $p=json_decode($this->pub->package($id,7,'json'),true); self::assertSame('/clips/1/download',$p['video_download']); self::assertSame('manual',$p['publication_confirmation']); self::assertArrayNotHasKey('object_key',$p); }
}
