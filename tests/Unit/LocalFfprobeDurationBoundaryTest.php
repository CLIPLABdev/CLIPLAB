<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\PrivateStorage;
use App\Exceptions\MediaValidationException;
use App\Media\LocalFfprobeProcessor;
use App\Media\ProjectSource;
use App\Media\StoredObject;
use App\Process\ProcessResult;
use App\Process\ProcessRunner;
use PHPUnit\Framework\TestCase;

final class LocalFfprobeDurationBoundaryTest extends TestCase
{
    public function testRejectsRawDurationBelowOneSecondBeforeRounding(): void
    {
        $runner = new DurationBoundaryRunner();
        $runner->result = new ProcessResult(0, json_encode([
            'format' => ['duration' => '0.1'],
            'streams' => [['codec_type' => 'video', 'width' => 1920, 'height' => 1080, 'codec_name' => 'h264']],
        ], JSON_THROW_ON_ERROR), '');
        $processor = new LocalFfprobeProcessor(new DurationBoundaryStorage(), $runner, 'ffprobe', 5, 4096);

        $this->expectException(MediaValidationException::class);
        $processor->inspect(new ProjectSource(1, 2, 'local', 'imports/video.mp4', 'video/mp4'));
    }
}

final class DurationBoundaryRunner extends ProcessRunner
{
    public ?ProcessResult $result = null;

    public function __construct()
    {
        parent::__construct(['ffprobe'], sys_get_temp_dir());
    }

    public function run(array $command, int $timeoutSeconds, int $outputLimitBytes): ProcessResult
    {
        return $this->result ?? throw new \LogicException('No result configured.');
    }
}

final class DurationBoundaryStorage implements PrivateStorage
{
    public function putUploaded(string $temporaryPath, string $objectKey): StoredObject { throw new \LogicException('Not used.'); }
    public function putStream(mixed $stream, string $objectKey, int $maxBytes): StoredObject { throw new \LogicException('Not used.'); }
    public function absolutePath(string $objectKey): string { return 'C:/private/' . $objectKey; }
    public function delete(string $objectKey): void { }
}
