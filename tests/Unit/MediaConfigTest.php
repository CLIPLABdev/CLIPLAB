<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MediaConfigTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach ($this->environmentNames() as $name) {
            $this->environment[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testMediaDefaultsAreHostingerSafe(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/media.php';

        self::assertSame(['mp4', 'mov', 'webm'], $config['allowed_extensions']);
        self::assertSame('ffmpeg', $config['ffmpeg_binary']);
        self::assertSame(240, $config['render_timeout_seconds']);
        self::assertSame(524288000, $config['render_max_output_bytes']);
        self::assertSame(180, $config['render_max_duration_seconds']);
        self::assertSame(10485760, $config['render_thumbnail_max_bytes']);
        self::assertSame(90, $config['reframe_max_duration_seconds']);
        self::assertSame(32, $config['reframe_max_keyframes']);
        self::assertSame(180, $config['reframe_preview_max_frames']);
        self::assertSame(320, $config['reframe_preview_max_edge']);
        self::assertSame('1.0.1', $config['mediapipe_asset_version']);
        self::assertSame(524288000, $config['configured_max_upload_bytes']);
        self::assertSame(524288000, $config['max_upload_bytes']);
        self::assertGreaterThanOrEqual(1, $config['effective_upload_bytes']);
        self::assertLessThanOrEqual($config['max_upload_bytes'], $config['effective_upload_bytes']);
        self::assertGreaterThanOrEqual(60, $config['queue']['lease_seconds']);
        self::assertSame('local', $config['disk']);
        self::assertStringNotContainsString(DIRECTORY_SEPARATOR . 'public', $config['private_root']);
        self::assertLessThanOrEqual(10, $config['queue']['batch_size']);
    }

    public function testUnsafeNumericOverridesAreClamped(): void
    {
        putenv('MEDIA_MAX_UPLOAD_BYTES=0');
        putenv('MEDIA_DOWNLOAD_TIMEOUT_SECONDS=1');
        putenv('MEDIA_MAX_REDIRECTS=-1');
        putenv('PROCESS_TIMEOUT_SECONDS=1');
        putenv('PROCESS_OUTPUT_LIMIT_BYTES=10');
        putenv('RENDER_TIMEOUT_SECONDS=1');
        putenv('RENDER_MAX_OUTPUT_BYTES=0');
        putenv('RENDER_MAX_DURATION_SECONDS=0');
        putenv('RENDER_THUMBNAIL_MAX_BYTES=0');
        putenv('REFRAME_MAX_DURATION_SECONDS=0');
        putenv('REFRAME_PREVIEW_MAX_FRAMES=1');
        putenv('REFRAME_PREVIEW_MAX_EDGE=1');
        putenv('QUEUE_LEASE_SECONDS=10');
        putenv('QUEUE_MAX_ATTEMPTS=0');
        putenv('QUEUE_BATCH_SIZE=99');

        $config = require dirname(__DIR__, 2) . '/config/media.php';

        self::assertSame(1, $config['configured_max_upload_bytes']);
        self::assertSame(1, $config['max_upload_bytes']);
        self::assertSame(1, $config['effective_upload_bytes']);
        self::assertSame(5, $config['download_timeout_seconds']);
        self::assertSame(0, $config['max_redirects']);
        self::assertSame(5, $config['process_timeout_seconds']);
        self::assertSame(4096, $config['process_output_limit_bytes']);
        self::assertSame(5, $config['render_timeout_seconds']);
        self::assertSame(1, $config['render_max_output_bytes']);
        self::assertSame(1, $config['render_max_duration_seconds']);
        self::assertSame(1, $config['render_thumbnail_max_bytes']);
        self::assertSame(1, $config['reframe_max_duration_seconds']);
        self::assertSame(2, $config['reframe_preview_max_frames']);
        self::assertSame(64, $config['reframe_preview_max_edge']);
        self::assertSame(60, $config['queue']['lease_seconds']);
        self::assertSame(1, $config['queue']['max_attempts']);
        self::assertSame(10, $config['queue']['batch_size']);
    }

    public function testReframeUpperBoundsAreClamped(): void
    {
        putenv('REFRAME_MAX_DURATION_SECONDS=999');
        putenv('REFRAME_PREVIEW_MAX_FRAMES=999');
        putenv('REFRAME_PREVIEW_MAX_EDGE=999');

        $config = require dirname(__DIR__, 2) . '/config/media.php';

        self::assertSame(180, $config['reframe_max_duration_seconds']);
        self::assertSame(180, $config['reframe_preview_max_frames']);
        self::assertSame(320, $config['reframe_preview_max_edge']);
    }

    public function testExactGateValuesRemainVisibleToTheRequirementsChecker(): void
    {
        putenv('REFRAME_MAX_KEYFRAMES=31');
        putenv('MEDIAPIPE_ASSET_VERSION=1.0.0');

        $config = require dirname(__DIR__, 2) . '/config/media.php';

        self::assertSame(31, $config['reframe_max_keyframes']);
        self::assertSame('1.0.0', $config['mediapipe_asset_version']);
    }

    /** @dataProvider unsafePrivateRoots */
    public function testPrivateRootRejectsPublicLocationsEvenWhenTheyDoNotExist(string $relativePath): void
    {
        $root = dirname(__DIR__, 2);
        putenv('MEDIA_PRIVATE_ROOT=' . $root . DIRECTORY_SEPARATOR . $relativePath);

        $this->expectException(\InvalidArgumentException::class);
        require $root . '/config/media.php';
    }

    /** @return iterable<string, array{string}> */
    public function unsafePrivateRoots(): iterable
    {
        yield 'public root' => ['public'];
        yield 'nonexistent public child' => ['public/never-created/media'];
        yield 'normalized public child' => ['storage/../public/uploads'];
        yield 'hostinger public html child' => ['public_html/media'];
    }

    /** @return list<string> */
    private function environmentNames(): array
    {
        return [
            'MEDIA_DISK',
            'MEDIA_PRIVATE_ROOT',
            'MEDIA_MAX_UPLOAD_BYTES',
            'MEDIA_DOWNLOAD_TIMEOUT_SECONDS',
            'MEDIA_MAX_REDIRECTS',
            'FFPROBE_BINARY',
            'FFMPEG_BINARY',
            'PROCESS_TIMEOUT_SECONDS',
            'PROCESS_OUTPUT_LIMIT_BYTES',
            'RENDER_TIMEOUT_SECONDS',
            'RENDER_MAX_OUTPUT_BYTES',
            'RENDER_MAX_DURATION_SECONDS',
            'RENDER_THUMBNAIL_MAX_BYTES',
            'REFRAME_MAX_DURATION_SECONDS',
            'REFRAME_MAX_KEYFRAMES',
            'REFRAME_PREVIEW_MAX_FRAMES',
            'REFRAME_PREVIEW_MAX_EDGE',
            'MEDIAPIPE_ASSET_VERSION',
            'QUEUE_LEASE_SECONDS',
            'QUEUE_MAX_ATTEMPTS',
            'QUEUE_BATCH_SIZE',
        ];
    }
}
