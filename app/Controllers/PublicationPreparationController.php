<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Request,Response,Session,View};
use App\Repositories\ThumbnailRepository;
use App\Services\PublicationPreparationService;
use App\Media\Thumbnails\PublicationVersionConflict;
final class PublicationPreparationController
{
 private $profile;
 public function __construct(private View $view,private ThumbnailRepository $thumbnails,private PublicationPreparationService $service,?callable $profile=null){$this->profile=$profile;}
 public function show(Request $r,array $p):Response{return $this->page($p);}
 public function store(Request $r,array $p):Response { $u=(int)Session::get('user_id',0);if(!$u)return $this->private(Response::redirect('/login'));$clip=$this->id($p);if(!$this->thumbnails->sourceOwned($clip,$u))return $this->missing();try{$meta=[];foreach(['platform'=>30,'title'=>1020,'description'=>20000,'caption'=>8800,'cta'=>2000,'hashtags'=>12000] as $k=>$max)$meta[$k]=$this->field($r,$k,$max,$k==='platform'?'youtube':'');$id=$this->service->save($clip,$u,$this->number($r,'publication_id'),$this->number($r,'version'),$meta,$this->number($r,'thumbnail_id')?:null);if(!$id)return $this->missing();return $this->private(Response::redirect('/clips/'.$clip.'/publicacao#publicacao-'.$id));}catch(PublicationVersionConflict $e){return $this->page($p,$e->getMessage(),409,$r);}catch(\InvalidArgumentException $e){return $this->page($p,$e->getMessage(),422,$r);} }
 public function status(Request $r,array $p):Response { $u=(int)Session::get('user_id',0);if(!$u)return $this->private(Response::redirect('/login'));$id=$this->id($p);$publication=$this->service->findOwned($id,$u);if(!$publication)return $this->missing();try{$this->service->transition($id,$u,$this->number($r,'version'),$this->field($r,'status',30));return $this->private(Response::redirect('/clips/'.$publication['clip_id'].'/publicacao#publicacao-'.$id));}catch(PublicationVersionConflict $e){return $this->page(['id'=>(string)$publication['clip_id']],$e->getMessage(),409);}catch(\InvalidArgumentException $e){return $this->page(['id'=>(string)$publication['clip_id']],$e->getMessage(),422);} }
 public function download(Request $r,array $p):Response { $u=(int)Session::get('user_id',0);if(!$u)return $this->private(Response::redirect('/login'));$format=$r->query('format','json');if(!is_string($format)||!in_array($format,['json','txt'],true))return $this->private(Response::text('Formato inválido.',422));$id=$this->id($p);$data=$this->service->package($id,$u,$format);if($data===null)return $this->missing();return $this->private((new Response($data))->withHeader('Content-Type',$format==='json'?'application/json; charset=UTF-8':'text/plain; charset=UTF-8')->withHeader('Content-Disposition','attachment; filename="publicacao-'.$id.'.'.$format.'"')->withHeader('X-Content-Type-Options','nosniff')); }
 private function page(array $p,?string $error=null,int $status=200,?Request $submitted=null):Response
 {
  $u=(int)Session::get('user_id',0);if(!$u)return $this->private(Response::redirect('/login'));
  $clip=$this->thumbnails->sourceOwned($this->id($p),$u);if(!$clip)return $this->missing();
  $items=$this->service->forClip((int)$clip['id'],$u);$draft=null;
  if($submitted!==null){
   try{$draftId=$this->number($submitted,'publication_id');}catch(\InvalidArgumentException){return $this->missing();}
   if($draftId>0&&!in_array($draftId,array_map('intval',array_column($items,'id')),true))return $this->missing();
   $metadata=[];foreach(['platform','title','description','caption','cta','hashtags'] as $key)$metadata[$key]=$this->draftField($submitted,$key,$key==='platform'?'youtube':'');
   // Display-only draft. Preserve the submitted optimistic version; never replace it with the current version.
   $draft=['id'=>$draftId,'version'=>$this->draftField($submitted,'version','0',128),'thumbnail_id'=>$this->draftField($submitted,'thumbnail_id','0',128),'metadata'=>$metadata,'conflict'=>$status===409];
  }
  foreach($items as &$item)$item['history']=$this->service->history((int)$item['id'],$u);unset($item);
  $user=$this->profile!==null?($this->profile)($u):[];
  $html=$this->view->render('clips.publication',['title'=>'Preparar publicação','user'=>array_replace(['name'=>'Conta','email'=>'','credits'=>0,'plan_name'=>'Plano','monthly_minutes'=>0],$user??[]),'clip'=>$clip,'publications'=>$items,'thumbnails'=>$this->thumbnails->forClip((int)$clip['id'],$u),'error'=>$error,'draft'=>$draft]);
  return $this->private(Response::html($html->body(),$status));
 }
 private function draftField(Request $r,string $key,string $default='',int $maxBytes=65536):string
 {
  $value=$r->input($key,$default);if(!is_string($value)||!mb_check_encoding($value,'UTF-8'))return $default;
  return str_replace("\0",'',mb_strcut($value,0,$maxBytes,'UTF-8'));
 }
 private function field(Request $r,string $key,int $max,string $default=''):string {$v=$r->input($key,$default);if(!is_string($v)||strlen($v)>$max||!mb_check_encoding($v,'UTF-8'))throw new \InvalidArgumentException('Campo inválido.');return $v;}
 private function number(Request $r,string $k):int {$s=$this->field($r,$k,18,'0');if(preg_match('/^(0|[1-9][0-9]{0,17})$/D',$s)!==1)throw new \InvalidArgumentException('Versão ou identificador inválido.');return (int)$s;}
 private function id(array $p):int {$s=$p['id']??'';return is_string($s)&&preg_match('/^[1-9][0-9]{0,17}$/D',$s)?(int)$s:0;}
 private function private(Response $r):Response{return $r->withHeader('Cache-Control','private, no-store');}
 private function missing():Response{return $this->private(Response::text('Não encontrado.',404));}
}
