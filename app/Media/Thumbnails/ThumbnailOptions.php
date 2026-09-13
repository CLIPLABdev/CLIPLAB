<?php
declare(strict_types=1);
namespace App\Media\Thumbnails;
use InvalidArgumentException;
final class ThumbnailOptions
{
 private function __construct(private array $values) {}
 public static function fromArray(array $input): self {
  $v=['template'=>'clean','title'=>'','font_family'=>'Arial','color'=>'#FFFFFF','accent_color'=>'#F5C542','font_size'=>64,'position'=>'bottom','logo_asset_id'=>0];
  if(array_diff(array_keys($input),array_keys($v))) throw new InvalidArgumentException('Controles de capa inválidos.');
  $v=array_replace($v,$input);
  foreach(['template'=>['clean','bold','split'],'font_family'=>['Arial','Georgia','Verdana'],'position'=>['top','center','bottom']] as $k=>$allowed) if(!in_array($v[$k],$allowed,true)) throw new InvalidArgumentException('Opção de capa inválida.');
  foreach(['color','accent_color'] as $k) if(!is_string($v[$k])||preg_match('/^#[0-9a-fA-F]{6}$/D',$v[$k])!==1) throw new InvalidArgumentException('Cor inválida.');
  if(!is_string($v['title'])||!mb_check_encoding($v['title'],'UTF-8')||mb_strlen($v['title'])>120||preg_match('/[\x00-\x08\x0B-\x1F]/',$v['title'])) throw new InvalidArgumentException('Título deve ter até 120 caracteres.');
  foreach(['font_size'=>[32,96],'logo_asset_id'=>[0,PHP_INT_MAX]] as $k=>$range) { if(!(is_int($v[$k]) || (is_string($v[$k])&&preg_match('/^(0|[1-9][0-9]{0,17})$/D',$v[$k])))) throw new InvalidArgumentException('Número inválido.'); $v[$k]=(int)$v[$k]; if($v[$k]<$range[0]||$v[$k]>$range[1]) throw new InvalidArgumentException('Número fora do limite.'); }
  return new self($v);
 }
 public function toArray(): array { return $this->values; }
 public static function offset(mixed $value,float $duration): float { if(!(is_int($value)||is_float($value)||(is_string($value)&&preg_match('/^(0|[1-9][0-9]{0,5})(\.[0-9]{1,3})?$/D',$value)))) throw new InvalidArgumentException('Tempo inválido.'); $n=(float)$value; if(!is_finite($n)||$n<0||$n>=$duration||$duration>180||$duration<=0) throw new InvalidArgumentException('Selecione um momento dentro do clipe.'); return $n; }
 public static function candidateTimes(float $duration): array { self::offset(0,$duration); return array_map(static fn(int $i): float=>round($duration*$i/16,3),range(1,15)); }
}
