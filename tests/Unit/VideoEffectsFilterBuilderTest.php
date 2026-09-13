<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Media\Editor\EditorOptions;
use App\Media\Editor\VideoEffectsFilterBuilder;
use PHPUnit\Framework\TestCase;
final class VideoEffectsFilterBuilderTest extends TestCase
{
    public function testNeutralDoesNotChangeLegacyGraphAndTypedFiltersFollowSpecifiedOrder(): void
    {
        $builder=new VideoEffectsFilterBuilder();
        self::assertSame('',$builder->build(EditorOptions::fromArray([]),720,1280,2000));
        $filter=$builder->build(EditorOptions::fromArray(['brightness'=>25,'contrast'=>125,'saturation'=>150,'blur'=>2,'noise'=>8,'vignette'=>50,'video_fade_in_ms'=>3000,'video_fade_out_ms'=>3000]),720,1280,2000);
        self::assertMatchesRegularExpression('/eq=brightness=0\.25:contrast=1\.25:saturation=1\.5,gblur=sigma=2,noise=.*vignette=.*fade=t=in:st=0:d=1,fade=t=out:st=1:d=1$/',$filter);
    }
    public function testMotionUsesThirtyFpsAndBoundedProgressWhileStaticZoomPreservesRate(): void
    {
        $builder=new VideoEffectsFilterBuilder();
        foreach (['zoom_in','zoom_out','pan_left','pan_right'] as $motion) {
            $filter=$builder->build(EditorOptions::fromArray(['zoom_percent'=>125,'motion'=>$motion]),320,180,2000);
            self::assertStringContainsString('fps=30',$filter);
            self::assertStringContainsString('min(1',$filter);
            self::assertStringContainsString('s=320x180',$filter);
        }
        $static=$builder->build(EditorOptions::fromArray(['zoom_percent'=>125]),320,180,2000);
        self::assertStringNotContainsString('fps=',$static);
        self::assertStringContainsString('crop=',$static);
        self::assertStringContainsString('scale=320:180',$static);
    }
    public function testRejectsInvalidGeometryAndDuration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new VideoEffectsFilterBuilder())->build(EditorOptions::fromArray([]),0,100,0);
    }

    public function testActiveEffectsStillRejectGeometryAboveEffectLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new VideoEffectsFilterBuilder())->build(EditorOptions::fromArray(['brightness'=>1]),10000,2000,2000);
    }
}
