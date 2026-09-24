<?php
declare(strict_types=1);
namespace Tests\Integration;
use App\Media\Thumbnails\LocalThumbnailGenerator;
use App\Media\Thumbnails\ThumbnailOptions;
use App\Storage\LocalPrivateStorage;
use App\Process\ProcessRunner;
use PHPUnit\Framework\TestCase;
final class ThumbnailGeneratorTest extends TestCase
{
 public function test_all_black_source_fails_without_leaving_jpegs(): void {
  $bin=getenv('TEST_FFMPEG_BINARY');if(!$bin)$this->markTestSkipped('Set TEST_FFMPEG_BINARY.');$root=sys_get_temp_dir().'/thumbnail-black-'.bin2hex(random_bytes(8));mkdir($root,0700);$runner=new ProcessRunner([$bin],$root);$runner->run([$bin,'-v','error','-f','lavfi','-i','color=black:size=64x36:rate=10','-t','2','-c:v','libx264',$root.'/black.mp4'],10,100000);try{$generator=new LocalThumbnailGenerator(new LocalPrivateStorage($root,100000),$runner,$bin,$root);try{$generator->generate(['source_object_key'=>'black.mp4','render_start_time'=>0,'render_end_time'=>2,'project_id'=>1]);self::fail('Black source was accepted');}catch(\RuntimeException $e){self::assertStringContainsString('nonblack',$e->getMessage());}self::assertSame([],glob($root.'/thumbnail-*.jpg'));}finally{foreach(glob($root.'/*') as $f)unlink($f);rmdir($root);}
 }
 public function test_maximum_title_keeps_all_lines_inside_safe_margins(): void {
  $bin=getenv('TEST_FFMPEG_BINARY');if(!$bin)$this->markTestSkipped('Set TEST_FFMPEG_BINARY.');$root=sys_get_temp_dir().'/thumbnail-title-'.bin2hex(random_bytes(8));mkdir($root,0700);$runner=new ProcessRunner([$bin],$root);$runner->run([$bin,'-v','error','-f','lavfi','-i','color=black:size=1280x720:rate=10','-t','2','-c:v','libx264',$root.'/black.mp4'],10,100000);try{$g=new LocalThumbnailGenerator(new LocalPrivateStorage($root,100000),$runner,$bin,$root);$a=$g->design(['source_object_key'=>'black.mp4','render_start_time'=>0,'render_end_time'=>2,'project_id'=>1],1,ThumbnailOptions::fromArray(['title'=>str_repeat('W',120),'font_size'=>96]));$r=$runner->run([$bin,'-hide_banner','-i',$a['path'],'-vf','bbox=min_val=80','-f','null','-'],10,100000);self::assertSame(1,preg_match('/x1:(\d+) x2:(\d+) y1:(\d+) y2:(\d+)/',$r->stderr,$bounds));self::assertGreaterThanOrEqual(30,(int)$bounds[3]);self::assertLessThan(690,(int)$bounds[4]);}finally{foreach(glob($root.'/*') as $f)unlink($f);rmdir($root);}
 }
 public function test_five_real_jpegs_and_three_distinct_designs_decode(): void {
  $bin=getenv('TEST_FFMPEG_BINARY');if(!$bin)$this->markTestSkipped('Set TEST_FFMPEG_BINARY for the local FFmpeg fixture.');
  $root=sys_get_temp_dir().'/cliplab-thumbnails-'.bin2hex(random_bytes(8));mkdir($root,0700);$runner=new ProcessRunner([$bin],$root);$storage=new LocalPrivateStorage($root,20000000);
  $fixture=$root.'/fixture.mp4';$r=$runner->run([$bin,'-hide_banner','-loglevel','error','-f','lavfi','-i','testsrc2=size=1280x720:rate=10','-t','4','-c:v','libx264','-pix_fmt','yuv420p',$fixture],30,100000);self::assertSame(0,$r->exitCode);
  $generator=new LocalThumbnailGenerator($storage,$runner,$bin,$root);$artifacts=[];
  try { $artifacts=$generator->generate(['source_object_key'=>'fixture.mp4','render_start_time'=>0,'render_end_time'=>4,'project_id'=>1]);self::assertCount(5,$artifacts);$times=[];foreach($artifacts as $a){$times[]=$a['offset'];$size=getimagesize($a['path']);self::assertSame([1280,720],[$size[0],$size[1]]);self::assertSame('image/jpeg',$size['mime']);$decoded=$runner->run([$bin,'-v','error','-i',$a['path'],'-f','null','-'],10,100000);self::assertSame(0,$decoded->exitCode);}self::assertCount(5,array_unique($times));
   $hashes=[];foreach(['clean','bold','split'] as $template){$a=$generator->design(['source_object_key'=>'fixture.mp4','render_start_time'=>0,'render_end_time'=>4,'project_id'=>1],1,ThumbnailOptions::fromArray(['template'=>$template,'title'=>"Olá: texto 100% {seguro} ' teste"]));$artifacts[]=$a;$hashes[]=hash_file('sha256',$a['path']);self::assertSame(1280,getimagesize($a['path'])[0]);$evidence=getenv('TEST_THUMBNAIL_EVIDENCE_DIR');if($evidence&&is_dir($evidence))copy($a['path'],$evidence.'/'.$template.'.jpg');}self::assertCount(3,array_unique($hashes));
  } finally { foreach($artifacts as $a)@unlink($a['path']);foreach(glob($root.'/*')?:[] as $file)if(is_file($file))@unlink($file);@rmdir($root); }
 }
}

