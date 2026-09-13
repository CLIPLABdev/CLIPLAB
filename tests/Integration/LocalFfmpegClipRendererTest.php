<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Media\LocalFfmpegClipRenderer;
use App\Media\LocalFfmpegAudioExtractor;
use App\Media\Editor\EditorOptions;
use App\Media\Subtitles\SubtitleCue;
use App\Media\Subtitles\Transcript;
use App\Media\ProjectSource;
use App\Media\RenderClipRequest;
use App\Process\ProcessRunner;
use App\Storage\LocalPrivateStorage;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class LocalFfmpegClipRendererTest extends TestCase
{
    private string $root;
    private string $storageRoot;
    private string $renderRoot;
    private string $ffmpegBinary;
    private string $ffprobeBinary;

    protected function setUp(): void
    {
        $this->ffmpegBinary = $this->requiredBinary('TEST_FFMPEG_BIN');
        $this->ffprobeBinary = $this->requiredBinary('TEST_FFPROBE_BIN');
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clipforge-real-render-' . bin2hex(random_bytes(8));
        $this->storageRoot = $this->root . DIRECTORY_SEPARATOR . 'storage';
        $this->renderRoot = $this->root . DIRECTORY_SEPARATOR . 'render';

        self::assertTrue(mkdir($this->storageRoot, 0700, true));
        self::assertTrue(mkdir($this->renderRoot, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root ?? '');
    }

    public function testRendersAndProbesARealClipAndThumbnail(): void
    {
        $runner = new ProcessRunner([$this->ffmpegBinary, $this->ffprobeBinary], $this->renderRoot);
        $storage = new LocalPrivateStorage($this->storageRoot, 32 * 1024 * 1024);
        $sourceKey = 'imports/1/source.mp4';
        $sourcePath = $storage->absolutePath($sourceKey);
        self::assertTrue(mkdir(dirname($sourcePath), 0700, true));

        $generated = $runner->run([
            $this->ffmpegBinary,
            '-y',
            '-nostdin',
            '-hide_banner',
            '-loglevel', 'error',
            '-f', 'lavfi',
            '-i', 'testsrc=size=640x360:rate=30:duration=4',
            '-f', 'lavfi',
            '-i', 'sine=frequency=1000:sample_rate=48000:duration=4',
            '-map', '0:v:0',
            '-map', '1:a:0',
            '-t', '4.000',
            '-c:v', 'libx264',
            '-preset', 'ultrafast',
            '-crf', '23',
            '-pix_fmt', 'yuv420p',
            '-c:a', 'aac',
            '-b:a', '128k',
            '-movflags', '+faststart',
            $sourcePath,
        ], 30, 1024 * 1024);
        self::assertSame(0, $generated->exitCode, 'Synthetic FFmpeg source generation failed.');
        self::assertFileExists($sourcePath);
        self::assertGreaterThan(0, filesize($sourcePath));

        $renderer = new LocalFfmpegClipRenderer(
            $storage,
            $runner,
            $this->ffmpegBinary,
            $this->renderRoot,
            30,
            1024 * 1024,
            32 * 1024 * 1024,
            4 * 1024 * 1024,
            null,
            null,
            $this->ffprobeBinary
        );
        $artifacts = $renderer->render(new RenderClipRequest(
            new ProjectSource(1, 1, 'local', $sourceKey, 'video/mp4'),
            1.0,
            2.0,
            str_repeat('a', 32)
        ));

        try {
            self::assertFileExists($artifacts->videoPath());
            self::assertFileExists($artifacts->thumbnailPath());
            self::assertGreaterThan(0, $artifacts->videoSizeBytes());
            self::assertGreaterThan(0, $artifacts->thumbnailSizeBytes());
            self::assertSame($artifacts->videoSizeBytes(), filesize($artifacts->videoPath()));
            self::assertSame($artifacts->thumbnailSizeBytes(), filesize($artifacts->thumbnailPath()));
            self::assertSame('video/mp4', $artifacts->videoMimeType());
            self::assertSame('image/jpeg', $artifacts->thumbnailMimeType());

            $video = $this->probe($runner, $artifacts->videoPath());
            self::assertStringContainsString('mp4', (string) ($video['format']['format_name'] ?? ''));
            self::assertEqualsWithDelta(2.0, (float) ($video['format']['duration'] ?? 0.0), 0.35);
            self::assertSame('h264', $this->streamCodec($video, 'video'));
            self::assertSame('aac', $this->streamCodec($video, 'audio'));

            $thumbnail = $this->probe($runner, $artifacts->thumbnailPath());
            self::assertStringContainsString('image2', (string) ($thumbnail['format']['format_name'] ?? ''));
            self::assertSame('mjpeg', $this->streamCodec($thumbnail, 'video'));
            $thumbnailStream = $this->stream($thumbnail, 'video');
            self::assertGreaterThan(0, (int) ($thumbnailStream['width'] ?? 0));
            self::assertLessThanOrEqual(640, (int) ($thumbnailStream['width'] ?? 0));

            $audio = (new LocalFfmpegAudioExtractor($storage, $runner, $this->ffmpegBinary, $this->renderRoot, 30, 1024 * 1024))
                ->extract(new ProjectSource(1, 1, 'local', $sourceKey, 'video/mp4'), 1.0, 2.0);
            self::assertSame('RIFF', substr($audio, 0, 4));
            self::assertSame([], glob($this->renderRoot . '/clipforge-audio-*.wav'));
            $audioFixture = $this->renderRoot . '/probe-extracted-audio.wav';
            file_put_contents($audioFixture, $audio);
            try {
                $audioProbe = $this->probe($runner, $audioFixture);
                $audioStream = $this->stream($audioProbe, 'audio');
                self::assertSame('pcm_s16le', (string) ($audioStream['codec_name'] ?? ''));
                self::assertSame(1, (int) ($audioStream['channels'] ?? 0));
                self::assertSame('16000', (string) ($audioStream['sample_rate'] ?? ''));
            } finally { @unlink($audioFixture); }

            $overlay = $renderer->render(new RenderClipRequest(
                new ProjectSource(1, 1, 'local', $sourceKey, 'video/mp4', 640, 360), 1.0, 2.0,
                str_repeat('b', 32), null,
                EditorOptions::fromArray(['style' => 'minimal', 'title' => 'Texto visível']),
                new Transcript('pt-BR', [new SubtitleCue(0, 1800, 'Legenda real')], 2000)
            ));
            try { self::assertNotSame(hash_file('sha256', $artifacts->videoPath()), hash_file('sha256', $overlay->videoPath())); }
            finally { $overlay->cleanup(); }

            $videoPath = $artifacts->videoPath();
            $thumbnailPath = $artifacts->thumbnailPath();
            $artifacts->cleanup();
            self::assertFileDoesNotExist($videoPath);
            self::assertFileDoesNotExist($thumbnailPath);
        } finally {
            $artifacts->cleanup();
        }
    }

    /** @return array<string, mixed> */
    public function testFullHdFormatsCompositeOwnedPngAndDecodeCompletely(): void
    {
        $runner=new ProcessRunner([$this->ffmpegBinary,$this->ffprobeBinary],$this->renderRoot);
        $storage=new LocalPrivateStorage($this->storageRoot,32*1024*1024);
        $sourceKey='imports/1/blue.mp4';
        $logoKey='brands/1/logo.png';
        foreach ([$sourceKey,$logoKey] as $key) mkdir(dirname($storage->absolutePath($key)),0700,true);
        foreach ([
            ['-f','lavfi','-i','color=c=blue:s=320x180:r=10:d=1','-c:v','libx264','-pix_fmt','yuv420p',$storage->absolutePath($sourceKey)],
            ['-f','lavfi','-i','color=c=lime:s=32x32','-frames:v','1',$storage->absolutePath($logoKey)],
        ] as $args) {
            $result=$runner->run(array_merge([$this->ffmpegBinary,'-nostdin','-hide_banner','-loglevel','error'],$args),30,1024*1024);
            self::assertSame(0,$result->exitCode,$result->stderr);
        }
        $renderer=new LocalFfmpegClipRenderer($storage,$runner,$this->ffmpegBinary,$this->renderRoot,60,1024*1024,32*1024*1024,4*1024*1024,null,
            static fn(int $id,int $project): ?string=>$id===45 && $project===1 ? $logoKey : null,$this->ffprobeBinary);
        foreach ([
            ['9:16',1080,1920,80,207],
            ['4:5',1080,1350,80,150],
            ['1:1',1080,1080,40,40],
            ['16:9',1920,1080,40,40],
            ['legacy',720,1280,55,140],
        ] as [$ratio,$w,$h,$sampleX,$sampleY]) {
            $aspect=$ratio==='legacy' ? \App\Media\Reframe\AspectRatio::fromStored('9:16',720,1280) : \App\Media\Reframe\AspectRatio::fromString($ratio);
            $artifacts=$renderer->render(new RenderClipRequest(new ProjectSource(1,1,'local',$sourceKey,'video/mp4',320,180),0.0,1.0,str_repeat('a',32),
                \App\Media\Reframe\ReframePlan::center($aspect),
                EditorOptions::fromArray(['style'=>'karaoke','font_family'=>'Verdana','animation'=>'pop','cta_text'=>'Siga','logo_asset_id'=>45,'logo_position'=>'top_left']),
                new Transcript('pt-BR',[new SubtitleCue(0,1000,'Olá',[['start_ms'=>0,'end_ms'=>1000,'text'=>'Olá']])],1000)));
            try {
                $stream=$this->stream($this->probe($runner,$artifacts->videoPath()),'video');
                self::assertSame([$w,$h],[(int)$stream['width'],(int)$stream['height']]);
                $decoded=$runner->run([$this->ffmpegBinary,'-nostdin','-hide_banner','-loglevel','error','-i',$artifacts->videoPath(),'-f','null','-'],30,1024*1024);
                self::assertSame(0,$decoded->exitCode,$decoded->stderr);
                self::assertSame('',$decoded->stderr);
                $pixel=$runner->run([$this->ffmpegBinary,'-nostdin','-hide_banner','-loglevel','error','-i',$artifacts->videoPath(),'-frames:v','1','-vf',sprintf('crop=2:2:%d:%d,scale=1:1',$sampleX,$sampleY),'-pix_fmt','rgb24','-f','rawvideo','pipe:1'],30,1024);
                self::assertSame(0,$pixel->exitCode,$pixel->stderr);
                self::assertSame(3,strlen($pixel->stdout));
                self::assertGreaterThan(180,ord($pixel->stdout[1]),'Owned green logo must be visible.');
                self::assertLessThan(70,ord($pixel->stdout[2]));
            } finally { $artifacts->cleanup(); }
        }
        $collision=$renderer->render(new RenderClipRequest(
            new ProjectSource(1,1,'local',$sourceKey,'video/mp4',320,180),0.0,1.0,str_repeat('c',32),
            \App\Media\Reframe\ReframePlan::center(\App\Media\Reframe\AspectRatio::fromString('9:16')),
            EditorOptions::fromArray([
                'style'=>'none','title'=>'Fidelidade em debate','logo_asset_id'=>45,
                'logo_position'=>'top_right','logo_scale'=>10,
            ])
        ));
        try {
            $frame=$runner->run([
                $this->ffmpegBinary,'-nostdin','-hide_banner','-loglevel','error','-i',$collision->videoPath(),
                '-frames:v','1','-vf','crop=1080:320:0:160','-pix_fmt','rgb24','-f','rawvideo','pipe:1',
            ],30,2*1024*1024);
            self::assertSame(0,$frame->exitCode,$frame->stderr);
            self::assertSame(1080*320*3,strlen($frame->stdout));
            $logoMinX=1080;
            $titleMaxX=-1;
            for ($offset=0,$length=strlen($frame->stdout);$offset<$length;$offset+=3) {
                $x=intdiv($offset,3)%1080;
                $red=ord($frame->stdout[$offset]);
                $green=ord($frame->stdout[$offset+1]);
                $blue=ord($frame->stdout[$offset+2]);
                if ($green>150 && $red<140 && $blue<140) $logoMinX=min($logoMinX,$x);
                if ($red>170 && $green>170 && $blue>170) $titleMaxX=max($titleMaxX,$x);
            }
            self::assertLessThan(1080,$logoMinX,'The real frame must contain the top-right logo.');
            self::assertGreaterThanOrEqual(0,$titleMaxX,'The real frame must contain the title.');
            self::assertLessThan($logoMinX,$titleMaxX,'The title must end before the reserved logo corner.');
        } finally { $collision->cleanup(); }
        $thumbnailGenerator=new \App\Media\Thumbnails\LocalThumbnailGenerator($storage,$runner,$this->ffmpegBinary,$this->renderRoot,
            static fn(int $id,int $project): ?string=>$id===45 && $project===1 ? $logoKey : null);
        $thumbnail=$thumbnailGenerator->design(['source_object_key'=>$sourceKey,'render_start_time'=>0,'render_end_time'=>1,'project_id'=>1],0,
            \App\Media\Thumbnails\ThumbnailOptions::fromArray(['logo_asset_id'=>45]));
        try {
            $info=getimagesize($thumbnail['path']);
            self::assertSame([1280,720],array_slice($info,0,2));
            $decoded=$runner->run([$this->ffmpegBinary,'-nostdin','-hide_banner','-loglevel','error','-i',$thumbnail['path'],'-f','null','-'],30,1024*1024);
            self::assertSame(0,$decoded->exitCode,$decoded->stderr);
            $pixel=$runner->run([$this->ffmpegBinary,'-nostdin','-hide_banner','-loglevel','error','-i',$thumbnail['path'],'-frames:v','1','-vf','crop=2:2:1180:40,scale=1:1','-pix_fmt','rgb24','-f','rawvideo','pipe:1'],30,1024);
            self::assertSame(0,$pixel->exitCode,$pixel->stderr);
            self::assertSame(3,strlen($pixel->stdout));
            self::assertGreaterThan(180,ord($pixel->stdout[1]),'Owned green logo must remain visible in thumbnail.');
            self::assertLessThan(70,ord($pixel->stdout[2]));
        } finally { unlink($thumbnail['path']); }
    }

    public function testRejectsSyntheticFractionalEofOvershootAndAcceptsPreciseEnd(): void
    {
        $runner=new ProcessRunner([$this->ffmpegBinary,$this->ffprobeBinary],$this->renderRoot);
        $storage=new LocalPrivateStorage($this->storageRoot,32*1024*1024);
        $sourceKey='source-fractional.mp4';
        $generated=$runner->run([
            $this->ffmpegBinary,'-nostdin','-hide_banner','-loglevel','error',
            '-f','lavfi','-i','testsrc=size=160x90:rate=30:duration=2.1',
            '-c:v','libx264','-pix_fmt','yuv420p',$storage->absolutePath($sourceKey),
        ],30,1024*1024);
        self::assertSame(0,$generated->exitCode);
        $renderer=new LocalFfmpegClipRenderer($storage,$runner,$this->ffmpegBinary,$this->renderRoot,30,1024*1024,32*1024*1024,4*1024*1024,null,null,$this->ffprobeBinary);
        $source=new ProjectSource(1,1,'local',$sourceKey,'video/mp4',160,90);
        try {
            $unexpected=$renderer->render(new RenderClipRequest($source,0.0,3.0,str_repeat('d',32)));
            $unexpected->cleanup();
            self::fail('A complete decode does not justify returning an EOF-short MP4.');
        } catch (\App\Exceptions\ClipRenderException $error) {
            self::assertSame('render_output_invalid',$error->publicCode());
        }
        self::assertSame([],glob($this->renderRoot.'/clipforge-*'));
        $artifacts=$renderer->render(new RenderClipRequest($source,0.0,2.1,str_repeat('e',32)));
        try {
            $metadata=$this->probe($runner,$artifacts->videoPath());
            self::assertEqualsWithDelta(2.1,(float)$metadata['format']['duration'],0.067);
            $decode=$runner->run([$this->ffmpegBinary,'-nostdin','-hide_banner','-loglevel','error','-xerror','-i',$artifacts->videoPath(),'-f','null','-'],30,1024*1024);
            self::assertSame(0,$decode->exitCode);
            self::assertSame('',$decode->stderr);
        } finally { $artifacts->cleanup(); }
    }

    /** @return array<string, mixed> */
    private function probe(ProcessRunner $runner, string $path): array
    {
        $result = $runner->run([
            $this->ffprobeBinary,
            '-v', 'error',
            '-show_entries', 'format=format_name,duration:stream=codec_type,codec_name,width,height,channels,sample_rate',
            '-of', 'json',
            $path,
        ], 15, 1024 * 1024);
        self::assertSame(0, $result->exitCode, 'FFprobe inspection failed.');
        $metadata = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($metadata);

        return $metadata;
    }

    /** @param array<string, mixed> $metadata */
    private function streamCodec(array $metadata, string $type): string
    {
        return (string) ($this->stream($metadata, $type)['codec_name'] ?? '');
    }

    /** @param array<string, mixed> $metadata @return array<string, mixed> */
    private function stream(array $metadata, string $type): array
    {
        foreach (($metadata['streams'] ?? []) as $stream) {
            if (is_array($stream) && ($stream['codec_type'] ?? null) === $type) {
                return $stream;
            }
        }

        return [];
    }

    private function requiredBinary(string $variable): string
    {
        $configured = getenv($variable);
        if (!is_string($configured) || trim($configured) === '') {
            self::fail($variable . ' must point to the real media binary for this integration test.');
        }
        $resolved = realpath($configured);
        if ($resolved === false || !is_file($resolved) || !is_executable($resolved)) {
            self::fail($variable . ' must point to an executable file.');
        }

        return $resolved;
    }

    private function removeDirectory(string $directory): void
    {
        if ($directory === '' || !is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
