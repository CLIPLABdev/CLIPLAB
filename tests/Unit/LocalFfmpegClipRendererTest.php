<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\PrivateStorage;
use App\Exceptions\ClipRenderException;
use App\Media\LocalFfmpegClipRenderer;
use App\Media\ProjectSource;
use App\Media\Reframe\AspectRatio;
use App\Media\Reframe\ReframeKeyframe;
use App\Media\Reframe\ReframePlan;
use App\Media\RenderClipRequest;
use App\Media\Editor\EditorOptions;
use App\Media\Subtitles\SubtitleCue;
use App\Media\Subtitles\Transcript;
use App\Media\StoredObject;
use App\Process\ProcessExecutionException;
use App\Process\ProcessResult;
use App\Process\ProcessRunner;
use PHPUnit\Framework\TestCase;

final class LocalFfmpegClipRendererTest extends TestCase
{
    private string $temporaryDirectory;
    private string $sourcePath;
    private RenderPathStorage $storage;
    private RecordingRenderProcessRunner $runner;
    private ProjectSource $source;
    private LocalFfmpegClipRenderer $renderer;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'clipforge renderer-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->temporaryDirectory, 0700));
        $this->sourcePath = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'source-input.mp4';
        self::assertSame(6, file_put_contents($this->sourcePath, 'source'));
        $this->storage = new RenderPathStorage($this->sourcePath);
        $this->runner = new RecordingRenderProcessRunner();
        $this->runner->onRun = static function (array $command): ProcessResult {
            $outputPath = $command[count($command) - 1];
            $bytes = in_array('-frames:v', $command, true)
                ? self::validJpeg()
                : self::validMp4();
            file_put_contents($outputPath, $bytes);

            return new ProcessResult(0, '', '');
        };
        $this->source = new ProjectSource(11, 7, 'local', 'users/7/episode.mp4', 'video/mp4');
        $this->renderer = $this->newRenderer();
    }

    protected function tearDown(): void
    {
        if (!isset($this->temporaryDirectory) || !is_dir($this->temporaryDirectory)) {
            return;
        }
        foreach (scandir($this->temporaryDirectory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $this->temporaryDirectory . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($this->temporaryDirectory);
    }

    public function testBuildsFixedVideoAndThumbnailArgumentLists(): void
    {
        $artifacts = $this->renderer->render(new RenderClipRequest($this->source, 12.5, 23.0, str_repeat('b', 32)));

        try {
            self::assertCount(3, $this->runner->commands);
            self::assertSame([
                'ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error',
                '-ss', '12.500', '-i', $this->sourcePath, '-t', '23.000',
                '-map', '0:v:0', '-map', '0:a?',
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23',
                '-pix_fmt', 'yuv420p', '-movflags', '+faststart',
                '-c:a', 'aac', '-b:a', '128k', $artifacts->videoPath(),
            ], $this->runner->commands[0]);
            self::assertSame([
                'ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error',
                '-ss', '11.500', '-i', $artifacts->videoPath(),
                '-frames:v', '1', '-vf', 'scale=640:-2:force_original_aspect_ratio=decrease',
                '-q:v', '2', $artifacts->thumbnailPath(),
            ], $this->runner->commands[2]);
            self::assertSame([
                'ffprobe', '-v', 'error', '-print_format', 'json', '-show_entries',
                'format=duration:stream=codec_type,duration,avg_frame_rate', $artifacts->videoPath(),
            ], $this->runner->commands[1]);
            self::assertSame(['users/7/episode.mp4'], $this->storage->requestedKeys);
            self::assertSame('video/mp4', $artifacts->videoMimeType());
            self::assertSame('image/jpeg', $artifacts->thumbnailMimeType());
            self::assertSame(strlen(self::validMp4()), $artifacts->videoSizeBytes());
            self::assertSame(strlen(self::validJpeg()), $artifacts->thumbnailSizeBytes());
            self::assertSame([4096, 4096, 4096], $this->runner->outputLimits);
        } finally {
            $artifacts->cleanup();
        }
    }

    public function testUsesUnpredictablePrivateTemporaryNames(): void
    {
        $first = $this->renderer->render(new RenderClipRequest($this->source, 0.0, 1.0, str_repeat('c', 32)));
        $second = $this->renderer->render(new RenderClipRequest($this->source, 0.0, 1.0, str_repeat('c', 32)));

        try {
            self::assertNotSame($first->videoPath(), $second->videoPath());
            self::assertNotSame($first->thumbnailPath(), $second->thumbnailPath());
            foreach ([$first->videoPath(), $first->thumbnailPath(), $second->videoPath(), $second->thumbnailPath()] as $path) {
                self::assertSame(realpath($this->temporaryDirectory), realpath(dirname($path)));
            }
        } finally {
            $first->cleanup();
            $second->cleanup();
        }
    }

    public function testNeutralOriginalRenderPreservesLargeIngestedSourceGeometry(): void
    {
        $source=new ProjectSource(11,7,'local','users/7/episode.mp4','video/mp4',10000,2000);
        $artifacts=$this->renderer->render(new RenderClipRequest($source,0.0,2.0,str_repeat('c',32)));
        try {
            self::assertCount(3,$this->runner->commands);
            self::assertNotContains('-vf',$this->runner->commands[0]);
            self::assertNotContains('-filter_complex',$this->runner->commands[0]);
            self::assertSame('video/mp4',$artifacts->videoMimeType());
        } finally { $artifacts->cleanup(); }
    }

    public function testRequestedLogoFailsClosedWithoutResolution(): void
    {
        $source=new ProjectSource(11,7,'local','users/7/episode.mp4','video/mp4',1920,1080);
        foreach ([null,static fn(int $asset,int $project): ?string=>null] as $resolver) {
            $renderer=new LocalFfmpegClipRenderer($this->storage,$this->runner,'ffmpeg',$this->temporaryDirectory,30,4096,4096,4096,null,$resolver,'ffprobe');
            try {
                $renderer->render(new RenderClipRequest($source,0.0,1.0,str_repeat('c',32),null,EditorOptions::fromArray(['logo_asset_id'=>123])));
                self::fail('Unresolved logo rendered.');
            } catch (ClipRenderException $error) {
                self::assertSame('render_output_invalid',$error->publicCode());
                self::assertSame([],$this->runner->commands);
            }
        }
    }

    public function testAppliesReframeBeforeServerOwnedAssAndCleansTheDocument(): void
    {
        $source = new ProjectSource(11, 7, 'local', 'users/7/episode.mp4', 'video/mp4', 1920, 1080);
        $plan = ReframePlan::center(AspectRatio::fromString('4:5'));
        $options = EditorOptions::fromArray(['style' => 'minimal', 'title' => 'Título seguro','brightness'=>25]);
        $transcript = new Transcript('pt-BR', [new SubtitleCue(0, 1500, 'Olá mundo')], 2000);
        $artifacts = $this->renderer->render(new RenderClipRequest($source, 0.0, 2.0, str_repeat('9', 32), $plan, $options, $transcript));
        try {
            $command = $this->runner->commands[0]; $index = array_search('-vf', $command, true); self::assertIsInt($index);
            $filter = $command[$index + 1]; self::assertMatchesRegularExpression('/crop=.*scale=.*eq=brightness=0\.25.*subtitles=filename=/', $filter);
            self::assertStringNotContainsString(';', $filter);
            $assFiles = glob($this->temporaryDirectory . DIRECTORY_SEPARATOR . '*.ass') ?: [];
            self::assertSame([], $assFiles);
        } finally { $artifacts->cleanup(); }
    }

    public function testVideoEffectsApplyWithoutOverlaysAndBeforeAss(): void
    {
        $source=new ProjectSource(11,7,'local','users/7/episode.mp4','video/mp4',320,180);
        foreach (['','Título'] as $title) {
            $options=EditorOptions::fromArray(['brightness'=>25,'title'=>$title]);
            $artifacts=$this->renderer->render(new RenderClipRequest($source,0.0,2.0,str_repeat('8',32),null,$options));
            try {
                $command=$this->runner->commands[count($this->runner->commands)-3];
                $index=array_search('-vf',$command,true);
                self::assertIsInt($index);
                self::assertStringStartsWith('eq=brightness=0.25',$command[$index+1]);
                if ($title!=='') self::assertStringContainsString(',subtitles=',$command[$index+1]);
            } finally { $artifacts->cleanup(); }
        }
    }

    /** @dataProvider nonOriginalPlans */
    public function testAddsExactlyOneServerOwnedVideoFilterBeforeCodecsForEachReframeMode(
        ReframePlan $plan,
        string $expectedFilterFragment
    ): void {
        $source = new ProjectSource(11, 7, 'local', 'users/7/episode.mp4', 'video/mp4', 1920, 1080);
        $artifacts = $this->renderer->render(new RenderClipRequest($source, 0.0, 2.0, str_repeat('2', 32), $plan));

        try {
            $videoCommand = $this->runner->commands[0];
            $filterIndexes = array_keys($videoCommand, '-vf', true);
            self::assertCount(1, $filterIndexes);
            $filterIndex = $filterIndexes[0];
            $codecIndex = array_search('-c:v', $videoCommand, true);
            self::assertIsInt($codecIndex);
            self::assertLessThan($codecIndex, $filterIndex);
            self::assertStringContainsString($expectedFilterFragment, $videoCommand[$filterIndex + 1]);
            self::assertSame($artifacts->videoPath(), $this->runner->commands[2][8]);
        } finally {
            $artifacts->cleanup();
        }
    }

    /** @return iterable<string, array{ReframePlan, string}> */
    public function nonOriginalPlans(): iterable
    {
        yield 'center' => [
            ReframePlan::center(AspectRatio::fromString('9:16')),
            'crop=606:1080:657.000000:0.000000,scale=1080:1920:flags=lanczos',
        ];
        yield 'manual' => [
            ReframePlan::manual(AspectRatio::fromString('1:1'), new ReframeKeyframe(0, 0.0, 1.0, 'manual')),
            'crop=1080:1080:0.000000:0.000000,scale=1080:1080:flags=lanczos',
        ];
        yield 'automatic' => [
            ReframePlan::automatic(AspectRatio::fromString('4:5'), ReframePlan::DETECTOR_VERSION, [
                new ReframeKeyframe(0, 0.0, 0.5, 'detected'),
                new ReframeKeyframe(2000, 1.0, 0.5, 'detected'),
            ]),
            'scale=1080:1350:flags=lanczos',
        ];
    }

    /** @dataProvider incompleteGeometry */
    public function testRejectsNonOriginalReframeWhenSourceGeometryIsMissingOrIncomplete(?int $width, ?int $height): void
    {
        $source = new ProjectSource(11, 7, 'local', 'users/7/episode.mp4', 'video/mp4', $width, $height);
        $plan = ReframePlan::center(AspectRatio::fromString('9:16'));

        try {
            $this->renderer->render(new RenderClipRequest($source, 0.0, 1.0, str_repeat('3', 32), $plan));
            self::fail('A reframe without complete geometry returned artifacts.');
        } catch (ClipRenderException $exception) {
            self::assertSame('render_output_invalid', $exception->publicCode());
            self::assertSame([], $this->runner->commands);
        }
    }

    /** @return iterable<string, array{?int, ?int}> */
    public function incompleteGeometry(): iterable
    {
        yield 'missing width and height' => [null, null];
        yield 'missing width' => [null, 1080];
        yield 'missing height' => [1920, null];
    }

    public function testSharesOneDeadlineBetweenBothProcesses(): void
    {
        $this->renderer = $this->newRenderer(2);
        $this->runner->onRun = static function (array $command, int $call): ProcessResult {
            if ($call === 1) {
                usleep(1100000);
            }
            $outputPath = $command[count($command) - 1];
            file_put_contents($outputPath, in_array('-frames:v', $command, true) ? self::validJpeg() : self::validMp4());

            return new ProcessResult(0, '', '');
        };

        $artifacts = $this->renderer->render(new RenderClipRequest($this->source, 0.0, 1.0, str_repeat('d', 32)));

        try {
            self::assertSame(2, $this->runner->timeouts[0]);
            self::assertSame(1, $this->runner->timeouts[1]);
            self::assertSame(1, $this->runner->timeouts[2]);
        } finally {
            $artifacts->cleanup();
        }
    }

    /** @dataProvider processFailures */
    public function testMapsProcessFailuresWithoutLeakingDetails(string $processCode, string $renderCode): void
    {
        $this->runner->throwCode = $processCode;
        $this->runner->onRun = null;

        try {
            $this->renderer->render(new RenderClipRequest($this->source, 0.0, 1.0, str_repeat('e', 32)));
            self::fail('A process failure returned artifacts.');
        } catch (ClipRenderException $exception) {
            self::assertSame($renderCode, $exception->publicCode());
            self::assertStringNotContainsString($this->sourcePath, $exception->getMessage());
            self::assertStringNotContainsString('stderr', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public function processFailures(): iterable
    {
        yield 'unavailable' => ['process_unavailable', 'render_unavailable'];
        yield 'timeout' => ['process_timeout', 'render_timeout'];
        yield 'output limit' => ['process_output_limit', 'render_failed'];
        yield 'generic process failure' => ['process_failed', 'render_failed'];
    }

    public function testMapsANonzeroExitWithoutLeakingStderr(): void
    {
        $this->runner->onRun = static fn (): ProcessResult => new ProcessResult(9, '', 'private stderr detail');

        try {
            $this->renderer->render(new RenderClipRequest($this->source, 0.0, 1.0, str_repeat('f', 32)));
            self::fail('A nonzero FFmpeg exit returned artifacts.');
        } catch (ClipRenderException $exception) {
            self::assertSame('render_failed', $exception->publicCode());
            self::assertStringNotContainsString('private stderr detail', $exception->getMessage());
        }
    }

    public function testRejectsOversizedSubstitutedProcessOutput(): void
    {
        $this->runner->onRun = static fn (): ProcessResult => new ProcessResult(0, str_repeat('x', 4097), '');

        $this->expectRenderFailure('render_failed');
    }

    public function testRejectsMissingOutputAndCleansAllocatedPaths(): void
    {
        $this->runner->onRun = static fn (): ProcessResult => new ProcessResult(0, '', '');

        $this->expectRenderFailure('render_output_invalid');
        self::assertDirectoryContainsOnlySource($this->temporaryDirectory);
    }

    public function testRejectsAnOversizedArtifactAndCleansIt(): void
    {
        $this->renderer = $this->newRenderer(5, 16, 1024);
        $this->runner->onRun = static function (array $command): ProcessResult {
            file_put_contents($command[count($command) - 1], self::validMp4());

            return new ProcessResult(0, '', '');
        };

        $this->expectRenderFailure('render_output_invalid');
        self::assertDirectoryContainsOnlySource($this->temporaryDirectory);
    }

    public function testRejectsInvalidMimeAndCleansIt(): void
    {
        $this->runner->onRun = static function (array $command): ProcessResult {
            file_put_contents($command[count($command) - 1], 'plain text output');

            return new ProcessResult(0, '', '');
        };

        $this->expectRenderFailure('render_output_invalid');
        self::assertDirectoryContainsOnlySource($this->temporaryDirectory);
    }

    public function testCleansVideoWhenThumbnailProcessFails(): void
    {
        $videoPath = null;
        $this->runner->onRun = static function (array $command, int $call) use (&$videoPath): ProcessResult {
            $outputPath = $command[count($command) - 1];
            if ($call === 1) {
                $videoPath = $outputPath;
                file_put_contents($outputPath, self::validMp4());

                return new ProcessResult(0, '', '');
            }

            return new ProcessResult(1, '', 'thumbnail private stderr');
        };

        $this->expectRenderFailure('render_failed');
        self::assertIsString($videoPath);
        self::assertFileDoesNotExist($videoPath);
        self::assertDirectoryContainsOnlySource($this->temporaryDirectory);
    }

    public function testRejectsShortCompletedVideoBeforeReturningAnyArtifact(): void
    {
        $this->runner->probeResult = new ProcessResult(0, '{"format":{"duration":"45.011634"},"streams":[{"codec_type":"video","duration":"44.978267","avg_frame_rate":"30000/1001"}]}', '');
        try {
            $artifacts=$this->renderer->render(new RenderClipRequest($this->source,0.0,46.0,str_repeat('a',32)));
            $artifacts->cleanup();
            self::fail('EOF-short MP4 must not be returned for publication.');
        } catch (ClipRenderException $error) {
            self::assertSame('render_output_invalid',$error->publicCode());
        }
        self::assertCount(2,$this->runner->commands);
        self::assertDirectoryContainsOnlySource($this->temporaryDirectory);
    }

    public function testAcceptsFrameQuantizationAtThePreciseEndpoint(): void
    {
        $this->runner->probeResult = new ProcessResult(0, '{"format":{"duration":"45.011634"},"streams":[{"codec_type":"video","duration":"44.978267","avg_frame_rate":"30000/1001"}]}', '');
        $artifacts=$this->renderer->render(new RenderClipRequest($this->source,0.0,45.011,str_repeat('a',32)));
        try { self::assertFileExists($artifacts->videoPath()); self::assertCount(3,$this->runner->commands); }
        finally { $artifacts->cleanup(); }
    }

    public function testMissingProbeConfigurationFailsClosed(): void
    {
        $renderer=new LocalFfmpegClipRenderer($this->storage,$this->runner,'ffmpeg',$this->temporaryDirectory,5,4096,1024,1024);
        try {
            $artifacts=$renderer->render(new RenderClipRequest($this->source,0.0,1.0,str_repeat('a',32)));
            $artifacts->cleanup();
            self::fail('Missing FFprobe cannot bypass duration validation.');
        } catch (ClipRenderException $error) { self::assertSame('render_unavailable',$error->publicCode()); }
        self::assertSame([],$this->runner->commands);
        self::assertDirectoryContainsOnlySource($this->temporaryDirectory);
    }

    /** @dataProvider invalidProbeResults */
    public function testProbeFailureCleansVideoAndAss(string $stdout,int $exitCode,string $expected): void
    {
        $this->runner->probeResult=new ProcessResult($exitCode,$stdout,'private stderr');
        try {
            $artifacts=$this->renderer->render(new RenderClipRequest(new ProjectSource(11,7,'local','users/7/episode.mp4','video/mp4',1920,1080),0.0,1.0,str_repeat('a',32),null,EditorOptions::fromArray(['title'=>'Safe'])));
            $artifacts->cleanup();
            self::fail('Unvalidated output was returned.');
        } catch (ClipRenderException $error) {
            self::assertSame($expected,$error->publicCode());
            self::assertStringNotContainsString('private',$error->getMessage());
        }
        self::assertDirectoryContainsOnlySource($this->temporaryDirectory);
    }

    public function invalidProbeResults(): iterable
    {
        yield 'malformed'=>['not-json',0,'render_output_invalid'];
        yield 'nonfinite'=>['{"format":{"duration":"NaN"},"streams":[]}',0,'render_output_invalid'];
        yield 'oversized'=>[str_repeat('x',4097),0,'render_failed'];
        yield 'failed'=>['',1,'render_failed'];
    }

    public function testProbeTimeoutCannotReturnArtifacts(): void
    {
        $this->runner->probeThrowCode='process_timeout';
        $this->expectRenderFailure('render_timeout');
        self::assertDirectoryContainsOnlySource($this->temporaryDirectory);
    }

    public function testProbeCannotExtendTheSharedDeadlineEvenIfRunnerReturnsSuccess(): void
    {
        $this->renderer=$this->newRenderer(1);
        $this->runner->probeDelayMicroseconds=1100000;
        $this->expectRenderFailure('render_timeout');
        self::assertCount(2,$this->runner->commands);
        self::assertDirectoryContainsOnlySource($this->temporaryDirectory);
    }

    private function newRenderer(int $timeout = 5, int $videoMaxBytes = 1024, int $thumbnailMaxBytes = 1024): LocalFfmpegClipRenderer
    {
        return new LocalFfmpegClipRenderer(
            $this->storage,
            $this->runner,
            'ffmpeg',
            $this->temporaryDirectory,
            $timeout,
            4096,
            $videoMaxBytes,
            $thumbnailMaxBytes,
            null,
            null,
            'ffprobe'
        );
    }

    private function expectRenderFailure(string $publicCode): void
    {
        try {
            $this->renderer->render(new RenderClipRequest($this->source, 0.0, 1.0, str_repeat('1', 32)));
            self::fail('Invalid renderer output returned artifacts.');
        } catch (ClipRenderException $exception) {
            self::assertSame($publicCode, $exception->publicCode());
            self::assertStringNotContainsString($this->temporaryDirectory, $exception->getMessage());
        }
    }

    private static function assertDirectoryContainsOnlySource(string $directory): void
    {
        $entries = array_values(array_filter(scandir($directory) ?: [], static fn (string $entry): bool => $entry !== '.' && $entry !== '..'));
        self::assertSame(['source-input.mp4'], $entries);
    }

    private static function validMp4(): string
    {
        return (string) hex2bin('00000018667479706d703432000000006d70343269736f6d');
    }

    private static function validJpeg(): string
    {
        return (string) hex2bin('ffd8ffe000104a46494600010100000100010000ffd9');
    }
}

final class RecordingRenderProcessRunner extends ProcessRunner
{
    /** @var list<list<string>> */
    public array $commands = [];
    /** @var list<int> */
    public array $timeouts = [];
    /** @var list<int> */
    public array $outputLimits = [];
    /** @var null|\Closure(list<string>, int): ProcessResult */
    public ?\Closure $onRun = null;
    public ?string $throwCode = null;
    public ?string $probeThrowCode = null;
    public ?ProcessResult $probeResult = null;
    public int $probeDelayMicroseconds = 0;

    public function __construct()
    {
        parent::__construct(['ffmpeg','ffprobe'], sys_get_temp_dir());
    }

    public function run(array $command, int $timeoutSeconds, int $outputLimitBytes): ProcessResult
    {
        $this->commands[] = $command;
        $this->timeouts[] = $timeoutSeconds;
        $this->outputLimits[] = $outputLimitBytes;
        if ($this->throwCode !== null) {
            throw new ProcessExecutionException($this->throwCode);
        }
        if ($command[0]==='ffprobe') {
            if ($this->probeDelayMicroseconds>0) usleep($this->probeDelayMicroseconds);
            if ($this->probeThrowCode!==null) throw new ProcessExecutionException($this->probeThrowCode);
            if ($this->probeResult!==null) return $this->probeResult;
            $video=$this->commands[count($this->commands)-2];
            $duration=$video[array_search('-t',$video,true)+1];
            return new ProcessResult(0,json_encode(['format'=>['duration'=>$duration],'streams'=>[['codec_type'=>'video','duration'=>$duration,'avg_frame_rate'=>'30/1']]],JSON_THROW_ON_ERROR),'');
        }
        if ($this->onRun === null) {
            throw new \RuntimeException('No fake FFmpeg behavior was configured.');
        }

        return ($this->onRun)($command, count($this->commands));
    }
}

final class RenderPathStorage implements PrivateStorage
{
    /** @var list<string> */
    public array $requestedKeys = [];

    public function __construct(private string $path)
    {
    }

    public function putUploaded(string $temporaryPath, string $objectKey): StoredObject
    {
        throw new \LogicException('Not used by this test.');
    }

    public function putStream(mixed $stream, string $objectKey, int $maxBytes): StoredObject
    {
        throw new \LogicException('Not used by this test.');
    }

    public function absolutePath(string $objectKey): string
    {
        $this->requestedKeys[] = $objectKey;

        return $this->path;
    }

    public function delete(string $objectKey): void
    {
        throw new \LogicException('Not used by this test.');
    }
}
