<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\MediaValidationException;
use App\Media\DirectUrlValidator;
use App\Media\ResolvedYoutubeMedia;
use App\Media\ValidatedYoutubeUrl;
use App\Media\YtDlpYoutubeResolver;
use App\Process\ProcessResult;
use App\Process\ProcessExecutionException;
use App\Process\ProcessRunner;
use PHPUnit\Framework\TestCase;

final class YtDlpYoutubeResolverTest extends TestCase
{
    public function testResolvesOnePublicProgressiveMp4ThroughABoundedMetadataOnlyCommand(): void
    {
        $runner = new RecordingYoutubeResolverRunner();
        $runner->result = new ProcessResult(0, json_encode($this->metadata(), JSON_THROW_ON_ERROR), '');
        $resolver = new YtDlpYoutubeResolver(
            $runner,
            new DirectUrlValidator(static fn (): array => ['8.8.8.8']),
            'yt-dlp',
            45,
            32768,
            'deno:/usr/local/bin/deno'
        );

        $media = $resolver->resolve(new ValidatedYoutubeUrl(
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'www.youtube.com',
            'dQw4w9WgXcQ'
        ));

        self::assertInstanceOf(ResolvedYoutubeMedia::class, $media);
        self::assertSame('rr1---sn.example.googlevideo.com', $media->url()->host());
        self::assertSame('mp4', $media->extension());
        self::assertSame('video/mp4', $media->mimeType());
        self::assertSame(45, $runner->timeoutSeconds);
        self::assertSame(32768, $runner->outputLimitBytes);
        self::assertSame([
            'yt-dlp', '--ignore-config', '--no-plugin-dirs', '--no-cache-dir', '--no-js-runtimes', '--no-remote-components',
            '--no-playlist', '--skip-download',
            '--print', '%(.{_type,extractor_key,id,availability,is_live,live_status,age_limit,has_drm,duration,ext,protocol,vcodec,acodec,url,filesize,filesize_approx,language,format_id,requested_formats,formats})j', '--no-warnings', '--socket-timeout', '20', '--retries', '1',
            '--fragment-retries', '1', '--extractor-retries', '1', '--use-extractors', 'youtube',
            '--format', 'best[ext=mp4][vcodec!=none][acodec!=none]/bestvideo[ext=mp4][vcodec^=avc1]+bestaudio[ext=m4a]',
            '--js-runtimes', 'deno:/usr/local/bin/deno', '--force-ipv4', '--',
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ], $runner->command);
    }

    public function testPassesReadableCookiesFileAndIgnoresMissingOne(): void
    {
        $cookies = tempnam(sys_get_temp_dir(), 'yt-cookies-');
        file_put_contents($cookies, "# Netscape HTTP Cookie File\n");
        try {
            foreach ([$cookies => true, $cookies . '-missing' => false] as $path => $expected) {
                $runner = new RecordingYoutubeResolverRunner();
                $runner->result = new ProcessResult(0, json_encode($this->metadata(), JSON_THROW_ON_ERROR), '');
                $resolver = new YtDlpYoutubeResolver(
                    $runner, new DirectUrlValidator(static fn (): array => ['8.8.8.8']),
                    'yt-dlp', 45, 32768, null, true, null, $path
                );
                $resolver->resolve(new ValidatedYoutubeUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'www.youtube.com', 'dQw4w9WgXcQ'));
                $index = array_search('--cookies', $runner->command, true);
                self::assertSame($expected, $index !== false);
                if ($expected) {
                    self::assertSame($path, $runner->command[$index + 1]);
                    self::assertLessThan(array_search('--', $runner->command, true), $index);
                }
            }
        } finally {
            @unlink($cookies);
        }
    }

    public function testCanAllowTheRemoteChallengeSolverComponent(): void
    {
        $runner = new RecordingYoutubeResolverRunner();
        $runner->result = new ProcessResult(0, json_encode($this->metadata(), JSON_THROW_ON_ERROR), '');
        $resolver = new YtDlpYoutubeResolver(
            $runner, new DirectUrlValidator(static fn (): array => ['8.8.8.8']),
            'yt-dlp', 45, 32768, 'node:/usr/local/bin/node', true, null, null, 'ejs:github'
        );
        $resolver->resolve(new ValidatedYoutubeUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'www.youtube.com', 'dQw4w9WgXcQ'));
        self::assertNotContains('--no-remote-components', $runner->command);
        $index = array_search('--remote-components', $runner->command, true);
        self::assertIsInt($index);
        self::assertSame('ejs:github', $runner->command[$index + 1]);
    }

    public function testKeepsTheSourceLinkAndChosenFormatForTheDownloader(): void
    {
        $runner = new RecordingYoutubeResolverRunner();
        $runner->result = new ProcessResult(0, json_encode(['format_id' => '18'] + $this->metadata(), JSON_THROW_ON_ERROR), '');
        $resolver = new YtDlpYoutubeResolver($runner, new DirectUrlValidator(static fn (): array => ['8.8.8.8']));

        $media = $resolver->resolve(new ValidatedYoutubeUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'www.youtube.com', 'dQw4w9WgXcQ'));

        self::assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $media->sourceUrl());
        self::assertSame('18', $media->formatSelector());
    }

    public function testCanOptOutOfIpv4OnIpv6OnlyHosts(): void
    {
        $runner = new RecordingYoutubeResolverRunner();
        $runner->result = new ProcessResult(0, json_encode($this->metadata(), JSON_THROW_ON_ERROR), '');
        $resolver = new YtDlpYoutubeResolver($runner, new DirectUrlValidator(static fn (): array => ['8.8.8.8']), 'yt-dlp', 45, 32768, null, false);
        $resolver->resolve(new ValidatedYoutubeUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'www.youtube.com', 'dQw4w9WgXcQ'));
        self::assertNotContains('--force-ipv4', $runner->command);
        self::assertSame(45, $runner->timeoutSeconds);
    }

    public function testResolvesExactlyOneHttpsMp4VideoAndM4aAudioPair(): void
    {
        $runner = new RecordingYoutubeResolverRunner();
        $metadata = $this->metadata();
        unset(
            $metadata['_type'],
            $metadata['has_drm'],
            $metadata['url'],
            $metadata['ext'],
            $metadata['protocol'],
            $metadata['vcodec'],
            $metadata['acodec']
        );
        $metadata['requested_formats'] = [
            [
                'ext' => 'mp4', 'protocol' => 'https', 'vcodec' => 'avc1.4d400c', 'acodec' => 'none',
                'url' => 'https://v.example.googlevideo.com/videoplayback?id=video',
            ],
            [
                'ext' => 'm4a', 'protocol' => 'https', 'vcodec' => 'none', 'acodec' => 'mp4a.40.2',
                'url' => 'https://a.example.googlevideo.com/videoplayback?id=audio',
            ],
        ];
        $runner->result = new ProcessResult(0, json_encode($metadata, JSON_THROW_ON_ERROR), '');
        $resolver = new YtDlpYoutubeResolver($runner, new DirectUrlValidator(static fn (): array => ['8.8.8.8']));

        $media = $resolver->resolve(new ValidatedYoutubeUrl(
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'www.youtube.com', 'dQw4w9WgXcQ'
        ));

        self::assertTrue($media->isAdaptive());
        self::assertSame(['video', 'audio'], array_map(static fn ($track): string => $track->kind(), $media->tracks()));
        self::assertSame(['mp4', 'm4a'], array_map(static fn ($track): string => $track->extension(), $media->tracks()));
    }

    public function testRejectsDrmFlagOnEitherAdaptiveTrack(): void
    {
        foreach ([0, 1] as $drmIndex) {
            $runner = new RecordingYoutubeResolverRunner();
            $metadata = $this->metadata();
            unset($metadata['url'], $metadata['ext'], $metadata['protocol'], $metadata['vcodec'], $metadata['acodec']);
            $metadata['requested_formats'] = [
                ['ext' => 'mp4', 'protocol' => 'https', 'vcodec' => 'avc1.4d400c', 'acodec' => 'none', 'url' => 'https://v.example.googlevideo.com/videoplayback'],
                ['ext' => 'm4a', 'protocol' => 'https', 'vcodec' => 'none', 'acodec' => 'mp4a.40.2', 'url' => 'https://a.example.googlevideo.com/videoplayback'],
            ];
            $metadata['requested_formats'][$drmIndex]['has_drm'] = true;
            $runner->result = new ProcessResult(0, json_encode($metadata, JSON_THROW_ON_ERROR), '');
            $resolver = new YtDlpYoutubeResolver($runner, new DirectUrlValidator(static fn (): array => ['8.8.8.8']));

            try {
                $resolver->resolve(new ValidatedYoutubeUrl(
                    'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'www.youtube.com', 'dQw4w9WgXcQ'
                ));
                self::fail('An adaptive DRM track was accepted.');
            } catch (MediaValidationException $exception) {
                self::assertSame('youtube_response_invalid', $exception->publicCode());
            }
        }
    }

    /** @dataProvider unsafeMetadata */
    public function testRejectsMetadataThatIsNotTheRequestedPublicProgressiveGooglevideoMp4(array $changes): void
    {
        $runner = new RecordingYoutubeResolverRunner();
        $runner->result = new ProcessResult(0, json_encode(array_replace($this->metadata(), $changes), JSON_THROW_ON_ERROR), '');
        $resolver = new YtDlpYoutubeResolver($runner, new DirectUrlValidator(static fn (): array => ['8.8.8.8']));

        try {
            $resolver->resolve(new ValidatedYoutubeUrl(
                'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'www.youtube.com',
                'dQw4w9WgXcQ'
            ));
            self::fail('Unsafe yt-dlp metadata was accepted.');
        } catch (MediaValidationException $exception) {
            self::assertSame('youtube_response_invalid', $exception->publicCode());
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unsafeMetadata(): iterable
    {
        yield 'wrong video id' => [['id' => 'aaaaaaaaaaa']];
        yield 'private' => [['availability' => 'private']];
        yield 'live' => [['is_live' => true, 'live_status' => 'is_live']];
        yield 'age restricted' => [['age_limit' => 18]];
        yield 'unknown nonnumeric age' => [['age_limit' => 'unknown']];
        yield 'drm' => [['has_drm' => true]];
        yield 'unknown drm status' => [['has_drm' => null]];
        yield 'unknown live status' => [['live_status' => null]];
        yield 'video only' => [['acodec' => 'none']];
        yield 'webm' => [['ext' => 'webm']];
        yield 'non https protocol' => [['protocol' => 'http']];
        yield 'untrusted host' => [['url' => 'https://cdn.example.test/videoplayback']];
        yield 'googlevideo custom port' => [['url' => 'https://rr1---sn.example.googlevideo.com:444/videoplayback']];
    }

    public function testMapsExtractorFailureWithoutExposingPrivateDiagnosticOutput(): void
    {
        $runner = new RecordingYoutubeResolverRunner();
        $runner->result = new ProcessResult(1, '', 'private URL, token, and extractor details');
        $resolver = new YtDlpYoutubeResolver($runner, new DirectUrlValidator(static fn (): array => ['8.8.8.8']));

        try {
            $resolver->resolve(new ValidatedYoutubeUrl(
                'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'www.youtube.com',
                'dQw4w9WgXcQ'
            ));
            self::fail('Failed yt-dlp command was accepted.');
        } catch (MediaValidationException $exception) {
            self::assertSame('youtube_video_unavailable', $exception->publicCode());
            self::assertStringNotContainsString('private', $exception->getMessage());
        }
    }

    /** @dataProvider processFailures */
    public function testMapsProcessFailuresToStablePublicMediaCodes(string $processCode, string $mediaCode): void
    {
        $runner = new RecordingYoutubeResolverRunner();
        $runner->exception = new ProcessExecutionException($processCode);
        $resolver = new YtDlpYoutubeResolver($runner, new DirectUrlValidator(static fn (): array => ['8.8.8.8']));

        try {
            $resolver->resolve(new ValidatedYoutubeUrl(
                'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'www.youtube.com',
                'dQw4w9WgXcQ'
            ));
            self::fail('A failed resolver process was accepted.');
        } catch (MediaValidationException $exception) {
            self::assertSame($mediaCode, $exception->publicCode());
        }
    }

    public static function processFailures(): iterable
    {
        yield 'timeout is retryable network failure' => ['process_timeout', 'remote_timeout'];
        yield 'missing dependency is a clear capability failure' => ['process_unavailable', 'youtube_import_unavailable'];
        yield 'excessive metadata fails closed' => ['process_output_limit', 'youtube_metadata_limit'];
        yield 'runner failure is unavailable video' => ['process_failed', 'youtube_video_unavailable'];
    }

    /** @return array<string, mixed> */
    private function metadata(): array
    {
        return [
            '_type' => 'video',
            'extractor_key' => 'Youtube',
            'id' => 'dQw4w9WgXcQ',
            'availability' => 'public',
            'is_live' => false,
            'live_status' => 'not_live',
            'age_limit' => 0,
            'has_drm' => false,
            'ext' => 'mp4',
            'protocol' => 'https',
            'vcodec' => 'avc1.42001E',
            'acodec' => 'mp4a.40.2',
            'url' => 'https://rr1---sn.example.googlevideo.com/videoplayback?expire=1',
        ];
    }
}

final class RecordingYoutubeResolverRunner extends ProcessRunner
{
    public ProcessResult $result;
    public ?ProcessExecutionException $exception = null;
    /** @var list<string> */
    public array $command = [];
    public int $timeoutSeconds = 0;
    public int $outputLimitBytes = 0;

    public function __construct()
    {
        parent::__construct(['yt-dlp'], sys_get_temp_dir());
    }

    public function run(array $command, int $timeoutSeconds, int $outputLimitBytes): ProcessResult
    {
        $this->command = $command;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->outputLimitBytes = $outputLimitBytes;
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->result;
    }
}
