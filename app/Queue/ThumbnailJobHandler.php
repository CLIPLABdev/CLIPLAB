<?php
declare(strict_types=1);
namespace App\Queue;
use App\Repositories\ThumbnailRepository;
use App\Media\Thumbnails\LocalThumbnailGenerator;
use App\Media\Thumbnails\ThumbnailOptions;
use App\Contracts\PrivateStorage;
use App\Contracts\RenderArtifactCleanupStore;
abstract class ThumbnailJobHandler implements JobHandler
{
 private $publishQuotaGuard;
 public function __construct(private ThumbnailRepository $repository,private LocalThumbnailGenerator $generator,private PrivateStorage $storage,private ProcessingEffectGuard $effects,private RenderArtifactCleanupStore $cleanups,?callable $publishQuotaGuard=null) {$this->publishQuotaGuard=$publishQuotaGuard;}
 abstract protected function kind(): string;
 public function handle(ClaimedJob $job): JobOutcome {
  $kind=$this->kind();$expected=$kind==='set'?'generate_thumbnail_candidates':'render_thumbnail_design';$payload=$job->payload();if($job->type()!==$expected||array_keys($payload)!==['thumbnail_request_id']||!is_int($payload['thumbnail_request_id'])||$payload['thumbnail_request_id']<1)return JobOutcome::failed('thumbnail_request_invalid',ProcessingErrorCatalog::requireMessage('thumbnail_request_invalid'));$id=$payload['thumbnail_request_id'];$files=[];$keys=[];$committed=false;
  try { foreach($this->cleanups->pending() as $key){try{$this->storage->delete($key);$this->cleanups->forget($key);}catch(\Throwable){}}
   $context=$this->repository->jobContext($id,$kind,$job->projectId());if(!$context)return JobOutcome::completed();if(!$this->effects->apply($job,static function():void{}))return JobOutcome::deferred(15);
   $files=$kind==='set'?$this->generator->generate($context['clip']):[$this->generator->design($context['clip'],(float)$context['row']['offset_seconds'],ThumbnailOptions::fromArray(json_decode($context['row']['options_json'],true,512,JSON_THROW_ON_ERROR)))];
   foreach($files as $f)$keys[]='thumbnails/'.$job->projectId().'/'.$context['row']['clip_id'].'-'.bin2hex(random_bytes(16)).'.jpg';if(!$this->cleanups->reserve($job,$keys))return JobOutcome::deferred(15);
   $stored=[];foreach($files as $i=>$f){$stream=fopen($f['path'],'rb');if(!$stream)throw new \RuntimeException('Thumbnail unavailable.');try{$object=$this->storage->putStream($stream,$keys[$i],2097152);}finally{fclose($stream);}$stored[]=['key'=>$object->objectKey(),'size'=>$object->sizeBytes(),'offset'=>$f['offset']];}
   $committed=$this->effects->apply($job,function()use($id,$kind,$stored,$job,$keys,$context):void{if($this->publishQuotaGuard!==null)($this->publishQuotaGuard)((int)$context['row']['user_id'],array_sum(array_column($stored,'size')));$this->repository->complete($id,$kind,$stored,$job->projectId());$this->cleanups->release($job,$keys);});return $committed?JobOutcome::completed():JobOutcome::deferred(15);
  }catch(\Throwable){try{$valid=$this->effects->apply($job,function()use($id,$kind):void{$this->repository->markFailed($id,$kind);});}catch(\Throwable){$valid=false;}return $valid?JobOutcome::failed('thumbnail_failed','Não foi possível gerar a capa. Revise o vídeo e tente uma nova variante.'):JobOutcome::deferred(15);
  }finally{foreach($files as $f)if(is_file($f['path']))@unlink($f['path']);if(!$committed&&$keys){try{$this->cleanups->markForCleanup($job,$keys);}catch(\Throwable){}foreach($keys as $key){try{$this->storage->delete($key);$this->cleanups->forget($key);}catch(\Throwable){}}}}
 }
}
