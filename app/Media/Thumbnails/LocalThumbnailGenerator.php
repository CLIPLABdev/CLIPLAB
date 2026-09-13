<?php
declare(strict_types=1);
namespace App\Media\Thumbnails;
use App\Contracts\PrivateStorage;
use App\Media\LogoDimensions;
use App\Process\ProcessRunner;
/** Local contrast/edge heuristic only; no face, expression or semantic analysis. */
final class LocalThumbnailGenerator
{
 private $logoResolver;
 private string $temp;
 public function __construct(private PrivateStorage $storage,private ProcessRunner $runner,private string $ffmpeg,string $temporaryDirectory,?callable $logoResolver=null) {
  $resolved=realpath($temporaryDirectory);if(!$resolved||!is_writable($resolved)||preg_match('~[\\/](public|public_html)([\\/]|$)~i',$resolved))throw new \InvalidArgumentException('Private temporary directory required.');$this->temp=$resolved;$this->logoResolver=$logoResolver;
  $count=0;foreach(new \DirectoryIterator($this->temp) as $file){if($count>=100)break;if($file->isFile()&&!$file->isLink()&&preg_match('/^thumbnail-[a-f0-9]{32}\.(jpg|txt)$/D',$file->getFilename())&&$file->getMTime()<time()-3600){@unlink($file->getPathname());++$count;}}
 }
 /** Sample fifteen moments; retain the best nonblack frame from each of five temporal bins. */
 public function generate(array $clip): array {
  $path=$this->source($clip);$times=ThumbnailOptions::candidateTimes((float)$clip['render_end_time']-(float)$clip['render_start_time']);$frames=[];
  try { foreach(array_chunk($times,3) as $bin){$best=null;$score=-1;foreach($bin as $offset){$r=$this->runner->run([$this->ffmpeg,'-hide_banner','-loglevel','error','-nostdin','-ss',$this->decimal((float)$clip['render_start_time']+$offset),'-i',$path,'-map','0:v:0','-frames:v','1','-vf','scale=64:36,format=gray','-f','rawvideo','-'],20,100000);if($r->exitCode!==0||strlen($r->stdout)!==2304)continue;$quality=$this->quality($r->stdout);if($quality>$score){$score=$quality;$best=$offset;}}if($best!==null&&$score>=0)$frames[]=$this->design($clip,$best,ThumbnailOptions::fromArray([]));}if(!$frames)throw new \RuntimeException('No nonblack frames available.');return $frames; }catch(\Throwable $e){foreach($frames as $f)@unlink($f['path']);throw $e;}
 }
 public function design(array $clip,float $offset,ThumbnailOptions $options): array {
  $source=$this->source($clip);ThumbnailOptions::offset($offset,(float)$clip['render_end_time']-(float)$clip['render_start_time']);$v=$options->toArray();$nonce=bin2hex(random_bytes(16));$output=$this->temp.'/thumbnail-'.$nonce.'.jpg';$textPath=$this->temp.'/thumbnail-'.$nonce.'.txt';
  $command=[$this->ffmpeg,'-hide_banner','-loglevel','error','-nostdin','-ss',$this->decimal((float)$clip['render_start_time']+$offset),'-i',$source];
  $filter='[0:v]scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2:color=black,setsar=1';
  if($v['template']==='bold')$filter.=',eq=contrast=1.12:saturation=1.12,drawbox=x=0:y=460:w=iw:h=260:color=black@0.72:t=fill';
  if($v['template']==='split')$filter.=',drawbox=x=0:y=0:w=640:h=ih:color=black@0.78:t=fill';
  try {
   if($v['title']!==''){
    $fontSize=$v['font_size'];$availableWidth=$v['template']==='split'?550:1180;
    $longestWord=0;foreach(preg_split('/\s+/u',$v['title'],-1,PREG_SPLIT_NO_EMPTY) as $word)$longestWord=max($longestWord,mb_strlen($word));
    do {
     $columns=max(4,(int)floor($availableWidth/($fontSize*1.05)));
     $text=$this->wrap($v['title'],$columns);
     $height=(substr_count($text,"\n")+1)*($fontSize*1.25+8);
     // Prefer a smaller font to broken words; only split long tokens at the minimum.
     if(($height<=600&&$longestWord<=$columns)||$fontSize===32)break;
     $fontSize=max(32,$fontSize-2);
    } while(true);
    if($height>600) {
     // Excessive forced line breaks cannot remain visible at the supported minimum.
     $text=$this->wrap(preg_replace('/\s+/u',' ',$v['title']),$columns);
     $height=(substr_count($text,"\n")+1)*($fontSize*1.25+8);
     if($height>600)throw new \RuntimeException('Thumbnail title exceeds the supported layout bounds.');
    }
    if(file_put_contents($textPath,$text)===false)throw new \RuntimeException('Text file unavailable.');@chmod($textPath,0600);
    $x=$v['template']==='split'?'40':'(w-text_w)/2';$y=['top'=>'45','center'=>'(h-text_h)/2','bottom'=>'h-text_h-45'][$v['position']];
    $font='font='.$v['font_family'];
    if(DIRECTORY_SEPARATOR==='\\') { $fontPath=rtrim((string)getenv('SystemRoot'),'\\/').'/Fonts/'.['Arial'=>'arial.ttf','Georgia'=>'georgia.ttf','Verdana'=>'verdana.ttf'][$v['font_family']];if(!is_file($fontPath))throw new \RuntimeException('Selected font unavailable.');$font='fontfile=\''.$this->filterPath($fontPath).'\''; }
    $filter.=',drawtext='.$font.':textfile=\''.$this->filterPath($textPath).'\':expansion=none:fontcolor='.$v['color'].':fontsize='.$fontSize.':x='.$x.':y='.$y.':borderw=3:bordercolor=black:shadowx=2:shadowy=2:line_spacing=8';
   }
   if($v['template']!=='clean')$filter.=',drawbox=x=0:y=0:w=iw:h=12:color='.$v['accent_color'].':t=fill';
   if($v['logo_asset_id']>0){$key=$this->logoResolver!==null?($this->logoResolver)($v['logo_asset_id'],(int)$clip['project_id']):null;if(!is_string($key))throw new \RuntimeException('Owned logo unavailable.');$logo=$this->storage->absolutePath($key);$info=@getimagesize($logo);if(!$info||($info['mime']??'')!=='image/png'||$info[0]>2048||$info[1]>2048||filesize($logo)>2097152)throw new \RuntimeException('Logo invalid.');[$logoWidth,$logoHeight]=LogoDimensions::fit($info[0],$info[1],1280,720,10);array_push($command,'-i',$logo);$filter.='[base];[1:v]scale='.$logoWidth.':'.$logoHeight.':flags=lanczos,setsar=1,format=rgba[logo];[base][logo]overlay=W-w-30:30';}
   $filter.='[out]';array_push($command,'-filter_complex',$filter,'-map','[out]','-frames:v','1','-an','-q:v','2','-threads','1','-fs','2097152',$output);
   $r=$this->runner->run($command,30,262144);$info=@getimagesize($output);if($r->exitCode!==0||!$info||$info[0]!==1280||$info[1]!==720||($info['mime']??'')!=='image/jpeg'||filesize($output)>2097152)throw new \RuntimeException('Thumbnail render failed: '.mb_substr($r->stderr,0,2000));return ['path'=>$output,'offset'=>$offset];
  }catch(\Throwable $e){@unlink($output);throw $e;}finally{if(is_file($textPath))@unlink($textPath);}
 }
 private function source(array $clip): string { $path=$this->storage->absolutePath((string)$clip['source_object_key']);if(!is_file($path)||is_link($path))throw new \RuntimeException('Source unavailable.');return $path; }
 private function quality(string $gray): float { $values=array_values(unpack('C*',$gray));$mean=array_sum($values)/count($values);if($mean<16)return -1;$variance=0;$edges=0;foreach($values as $i=>$v){$variance+=($v-$mean)**2;if($i%64)$edges+=abs($v-$values[$i-1]);}return $variance/count($values)+4*$edges/count($values); }
 private function wrap(string $text,int $columns): string { $lines=[];foreach(preg_split('/\R/u',$text) as $paragraph){$line='';foreach(preg_split('/\s+/u',$paragraph,-1,PREG_SPLIT_NO_EMPTY) as $word){if(mb_strlen($line.' '.$word)>$columns&&$line!==''){$lines[]=$line;$line='';}while(mb_strlen($word)>$columns){if($line!==''){$lines[]=$line;$line='';}$lines[]=mb_substr($word,0,$columns);$word=mb_substr($word,$columns);}$line.=($line===''?'':' ').$word;}if($line!=='')$lines[]=$line;}return implode("\n",$lines); }
 private function filterPath(string $path): string { return str_replace(['\\',':',"'"],["/","\\:","'\\''"],$path); }
 private function decimal(float $n): string { return number_format($n,3,'.',''); }
}
