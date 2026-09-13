<?php
declare(strict_types=1);
namespace App\Media\Thumbnails;
use InvalidArgumentException;
final class PublicationMetadata
{
 public const PLATFORMS=['youtube','youtube_shorts','instagram','tiktok','facebook'];
 public const STATES=['draft','ready','exported','marked_published','archived'];
 public static function normalize(array $input): array {
  $v=['platform'=>'youtube','title'=>'','description'=>'','caption'=>'','cta'=>'','hashtags'=>[]];
  if(array_diff(array_keys($input),array_keys($v))) throw new InvalidArgumentException('Metadados inválidos.'); $v=array_replace($v,$input);
  if(!in_array($v['platform'],self::PLATFORMS,true)) throw new InvalidArgumentException('Plataforma inválida.');
  foreach(['title'=>255,'description'=>5000,'caption'=>2200,'cta'=>500] as $k=>$max) if(!is_string($v[$k])||!mb_check_encoding($v[$k],'UTF-8')||mb_strlen($v[$k])>$max||str_contains($v[$k],"\0")) throw new InvalidArgumentException('Revise o tamanho e conteúdo dos metadados.');
  $tags=$v['hashtags']; if(is_string($tags)) $tags=preg_split('/[\s,]+/u',trim($tags),-1,PREG_SPLIT_NO_EMPTY);
  if(!is_array($tags)||count($tags)>30) throw new InvalidArgumentException('Use até 30 hashtags.');
  $v['hashtags']=[]; foreach($tags as $tag) { if(!is_string($tag)||!mb_check_encoding($tag,'UTF-8')||mb_strlen($tag)>100||preg_match('/^#?[\p{L}\p{N}_]+$/uD',$tag)!==1) throw new InvalidArgumentException('Hashtag inválida.'); $v['hashtags'][]='#'.ltrim($tag,'#'); } $v['hashtags']=array_values(array_unique($v['hashtags'])); return $v;
 }
}
