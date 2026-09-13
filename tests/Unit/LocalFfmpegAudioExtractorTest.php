<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\PrivateStorage;
use App\Exceptions\SubtitleException;
use App\Media\LocalFfmpegAudioExtractor;
use App\Media\ProjectSource;
use App\Media\StoredObject;
use App\Process\ProcessResult;
use App\Process\ProcessRunner;
use PHPUnit\Framework\TestCase;

final class LocalFfmpegAudioExtractorTest extends TestCase
{
    public function testExtractsBoundedMonoPcmWaveBytesAndCleansTemporaryFile(): void
    {
        $dir=sys_get_temp_dir().'/audio extract '.bin2hex(random_bytes(4));
        mkdir($dir);
        $source=$dir.'/source.mp4';
        file_put_contents($source,'source');
        $runner=new AudioRecordingRunner($dir);
        try {
            $wav=(new LocalFfmpegAudioExtractor(new AudioPathStorage($source),$runner,'ffmpeg',$dir,20,4096))
                ->extract(new ProjectSource(1,2,'local','source.mp4','video/mp4'),1.25,2.5);
            self::assertSame('RIFF',substr($wav,0,4));
            $output=$runner->command[array_key_last($runner->command)];
            self::assertSame(['ffmpeg','-nostdin','-hide_banner','-loglevel','error','-ss','1.250','-i',$source,'-t','2.500','-map','0:a:0','-vn','-ac','1','-ar','16000','-c:a','pcm_s16le','-f','wav',$output],$runner->command);
            self::assertFileDoesNotExist($output);
        } finally {
            @unlink($source);
            @rmdir($dir);
        }
    }

    public function testRejectsNoAudioWithoutLeakingPath(): void
    {
        $dir=sys_get_temp_dir().'/audio-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $source=$dir.'/secret.mp4';
        file_put_contents($source,'x');
        $runner=new AudioRecordingRunner($dir);
        $runner->exit=1;
        try {
            (new LocalFfmpegAudioExtractor(new AudioPathStorage($source),$runner,'ffmpeg',$dir,20,4096))
                ->extract(new ProjectSource(1,2,'local','x','video/mp4'),0,1);
            self::fail();
        } catch (SubtitleException $error) {
            self::assertSame('subtitle_audio_missing',$error->publicCode());
            self::assertStringNotContainsString($source,$error->getMessage());
            self::assertSame([],glob($dir.'/clipforge-audio-*.wav'));
        } finally {
            @unlink($source);
            @rmdir($dir);
        }
    }

    public function testRejectsTemporaryDirectoryUnderPublicHtml(): void
    {
        $publicHtml=dirname(__DIR__,2).'/public_html';
        $createdRoot=!is_dir($publicHtml);
        $dir=$publicHtml.'/.audio-test-'.bin2hex(random_bytes(4));
        self::assertTrue(@mkdir($dir,0777,true) || is_dir($dir));

        try {
            $this->expectException(\InvalidArgumentException::class);
            new LocalFfmpegAudioExtractor(
                new AudioPathStorage(__FILE__),
                new AudioRecordingRunner(sys_get_temp_dir()),
                'ffmpeg',
                $dir,
                20,
                4096
            );
        } finally {
            @rmdir($dir);
            if ($createdRoot) @rmdir($publicHtml);
        }
    }
}

final class AudioRecordingRunner extends ProcessRunner
{
    public array $command=[];
    public int $exit=0;
    public function __construct(string $dir) { parent::__construct(['ffmpeg'],$dir); }
    public function run(array $command,int $timeoutSeconds,int $outputLimitBytes): ProcessResult
    {
        $this->command=$command;
        if ($this->exit===0) file_put_contents($command[array_key_last($command)],"RIFF\x24\0\0\0WAVEfmt ".str_repeat("\0",40));
        return new ProcessResult($this->exit,'','private');
    }
}

final class AudioPathStorage implements PrivateStorage
{
    public function __construct(private string $path) {}
    public function absolutePath(string $objectKey): string { return $this->path; }
    public function putUploaded(string $temporaryPath,string $objectKey): StoredObject { throw new \LogicException(); }
    public function putStream(mixed $stream,string $objectKey,int $maxBytes): StoredObject { throw new \LogicException(); }
    public function delete(string $objectKey): void {}
}
