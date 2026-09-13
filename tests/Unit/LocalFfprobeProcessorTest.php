<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\PrivateStorage;
use App\Exceptions\MediaValidationException;
use App\Media\LocalFfprobeProcessor;
use App\Media\ProjectSource;
use App\Media\StoredObject;
use App\Process\ProcessExecutionException;
use App\Process\ProcessResult;
use App\Process\ProcessRunner;
use PHPUnit\Framework\TestCase;

final class LocalFfprobeProcessorTest extends TestCase
{
    private FakeFfprobeRunner $runner;
    private FakePrivateStorage $storage;
    private ProjectSource $source;
    private LocalFfprobeProcessor $processor;

    protected function setUp(): void
    {
        $this->runner = new FakeFfprobeRunner();
        $this->storage = new FakePrivateStorage('C:/private-media/users/7/episode.mp4');
        $this->source = new ProjectSource(11, 7, 'local', 'users/7/episode.mp4', 'video/mp4');
        $this->processor = new LocalFfprobeProcessor($this->storage, $this->runner, 'ffprobe', 5, 4096);
    }

    public function testBuildsFixedFfprobeArgumentsAndParsesMetadata(): void
    {
        $this->runner->result = new ProcessResult(0, $this->validJson(), '');

        $metadata = $this->processor->inspect($this->source);

        self::assertSame(1920, $metadata->width());
        self::assertSame(1080, $metadata->height());
        self::assertSame(126, $metadata->durationSeconds());
        self::assertSame('h264', $metadata->videoCodec());
        self::assertTrue($metadata->hasAudio());
        self::assertSame('aac', $metadata->audioCodec());
        self::assertSame([
            'ffprobe', '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams',
            'C:/private-media/users/7/episode.mp4',
        ], $this->runner->lastCommand);
    }

    public function testKeepsBusinessCeilingAndReturnsConservativeMillisecondEof(): void
    {
        $payload = json_decode($this->validJson(), true, 512, JSON_THROW_ON_ERROR);
        $payload['format']['duration'] = '45.011634';
        $this->runner->result = new ProcessResult(0, json_encode($payload, JSON_THROW_ON_ERROR), '');
        $metadata = $this->processor->inspect($this->source);
        self::assertSame(46, $metadata->durationSeconds());
        self::assertTrue(method_exists($metadata, 'durationMilliseconds'), 'Precise EOF is missing.');
        self::assertSame(45011, $metadata->durationMilliseconds());
    }

    public function testIngestStillRejectsSourceShorterThanOneSecond(): void
    {
        $payload = json_decode($this->validJson(), true, 512, JSON_THROW_ON_ERROR);
        $payload['format']['duration'] = '0.967';
        $this->runner->result = new ProcessResult(0, json_encode($payload, JSON_THROW_ON_ERROR), '');
        $this->expectException(MediaValidationException::class);
        $this->processor->inspect($this->source);
    }

    public function testRejectsInvalidJsonWithoutLeakingProcessOutput(): void
    {
        $this->runner->result = new ProcessResult(0, '{invalid', 'secret stderr');

        $this->expectException(MediaValidationException::class);
        $this->processor->inspect($this->source);
    }

    public function testRejectsSourcesWithoutAVideoStream(): void
    {
        $this->runner->result = new ProcessResult(0, json_encode(['format' => ['duration' => '12'], 'streams' => []], JSON_THROW_ON_ERROR), '');

        $this->expectException(MediaValidationException::class);
        $this->processor->inspect($this->source);
    }

    public function testRejectsNegativeAndNonFiniteDurations(): void
    {
        foreach (['-1', 'NaN'] as $duration) {
            $this->runner->result = new ProcessResult(0, json_encode([
                'format' => ['duration' => $duration],
                'streams' => [['codec_type' => 'video', 'width' => 1920, 'height' => 1080, 'codec_name' => 'h264']],
            ], JSON_THROW_ON_ERROR), '');
            try {
                $this->processor->inspect($this->source);
                self::fail('Invalid duration was accepted: ' . $duration);
            } catch (MediaValidationException $exception) {
                self::assertSame('invalid_media_metadata', $exception->publicCode());
            }
        }
    }

    public function testRejectsDimensionsAboveTheConfiguredBound(): void
    {
        $this->runner->result = new ProcessResult(0, json_encode([
            'format' => ['duration' => '12'],
            'streams' => [['codec_type' => 'video', 'width' => 16385, 'height' => 1080, 'codec_name' => 'h264']],
        ], JSON_THROW_ON_ERROR), '');

        $this->expectException(MediaValidationException::class);
        $this->processor->inspect($this->source);
    }

    public function testRejectsAStoragePathThatIsNotConfinedByTheStorageAdapter(): void
    {
        $this->storage->exception = MediaValidationException::withCode('invalid_object_key');

        $this->expectException(MediaValidationException::class);
        $this->processor->inspect(new ProjectSource(11, 7, 'local', '../outside.mp4', 'video/mp4'));
    }

    public function testTreatsANonZeroFfprobeExitAsAStableProcessFailure(): void
    {
        $this->runner->result = new ProcessResult(1, '', 'private failure details');

        try {
            $this->processor->inspect($this->source);
            self::fail('A failed ffprobe execution returned metadata.');
        } catch (ProcessExecutionException $exception) {
            self::assertSame('process_failed', $exception->publicCode());
            self::assertStringNotContainsString('private failure details', $exception->getMessage());
        }
    }

    public function testRejectsOversizedRunnerOutputEvenWhenTheRunnerIsSubstituted(): void
    {
        $this->runner->result = new ProcessResult(0, str_repeat('x', 4097), '');

        try {
            $this->processor->inspect($this->source);
            self::fail('Oversized output was parsed.');
        } catch (ProcessExecutionException $exception) {
            self::assertSame('process_output_limit', $exception->publicCode());
        }
    }

    public function testWindowsRequiresTheProductionBinaryToBeDirectFfprobe(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            self::assertTrue(true);

            return;
        }

        $this->expectException(\InvalidArgumentException::class);
        new LocalFfprobeProcessor($this->storage, $this->runner, 'php.exe', 5, 4096);
    }

    private function validJson(): string
    {
        return json_encode([
            'format' => ['duration' => '125.5'],
            'streams' => [
                ['codec_type' => 'video', 'width' => 1920, 'height' => 1080, 'codec_name' => 'h264'],
                ['codec_type' => 'audio', 'codec_name' => 'aac'],
            ],
        ], JSON_THROW_ON_ERROR);
    }
}

final class FakeFfprobeRunner extends ProcessRunner
{
    public ?ProcessResult $result = null;
    /** @var list<string> */
    public array $lastCommand = [];

    public function __construct()
    {
        parent::__construct(['ffprobe'], sys_get_temp_dir());
    }

    public function run(array $command, int $timeoutSeconds, int $outputLimitBytes): ProcessResult
    {
        $this->lastCommand = $command;
        if ($this->result === null) {
            throw new \RuntimeException('No fake ffprobe result was configured.');
        }
        return $this->result;
    }
}

final class FakePrivateStorage implements PrivateStorage
{
    public ?\Throwable $exception = null;

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
        if ($this->exception !== null) {
            throw $this->exception;
        }
        return $this->path;
    }

    public function delete(string $objectKey): void
    {
    }
}
