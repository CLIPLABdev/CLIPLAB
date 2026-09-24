<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Media\LocalFfmpegClipRenderer;
use App\Media\ProjectSource;
use App\Media\Reframe\AspectRatio;
use App\Media\Reframe\ReframeKeyframe;
use App\Media\Reframe\ReframePlan;
use App\Media\RenderClipRequest;
use App\Process\ProcessRunner;
use App\Storage\LocalPrivateStorage;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class SmartReframeFfmpegTest extends TestCase
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
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cliplab-smart-reframe-' . bin2hex(random_bytes(8));
        $this->storageRoot = $this->root . DIRECTORY_SEPARATOR . 'storage';
        $this->renderRoot = $this->root . DIRECTORY_SEPARATOR . 'render';

        self::assertTrue(mkdir($this->storageRoot, 0700, true));
        self::assertTrue(mkdir($this->renderRoot, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root ?? '');
    }

    public function testRendersAllSmartReframeCasesWithRealFfmpeg(): void
    {
        $runner = new ProcessRunner([$this->ffmpegBinary, $this->ffprobeBinary], $this->renderRoot);
        $storage = new LocalPrivateStorage($this->storageRoot, 64 * 1024 * 1024);
        $landscapeKey = 'imports/1/smart-landscape.mp4';
        $portraitKey = 'imports/1/smart-portrait.mp4';
        $landscapePath = $storage->absolutePath($landscapeKey);
        $portraitPath = $storage->absolutePath($portraitKey);
        self::assertTrue(mkdir(dirname($landscapePath), 0700, true));
        $this->generateSource($runner, $landscapePath, false);
        $this->generateSource($runner, $portraitPath, true);

        $renderer = new LocalFfmpegClipRenderer(
            $storage,
            $runner,
            $this->ffmpegBinary,
            $this->renderRoot,
            60,
            1024 * 1024,
            64 * 1024 * 1024,
            8 * 1024 * 1024,
            null,
            null,
            $this->ffprobeBinary
        );
        $landscape = new ProjectSource(1, 1, 'local', $landscapeKey, 'video/mp4', 640, 360);
        $portrait = new ProjectSource(2, 1, 'local', $portraitKey, 'video/mp4', 360, 640);
        $plans = [
            'original' => [$landscape, ReframePlan::original(), [640, 360], 'a'],
            'center 9:16' => [$landscape, ReframePlan::center(AspectRatio::fromString('9:16')), [1080, 1920], 'b'],
            'manual 1:1' => [
                $landscape,
                ReframePlan::manual(AspectRatio::fromString('1:1'), new ReframeKeyframe(0, 0.9, 0.5, 'manual')),
                [1080, 1080],
                'c',
            ],
            'automatic 4:5' => [
                $landscape,
                ReframePlan::automatic(AspectRatio::fromString('4:5'), ReframePlan::DETECTOR_VERSION, [
                    new ReframeKeyframe(0, 0.1, 0.5, 'detected'),
                    new ReframeKeyframe(2000, 0.9, 0.5, 'detected'),
                ]),
                [1080, 1350],
                'd',
            ],
            'portrait to 16:9' => [$portrait, ReframePlan::center(AspectRatio::fromString('16:9')), [1920, 1080], 'e'],
        ];

        foreach ($plans as $name => [$source, $plan, $expectedDimensions, $tokenCharacter]) {
            $artifacts = $renderer->render(new RenderClipRequest(
                $source,
                0.0,
                2.0,
                str_repeat($tokenCharacter, 32),
                $plan
            ));
            try {
                $video = $this->probe($runner, $artifacts->videoPath());
                self::assertSame($expectedDimensions, $this->dimensions($video), $name);
                self::assertSame('h264', $this->streamCodec($video, 'video'), $name);
                self::assertSame('aac', $this->streamCodec($video, 'audio'), $name);
                self::assertEqualsWithDelta(2.0, $this->duration($video), 0.2, $name);

                $thumbnail = $this->probe($runner, $artifacts->thumbnailPath());
                self::assertSame('mjpeg', $this->streamCodec($thumbnail, 'video'), $name);
                $this->assertSameAspect($expectedDimensions, $this->dimensions($thumbnail), $name);

                if ($name === 'automatic 4:5') {
                    $firstPath = $this->root . DIRECTORY_SEPARATOR . 'automatic-first.jpg';
                    $lastPath = $this->root . DIRECTORY_SEPARATOR . 'automatic-last.jpg';
                    $this->extractJpeg($runner, $artifacts->videoPath(), 0.1, $firstPath);
                    $this->extractJpeg($runner, $artifacts->videoPath(), 1.8, $lastPath);
                    $first = $this->averageChannels($runner, $firstPath);
                    $last = $this->averageChannels($runner, $lastPath);
                    self::assertGreaterThan($first['blue'], $first['red']);
                    self::assertGreaterThan($last['red'], $last['blue']);
                }
            } finally {
                $artifacts->cleanup();
            }
        }
    }

    private function generateSource(ProcessRunner $runner, string $path, bool $portrait): void
    {
        $fixture = realpath(dirname(__DIR__) . '/Fixtures/mediapipe-synthetic-face.png');
        self::assertNotFalse($fixture);
        $size = $portrait ? '360x640' : '640x360';
        $background = $portrait
            ? '[0:v]drawbox=x=0:y=320:w=360:h=320:color=blue:t=fill[bg]'
            : '[0:v]drawbox=x=320:y=0:w=320:h=360:color=blue:t=fill[bg]';
        $overlay = $portrait
            ? "[bg][face]overlay=x=(W-w)/2:y=40+(H-h-80)*t/3:shortest=1[outv]"
            : "[bg][face]overlay=x=40+(W-w-80)*t/3:y=(H-h)/2:shortest=1[outv]";
        $result = $runner->run([
            $this->ffmpegBinary,
            '-y', '-nostdin', '-hide_banner', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'color=color=red:size=' . $size . ':rate=30:duration=3',
            '-loop', '1', '-i', $fixture,
            '-f', 'lavfi', '-i', 'sine=frequency=880:sample_rate=48000:duration=3',
            '-filter_complex', $background . ';[1:v]scale=96:96[face];' . $overlay,
            '-map', '[outv]', '-map', '2:a:0', '-t', '3.000',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '23', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart',
            $path,
        ], 30, 1024 * 1024);
        self::assertSame(0, $result->exitCode, 'Synthetic FFmpeg source generation failed.');
    }

    private function extractJpeg(ProcessRunner $runner, string $videoPath, float $atSeconds, string $outputPath): void
    {
        $result = $runner->run([
            $this->ffmpegBinary,
            '-y', '-nostdin', '-hide_banner', '-loglevel', 'error',
            '-ss', number_format($atSeconds, 3, '.', ''), '-i', $videoPath,
            '-frames:v', '1', '-q:v', '2', $outputPath,
        ], 15, 1024 * 1024);
        self::assertSame(0, $result->exitCode, 'JPEG sample extraction failed.');
        self::assertFileExists($outputPath);
    }

    /** @return array{red:int, green:int, blue:int} */
    private function averageChannels(ProcessRunner $runner, string $jpegPath): array
    {
        $result = $runner->run([
            $this->ffmpegBinary,
            '-nostdin', '-hide_banner', '-loglevel', 'error', '-i', $jpegPath,
            '-vf', 'scale=1:1:flags=area', '-frames:v', '1', '-pix_fmt', 'rgb24', '-f', 'rawvideo', '-',
        ], 15, 1024 * 1024);
        self::assertSame(0, $result->exitCode, 'JPEG sample inspection failed.');
        self::assertSame(3, strlen($result->stdout));
        $channels = unpack('Cred/Cgreen/Cblue', $result->stdout);
        self::assertIsArray($channels);

        return [
            'red' => (int) $channels['red'],
            'green' => (int) $channels['green'],
            'blue' => (int) $channels['blue'],
        ];
    }

    /** @return array<string, mixed> */
    private function probe(ProcessRunner $runner, string $path): array
    {
        $result = $runner->run([
            $this->ffprobeBinary,
            '-v', 'error',
            '-show_entries', 'format=format_name,duration:stream=codec_type,codec_name,width,height',
            '-of', 'json',
            $path,
        ], 15, 1024 * 1024);
        self::assertSame(0, $result->exitCode, 'FFprobe inspection failed.');
        $metadata = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($metadata);

        return $metadata;
    }

    /** @param array<string, mixed> $metadata @return array{int, int} */
    private function dimensions(array $metadata): array
    {
        $stream = $this->stream($metadata, 'video');

        return [(int) ($stream['width'] ?? 0), (int) ($stream['height'] ?? 0)];
    }

    /** @param array<string, mixed> $metadata */
    private function streamCodec(array $metadata, string $type): string
    {
        return (string) ($this->stream($metadata, $type)['codec_name'] ?? '');
    }

    /** @param array<string, mixed> $metadata */
    private function duration(array $metadata): float
    {
        return (float) ($metadata['format']['duration'] ?? 0.0);
    }

    /** @param array{int, int} $expected @param array{int, int} $actual */
    private function assertSameAspect(array $expected, array $actual, string $name): void
    {
        self::assertGreaterThan(0, $actual[0], $name);
        self::assertGreaterThan(0, $actual[1], $name);
        self::assertEqualsWithDelta($expected[0] / $expected[1], $actual[0] / $actual[1], 0.002, $name);
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
