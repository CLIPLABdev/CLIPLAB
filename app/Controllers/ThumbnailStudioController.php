<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Request,Response,Session,View};
use App\Repositories\ThumbnailRepository;
use App\Services\{ThumbnailStudioService,PrivateFileResponseFactory};
use App\Contracts\PrivateStorage;
use App\Media\Thumbnails\ThumbnailOptions;
final class ThumbnailStudioController
{
 private $limit;private $profile;private $logos;
 public function __construct(private View $view,private ThumbnailRepository $repository,private ThumbnailStudioService $service,private PrivateStorage $storage,?callable $limit=null,?callable $profile=null,?callable $logos=null){$this->limit=$limit??static fn(int $id):bool=>false;$this->profile=$profile;$this->logos=$logos;}
 public function show(Request $r,array $p):Response {return $this->page($p);}
 public function generate(Request $r,array $p):Response{return $this->submit($r,$p,false);}
 public function save(Request $r,array $p):Response{return $this->submit($r,$p,true);}
 public function status(Request $r,array $p):Response { $u=(int)Session::get('user_id',0);if(!$u)return $this->private(Response::redirect('/login'));$id=$this->id($p);if(!$this->repository->sourceOwned($id,$u))return $this->missing();$set=$this->repository->setOwned($id,$u);$items=array_map(static fn(array $a):array=>array_intersect_key($a,array_flip(['id','kind','status','error_code','offset_seconds'])), $this->repository->forClip($id,$u));return $this->private(Response::json(['set'=>$set?array_intersect_key($set,array_flip(['id','status','error_code','candidate_count'])):null,'thumbnails'=>$items])); }
 public function image(Request $r,array $p):Response {return $this->artifact($p,false);}
 public function download(Request $r,array $p):Response {return $this->artifact($p,true);}
 private function artifact(array $p,bool $download):Response { $u=(int)Session::get('user_id',0);if(!$u)return $this->private(Response::redirect('/login'));$id=$this->id($p);$a=$this->repository->findOwned($id,$u);if(!$a||$a['status']!=='ready'||!is_string($a['object_key'])||$a['mime_type']!=='image/jpeg'||(int)$a['size_bytes']<1)return $this->missing();try{$response=(new PrivateFileResponseFactory())->thumbnail($this->storage->absolutePath($a['object_key']),(int)$a['size_bytes']);return $response->withHeader('Content-Disposition',($download?'attachment':'inline').'; filename="capa-'.$id.'.jpg"');}catch(\Throwable){return $this->missing();} }
 private function submit(Request $r,array $p,bool $design):Response { $u=(int)Session::get('user_id',0);if(!$u)return $this->private(Response::redirect('/login'));$id=$this->id($p);if(!$this->repository->sourceOwned($id,$u))return $this->missing();$old=[];
  try {foreach(['path','source_path','output_path','object_key','ffmpeg_args','filtergraph','source_object_key','fontfile'] as $field)if($r->hasInput($field))throw new \InvalidArgumentException('Envie somente os controles exibidos.');$key=$this->field($r,'request_key',64);$options=[];foreach(ThumbnailOptions::fromArray([])->toArray() as $k=>$default)$options[$k]=$this->field($r,$k,$k==='title'?480:100,(string)$default);$old=$options+['offset_seconds'=>$this->field($r,'offset_seconds',15,'1'),'base_thumbnail_id'=>$this->field($r,'base_thumbnail_id',18,'0')];if(!($this->limit)($u))return $this->page($p,$old,'Limite de 20 pedidos por hora atingido. Aguarde para tentar novamente.',429);$base=$this->number($old['base_thumbnail_id']);$result=$design?$this->service->save($id,$u,$key,$old['offset_seconds'],$base?:null,$options):$this->service->generate($id,$u,$key);if(!$result)return $this->missing();Session::flash('thumbnail_feedback','Pedido registrado. O resultado real aparecerá após o processamento.');return $this->private(Response::redirect('/clips/'.$id.'/capas'));
  }catch(\InvalidArgumentException $e){return $this->page($p,$old,$e->getMessage(),422);} }
 private function page(array $p,array $old=[],?string $error=null,int $status=200):Response { $u=(int)Session::get('user_id',0);if(!$u)return $this->private(Response::redirect('/login'));$clip=$this->repository->sourceOwned($this->id($p),$u);if(!$clip)return $this->missing();$user=$this->profile!==null?($this->profile)($u):[];$user=array_replace(['name'=>'Conta','email'=>'','credits'=>0,'plan_name'=>'Plano','monthly_minutes'=>0],$user??[]);$html=$this->view->render('clips.thumbnail-studio',['title'=>'Estúdio de capas','user'=>$user,'clip'=>$clip,'set'=>$this->repository->setOwned((int)$clip['id'],$u),'thumbnails'=>$this->repository->forClip((int)$clip['id'],$u),'values'=>array_replace(ThumbnailOptions::fromArray([])->toArray(),['offset_seconds'=>'1','base_thumbnail_id'=>'0'],$old),'requestKey'=>bin2hex(random_bytes(32)),'error'=>$error,'feedback'=>Session::pull('thumbnail_feedback'),'logos'=>$this->logos!==null?($this->logos)($u):[]]);return $this->private(Response::html($html->body(),$status)); }
 private function field(Request $r,string $key,int $max,string $default=''):string {$v=$r->input($key,$default);if(!is_string($v)||strlen($v)>$max||!mb_check_encoding($v,'UTF-8'))throw new \InvalidArgumentException('Campo inválido.');return $v;}
 private function number(string $n):int {if(preg_match('/^(0|[1-9][0-9]{0,17})$/D',$n)!==1)throw new \InvalidArgumentException('Identificador inválido.');return (int)$n;}
 private function id(array $p):int {$s=$p['id']??'';return is_string($s)&&preg_match('/^[1-9][0-9]{0,17}$/D',$s)?(int)$s:0;}
 private function private(Response $r):Response{return $r->withHeader('Cache-Control','private, no-store');}
 private function missing():Response{return $this->private(Response::text('Não encontrado.',404));}
}
