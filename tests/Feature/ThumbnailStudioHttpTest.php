<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Controllers\ThumbnailStudioController;
use App\Controllers\PublicationPreparationController;
use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Repositories\ThumbnailRepository;
use App\Repositories\PublicationPreparationRepository;
use App\Services\ThumbnailStudioService;
use App\Services\PublicationPreparationService;
use App\Contracts\JobDispatcher;
use App\Storage\LocalPrivateStorage;
use PHPUnit\Framework\TestCase;
use Tests\Support\ThumbnailTestDatabase;
final class ThumbnailStudioHttpTest extends TestCase
{
 private $pdo;private $studio;private $pub;
 protected function setUp():void { $this->pdo=ThumbnailTestDatabase::create();$t=new ThumbnailRepository($this->pdo);$jobs=new class implements JobDispatcher {public function dispatch(string $type,int $projectId,array $payload,string $idempotencyKey):int{return 1;}};$this->studio=new ThumbnailStudioController(new View(),$t,new ThumbnailStudioService($this->pdo,$t,$jobs),new LocalPrivateStorage(sys_get_temp_dir(),1000000),static fn(int $id):bool=>true);$this->pub=new PublicationPreparationController(new View(),$t,new PublicationPreparationService($this->pdo,new PublicationPreparationRepository($this->pdo),$t));Session::put('user_id',7); }
 public function test_authentication_and_foreign_reads_fail_private():void {Session::forget('user_id');$r=$this->studio->show(Request::fake('GET','/clips/1/capas'),['id'=>'1']);self::assertSame('/login',$r->header('Location'));Session::put('user_id',8);$r=$this->studio->show(Request::fake('GET','/clips/1/capas'),['id'=>'1']);self::assertSame(404,$r->status());self::assertSame('private, no-store',$r->header('Cache-Control'));}
 public function test_no_javascript_forms_save_and_poll_without_object_keys():void { $r=$this->studio->show(Request::fake('GET','/clips/1/capas'),['id'=>'1']);self::assertSame(200,$r->status());self::assertStringContainsString('method="post"',$r->body());$r=$this->studio->generate(Request::fake('POST','/clips/1/capas/gerar',['request_key'=>str_repeat('a',64)]),['id'=>'1']);self::assertSame(302,$r->status());$status=$this->studio->status(Request::fake('GET','/clips/1/capas/status'),['id'=>'1']);self::assertStringContainsString('pending',$status->body());self::assertStringNotContainsString('object_key',$status->body());self::assertStringNotContainsString('sources/test.mp4',$status->body()); }
 public function test_no_javascript_publication_and_bad_status():void {$r=$this->pub->store(Request::fake('POST','/clips/1/publicacao',['platform'=>'youtube','title'=>'Meu título','version'=>'0','publication_id'=>'0','thumbnail_id'=>'0']),['id'=>'1']);self::assertSame(302,$r->status());$r=$this->pub->show(Request::fake('GET','/clips/1/publicacao'),['id'=>'1']);self::assertStringContainsString('Meu título',$r->body());$r=$this->pub->status(Request::fake('POST','/publicacoes/1/status',['version'=>'1','status'=>'posted_on_youtube']),['id'=>'1']);self::assertSame(422,$r->status());}
 public function test_arbitrary_ffmpeg_input_is_rejected_without_a_job():void {$r=$this->studio->save(Request::fake('POST','/clips/1/capas',['request_key'=>str_repeat('b',64),'offset_seconds'=>'1','ffmpeg_args'=>'-i file:/secret']),['id'=>'1']);self::assertSame(422,$r->status());self::assertSame(0,(int)$this->pdo->query('SELECT COUNT(*) FROM clip_thumbnails')->fetchColumn());}
}
