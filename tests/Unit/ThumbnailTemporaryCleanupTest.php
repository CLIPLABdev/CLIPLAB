<?php
declare(strict_types=1);
namespace Tests\Unit;
use PHPUnit\Framework\TestCase;
use App\Media\Thumbnails\LocalThumbnailGenerator;
use App\Storage\LocalPrivateStorage;
use App\Process\ProcessRunner;
final class ThumbnailTemporaryCleanupTest extends TestCase
{
 public function test_recovers_only_stale_thumbnail_temp_files():void { $tmp=sys_get_temp_dir().'/thumb-temp-'.bin2hex(random_bytes(8));mkdir($tmp,0700);$stale=$tmp.'/thumbnail-'.str_repeat('a',32).'.txt';$fresh=$tmp.'/thumbnail-'.str_repeat('b',32).'.jpg';$other=$tmp.'/keep.txt';file_put_contents($stale,'Old title');file_put_contents($fresh,'Fresh');file_put_contents($other,'Keep');touch($stale,time()-7200);touch($other,time()-7200);try{new LocalThumbnailGenerator(new LocalPrivateStorage($tmp,1000),new ProcessRunner(['ffmpeg'],$tmp),'ffmpeg',$tmp);self::assertFileDoesNotExist($stale);self::assertFileExists($fresh);self::assertFileExists($other);}finally{foreach(glob($tmp.'/*') as $f)unlink($f);rmdir($tmp);} }
}
