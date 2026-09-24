<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Media\ProjectSource;
use App\Media\Reframe\AspectRatio;
use App\Media\Reframe\ReframeKeyframe;
use App\Media\Reframe\ReframePlan;
use App\Media\RenderedClipArtifacts;
use App\Media\RenderClipRequest;
use App\Media\Editor\EditorOptions;
use App\Media\Subtitles\SubtitleCue;
use App\Media\Subtitles\Transcript;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RenderClipRequestTest extends TestCase
{
    private ProjectSource $source;

    protected function setUp(): void
    {
        $this->source = new ProjectSource(11, 7, 'local', 'users/7/episode.mp4', 'video/mp4');
    }

    public function testExposesValidatedImmutableRenderValues(): void
    {
        $request = new RenderClipRequest($this->source, 12.5, 24.75, str_repeat('a', 32));

        self::assertSame($this->source, $request->source());
        self::assertSame(12.5, $request->startTime());
        self::assertSame(24.75, $request->durationSeconds());
        self::assertSame(str_repeat('a', 32), $request->jobToken());
        self::assertSame('original', $request->reframePlan()->mode());

        foreach ((new \ReflectionClass($request))->getProperties() as $property) {
            self::assertTrue($property->isPrivate());
        }
    }

    public function testPreservesAnExplicitReframePlan(): void
    {
        $manualPlan = ReframePlan::manual(
            AspectRatio::fromString('1:1'),
            new ReframeKeyframe(0, 0.25, 0.75, 'manual')
        );

        $request = new RenderClipRequest($this->source, 1.0, 2.0, str_repeat('a', 32), $manualPlan);

        self::assertSame($manualPlan, $request->reframePlan());
    }

    public function testCarriesOptionalEditorSnapshotAndTranscriptWithoutChangingLegacyDefaults(): void
    {
        $options = EditorOptions::fromArray(['style' => 'minimal']);
        $transcript = new Transcript('pt-BR', [new SubtitleCue(0, 1000, 'Olá')], 2000);
        $request = new RenderClipRequest($this->source, 1.0, 2.0, str_repeat('a', 32), null, $options, $transcript);
        self::assertSame($options, $request->editorOptions()); self::assertSame($transcript, $request->transcript());
        $legacy = new RenderClipRequest($this->source, 0.0, 1.0, str_repeat('b', 32));
        self::assertSame('none', $legacy->editorOptions()->style()); self::assertNull($legacy->transcript());
    }

    public function testProjectSourceAcceptsIndependentOptionalGeometry(): void
    {
        $legacy = new ProjectSource(1, 2, 'local', 'legacy.mp4', 'video/mp4');
        $widthOnly = new ProjectSource(2, 2, 'local', 'width.mp4', 'video/mp4', 1920, null);
        $heightOnly = new ProjectSource(3, 2, 'local', 'height.mp4', 'video/mp4', null, 1080);
        $complete = new ProjectSource(4, 2, 'local', 'complete.mp4', 'video/mp4', 1920, 1080);

        self::assertNull($legacy->width());
        self::assertNull($legacy->height());
        self::assertFalse($legacy->hasUsableGeometry());
        self::assertSame(1920, $widthOnly->width());
        self::assertNull($widthOnly->height());
        self::assertFalse($widthOnly->hasUsableGeometry());
        self::assertNull($heightOnly->width());
        self::assertSame(1080, $heightOnly->height());
        self::assertFalse($heightOnly->hasUsableGeometry());
        self::assertSame(1920, $complete->width());
        self::assertSame(1080, $complete->height());
        self::assertTrue($complete->hasUsableGeometry());
    }

    /** @dataProvider invalidProjectSourceGeometry */
    public function testProjectSourceRejectsOnlyNonNullNonPositiveGeometry(?int $width, ?int $height): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProjectSource(1, 2, 'local', 'source.mp4', 'video/mp4', $width, $height);
    }

    /** @return iterable<string, array{?int, ?int}> */
    public function invalidProjectSourceGeometry(): iterable
    {
        yield 'zero width' => [0, null];
        yield 'negative width' => [-1, null];
        yield 'zero height' => [null, 0];
        yield 'negative height' => [null, -1];
    }

    /** @dataProvider invalidRequests */
    public function testRejectsInvalidRenderValues(float $startTime, float $durationSeconds, string $jobToken): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RenderClipRequest($this->source, $startTime, $durationSeconds, $jobToken);
    }

    /** @return iterable<string, array{float, float, string}> */
    public function invalidRequests(): iterable
    {
        $token = str_repeat('a', 32);

        yield 'negative start' => [-0.001, 24.0, $token];
        yield 'NaN start' => [NAN, 24.0, $token];
        yield 'infinite start' => [INF, 24.0, $token];
        yield 'NaN duration' => [0.0, NAN, $token];
        yield 'infinite duration' => [0.0, INF, $token];
        yield 'duration below one second' => [0.0, 0.999, $token];
        yield 'duration above 180 seconds' => [0.0, 180.001, $token];
        yield 'short token' => [0.0, 1.0, str_repeat('a', 31)];
        yield 'uppercase token' => [0.0, 1.0, str_repeat('A', 32)];
        yield 'non hexadecimal token' => [0.0, 1.0, str_repeat('g', 32)];
    }

    public function testAcceptsDurationBoundaries(): void
    {
        self::assertSame(1.0, (new RenderClipRequest($this->source, 0.0, 1.0, str_repeat('b', 32)))->durationSeconds());
        self::assertSame(180.0, (new RenderClipRequest($this->source, 0.0, 180.0, str_repeat('c', 32)))->durationSeconds());
    }

    /** @dataProvider invalidArtifactSizes */
    public function testRejectsNonPositiveArtifactSizes(int $videoSizeBytes, int $thumbnailSizeBytes): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RenderedClipArtifacts('video.mp4', $videoSizeBytes, 'video/mp4', 'thumb.jpg', $thumbnailSizeBytes, 'image/jpeg');
    }

    /** @return iterable<string, array{int, int}> */
    public function invalidArtifactSizes(): iterable
    {
        yield 'empty video' => [0, 1];
        yield 'negative video' => [-1, 1];
        yield 'empty thumbnail' => [1, 0];
        yield 'negative thumbnail' => [1, -1];
    }

    public function testArtifactsExposeMetadataAndCleanupOnlyTheirExactFilesRepeatedly(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cliplab-artifacts-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $videoPath = $directory . DIRECTORY_SEPARATOR . 'video.mp4';
        $thumbnailPath = $directory . DIRECTORY_SEPARATOR . 'thumb.jpg';
        $unrelatedPath = $directory . DIRECTORY_SEPARATOR . 'keep.txt';
        self::assertSame(5, file_put_contents($videoPath, 'video'));
        self::assertSame(5, file_put_contents($thumbnailPath, 'thumb'));
        self::assertSame(4, file_put_contents($unrelatedPath, 'keep'));

        try {
            $artifacts = new RenderedClipArtifacts($videoPath, 5, 'video/mp4', $thumbnailPath, 5, 'image/jpeg');

            self::assertSame($videoPath, $artifacts->videoPath());
            self::assertSame(5, $artifacts->videoSizeBytes());
            self::assertSame('video/mp4', $artifacts->videoMimeType());
            self::assertSame($thumbnailPath, $artifacts->thumbnailPath());
            self::assertSame(5, $artifacts->thumbnailSizeBytes());
            self::assertSame('image/jpeg', $artifacts->thumbnailMimeType());

            $artifacts->cleanup();
            $artifacts->cleanup();

            self::assertFileDoesNotExist($videoPath);
            self::assertFileDoesNotExist($thumbnailPath);
            self::assertFileExists($unrelatedPath);
        } finally {
            foreach ([$videoPath, $thumbnailPath, $unrelatedPath] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }
    }
}
