<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Media\Editor\EditorOptions;
use App\Media\LocalFfmpegClipRenderer;
use App\Media\ProjectSource;
use App\Media\Reframe\AspectRatio;
use App\Media\Reframe\ReframePlan;
use App\Media\RenderClipRequest;
use App\Media\Thumbnails\LocalThumbnailGenerator;
use App\Media\Thumbnails\ThumbnailOptions;
use App\Process\ProcessResult;
use App\Process\ProcessRunner;
use App\Storage\LocalPrivateStorage;
use PHPUnit\Framework\TestCase;

final class LogoRenderBoundsTest extends TestCase
{
    /** @dataProvider logoShapes */
    public function testBothRenderersBoundDecodedLogoDimensionsBeforeExecutingFfmpeg(int $width,int $height,string $videoSize,string $thumbnailSize): void
    {
        $root=sys_get_temp_dir().'/logo-bounds-'.bin2hex(random_bytes(8));
        mkdir($root,0700);
        file_put_contents($root.'/source.mp4','fixture');
        file_put_contents($root.'/logo.png',self::png($width,$height));
        $storage=new LocalPrivateStorage($root,2097152);
        // Capture the actual command and stop before any vulnerable FFmpeg graph executes.
        $runner=new class($root) extends ProcessRunner {
            public array $commands=[];
            public function __construct(string $root) { parent::__construct(['ffmpeg'],$root); }
            public function run(array $command,int $timeoutSeconds,int $outputLimitBytes): ProcessResult {
                $this->commands[]=$command;
                return new ProcessResult(1,'','Stopped before execution');
            }
        };
        $resolver=static fn(int $id,int $project): ?string=>$id===7 && $project===1 ? 'logo.png' : null;
        try {
            self::assertSame([$width,$height],array_slice(getimagesize($root.'/logo.png'),0,2));
            $renderer=new LocalFfmpegClipRenderer($storage,$runner,'ffmpeg',$root,30,262144,2097152,2097152,null,$resolver,'ffprobe');
            try {
                $renderer->render(new RenderClipRequest(new ProjectSource(1,1,'local','source.mp4','video/mp4',1920,1080),0.0,1.0,str_repeat('a',32),null,EditorOptions::fromArray(['logo_asset_id'=>7,'logo_scale'=>25])));
                self::fail('Recording runner cannot return an artifact.');
            } catch (\App\Exceptions\ClipRenderException) {}
            self::assertCount(1,$runner->commands);
            $video=$runner->commands[0];
            $videoGraph=$video[array_search('-filter_complex',$video,true)+1];
            self::assertStringContainsString('[1:v:0]scale='.$videoSize.':flags=lanczos',$videoGraph);
            self::assertStringContainsString('overlay=x=main_w-overlay_w-32:y=32:shortest=1',$videoGraph);
            $generator=new LocalThumbnailGenerator($storage,$runner,'ffmpeg',$root,$resolver);
            try {
                $generator->design(['source_object_key'=>'source.mp4','render_start_time'=>0,'render_end_time'=>1,'project_id'=>1],0,ThumbnailOptions::fromArray(['logo_asset_id'=>7]));
                self::fail('Recording runner cannot return an artifact.');
            } catch (\RuntimeException) {}
            self::assertCount(2,$runner->commands);
            $thumbnail=$runner->commands[1];
            self::assertStringContainsString('[1:v]scale='.$thumbnailSize.':flags=lanczos',$thumbnail[array_search('-filter_complex',$thumbnail,true)+1]);
        } finally {
            foreach (glob($root.'/*') ?: [] as $file) unlink($file);
            rmdir($root);
        }
    }

    public function testPortraitVideoLogoUsesAsymmetricSafeZoneInsets(): void
    {
        $root=sys_get_temp_dir().'/logo-safe-zone-'.bin2hex(random_bytes(8));
        mkdir($root,0700);
        file_put_contents($root.'/source.mp4','fixture');
        file_put_contents($root.'/logo.png',self::png(16,16));
        $storage=new LocalPrivateStorage($root,2097152);
        $runner=new class($root) extends ProcessRunner {
            public array $commands=[];
            public function __construct(string $root) { parent::__construct(['ffmpeg'],$root); }
            public function run(array $command,int $timeoutSeconds,int $outputLimitBytes): ProcessResult {
                $this->commands[]=$command;
                return new ProcessResult(1,'','Stopped before execution');
            }
        };
        $resolver=static fn(int $id,int $project): ?string=>$id===7 && $project===1 ? 'logo.png' : null;
        $source=new ProjectSource(1,1,'local','source.mp4','video/mp4',1920,1080);
        $plan=ReframePlan::center(AspectRatio::fromString('9:16'));
        try {
            foreach ([
                'top_left'=>'overlay=x=65:y=192:shortest=1',
                'bottom_right'=>'overlay=x=main_w-overlay_w-65:y=main_h-overlay_h-346:shortest=1',
            ] as $position=>$expected) {
                $renderer=new LocalFfmpegClipRenderer($storage,$runner,'ffmpeg',$root,30,262144,2097152,2097152,null,$resolver,'ffprobe');
                try {
                    $renderer->render(new RenderClipRequest($source,0.0,1.0,str_repeat('a',32),$plan,EditorOptions::fromArray([
                        'logo_asset_id'=>7,'logo_position'=>$position,
                    ])));
                    self::fail('Recording runner cannot return an artifact.');
                } catch (\App\Exceptions\ClipRenderException) {}
                $command=$runner->commands[count($runner->commands)-1];
                self::assertStringContainsString($expected,$command[array_search('-filter_complex',$command,true)+1]);
            }
            self::assertCount(2,$runner->commands);
        } finally {
            foreach (glob($root.'/*') ?: [] as $file) unlink($file);
            rmdir($root);
        }
    }

    public function logoShapes(): iterable
    {
        yield 'one pixel wide' => [1,2048,'1:270','1:180'];
        yield 'one pixel tall' => [2048,1,'480:1','128:1'];
        yield 'normal brand' => [1024,512,'480:240','128:64'];
        yield 'square capped by height' => [1024,1024,'270:270','128:128'];
    }

    private static function png(int $width,int $height): string
    {
        $chunk=static fn(string $type,string $data): string=>pack('N',strlen($data)).$type.$data.pack('N',crc32($type.$data));
        $rows=str_repeat("\0".str_repeat("\0\xff\0\xff",$width),$height);
        return "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',$width,$height,8,6,0,0,0)).$chunk('IDAT',gzcompress($rows)).$chunk('IEND','');
    }
}
