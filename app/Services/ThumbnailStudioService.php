<?php
declare(strict_types=1);
namespace App\Services;
use App\Repositories\ThumbnailRepository;
use App\Contracts\JobDispatcher;
use App\Media\Thumbnails\ThumbnailOptions;
use PDO;
final class ThumbnailStudioService
{
 private $logoResolver;
 public function __construct(private PDO $pdo,private ThumbnailRepository $repository,private JobDispatcher $jobs,?callable $logoResolver=null) { $this->logoResolver=$logoResolver; }
 public function generate(int $clipId,int $userId,string $key): ?int { return $this->request($clipId,$userId,$key,null); }
 public function save(int $clipId,int $userId,string $key,mixed $offset,?int $base,array $options): ?int { return $this->request($clipId,$userId,$key,[$offset,$base,ThumbnailOptions::fromArray($options)->toArray()]); }
 private function request(int $clipId,int $userId,string $key,?array $design): ?int {
  if(preg_match('/^[a-f0-9]{64}$/D',$key)!==1)throw new \InvalidArgumentException('Atualize o formulário.');
  $this->pdo->beginTransaction();try { $c=$this->repository->sourceOwned($clipId,$userId,true);if(!$c){$this->pdo->rollBack();return null;}
   if($design!==null&&$design[1]!==null){$candidate=$this->repository->findOwned($design[1],$userId);if(!$candidate||(int)$candidate['clip_id']!==$clipId||$candidate['status']!=='ready')throw new \InvalidArgumentException('Candidato indisponível.');$design[0]=(float)$candidate['offset_seconds'];}
   $kind=$design===null?'set':'design';$existing=$this->repository->requestOwned($key,$userId,$kind);
   if($existing){if((int)$existing['clip_id']!==$clipId||(int)$existing['render_revision']!==(int)$c['render_revision'])throw new \InvalidArgumentException('Pedido já usado em outra versão.');if($design!==null&&((float)$existing['offset_seconds']!==(float)$design[0]||(int)($existing['base_thumbnail_id']??0)!==(int)($design[1]??0)||json_decode($existing['options_json'],true)!==$design[2]))throw new \InvalidArgumentException('Pedido já usado com outros controles.');$this->pdo->commit();return (int)$existing['id']; }
   if($design===null) { $old=$this->repository->setOwned($clipId,$userId);$id=$old?(int)$old['id']:$this->repository->createSet($c,$key); } else { [$offset,$base,$options]=$design;$offset=ThumbnailOptions::offset($offset,(float)$c['render_end_time']-(float)$c['render_start_time']);if($base!==null){$b=$this->repository->findOwned($base,$userId);if(!$b||(int)$b['clip_id']!==$clipId||$b['status']!=='ready')throw new \InvalidArgumentException('Candidato indisponível.');}if($options['logo_asset_id']>0&&($this->logoResolver===null||!($this->logoResolver)($options['logo_asset_id'],(int)$c['project_id'])))throw new \InvalidArgumentException('Logo indisponível.');if($this->repository->designCount($clipId,$userId)>=50)throw new \InvalidArgumentException('Limite de 50 variantes por clipe.');$id=$this->repository->createDesign($c,$key,$offset,$base,$options); }
   $type=$kind==='set'?'generate_thumbnail_candidates':'render_thumbnail_design';$this->jobs->dispatch($type,(int)$c['project_id'],['thumbnail_request_id'=>$id],$type.':'.$id);$this->pdo->commit();return $id;
  }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
 }
}
