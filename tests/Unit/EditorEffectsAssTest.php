<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Media\Editor\EditorOptions;
use App\Media\Subtitles\AssDocumentBuilder;
use App\Media\Subtitles\SubtitleCue;
use App\Media\Subtitles\Transcript;
use PHPUnit\Framework\TestCase;

final class EditorEffectsAssTest extends TestCase
{
    public function testNeutralStylesPreserveLegacyAssContract(): void
    {
        $ass=AssDocumentBuilder::build(new Transcript('pt',[new SubtitleCue(0,1000,'Oi')],2000),EditorOptions::fromArray(['style'=>'minimal','animation'=>'fade']),720,1280,2000);
        $expected=[
            'Style: Caption,Arial,44,&H00FFFFFF,&H0015CCFA,&H00151515,&H80000000,0,0,0,0,100,100,0,0,1,2,0,2,43,43,230,1',
            'Style: Title,Arial,44,&H00FFFFFF,&H0015CCFA,&H00151515,&H80000000,-1,0,0,0,100,100,0,0,1,2,0,8,43,43,128,1',
            'Style: CTA,Arial,44,&H00FFFFFF,&H0015CCFA,&H00151515,&H80000000,-1,0,0,0,100,100,0,0,3,2,0,5,43,43,43,1',
            'Style: Brand,Arial,26,&H00FFFFFF,&H0015CCFA,&H00151515,&H80000000,0,0,0,0,100,100,0,0,1,2,0,3,43,43,230,1',
        ];
        preg_match_all('/^Style: .+$/m',$ass,$actual);
        self::assertSame($expected,$actual[0]);
        self::assertStringContainsString('Dialogue: 0,0:00:00.00,0:00:01.00,Caption,,0,0,0,,{\\fad(150,150)}Oi',$ass);
    }
    private function styles(string $ass): array
    {
        $styles=[];
        foreach (explode("\n",$ass) as $line) if (str_starts_with($line,'Style: ')) {
            $parts=explode(',',substr($line,7)); $styles[array_shift($parts)]=$parts;
        }
        return $styles;
    }
    public function testExplicitTypographyColorBackgroundAndCaptionMarginsReachAss(): void
    {
        $o=EditorOptions::fromArray(['style'=>'podcast','font_italic'=>1,'letter_spacing'=>3,'font_weight'=>'normal',
            'caption_margin_x'=>20,'caption_offset_y'=>-30,'outline_color'=>'#123456','background_mode'=>'none',
            'title'=>'Title','watermark'=>'Brand','cta_text'=>'CTA']);
        $styles=$this->styles(AssDocumentBuilder::build(null,$o,720,1280,2000));
        foreach (['Caption','Title','CTA','Brand'] as $role) {
            self::assertSame('0',$styles[$role][6],$role.' weight');
            self::assertSame('-1',$styles[$role][7],$role.' italic');
            self::assertSame('3',$styles[$role][12],$role.' spacing');
            self::assertSame('&H00563412',$styles[$role][4],$role.' outline');
            self::assertSame('1',$styles[$role][14],$role.' background');
        }
        self::assertSame(['63','63','260'],array_slice($styles['Caption'],18,3));
    }
    public function testBoxAndOutlineHaveIndependentColorsAndLegacyRoleDefaultsRemain(): void
    {
        $ass=AssDocumentBuilder::build(new Transcript('pt',[new SubtitleCue(0,1000,'Oi')],1000),EditorOptions::fromArray([
            'style'=>'minimal','background_mode'=>'box','outline_color'=>'#112233','background_color'=>'#445566']),720,1280,1000);
        self::assertStringContainsString('&H00332211',$ass);
        self::assertStringContainsString('&H00665544',$ass);
        self::assertStringContainsString('CaptionBox',$ass);
        preg_match_all('/^Dialogue: ([0-9]+),/m',$ass,$layers);
        self::assertSame(['0','1'],$layers[1],'Box and caption need separate layers to avoid ASS collision repositioning.');
        $styles=$this->styles(AssDocumentBuilder::build(null,EditorOptions::fromArray([]),720,1280,1000));
        self::assertSame('0',$styles['Caption'][6]); self::assertSame('-1',$styles['Title'][6]);
        self::assertSame('-1',$styles['CTA'][6]); self::assertSame('0',$styles['Brand'][6]);
    }
    public function testAnimationsRespectEachEventDurationAndIndependentExit(): void
    {
        $track=new Transcript('pt',[new SubtitleCue(0,200,'Oi')],1000);
        $ass=AssDocumentBuilder::build($track,EditorOptions::fromArray(['style'=>'minimal','animation'=>'pop','animation_duration_ms'=>800,'animation_out'=>'fade','animation_out_duration_ms'=>900]),720,1280,1000);
        self::assertStringContainsString('\\t(0,200,\\fscx100\\fscy100)',$ass);
        self::assertStringContainsString('\\fad(0,200)',$ass);
        $ass=AssDocumentBuilder::build($track,EditorOptions::fromArray(['style'=>'minimal','animation'=>'fade','animation_duration_ms'=>75,'animation_out'=>'none']),720,1280,1000);
        self::assertStringContainsString('\\fad(75,0)',$ass);
    }
    public function testMiddleOffsetCreatesBoundedPositionAndNarrowFrameRetainsUsefulWidth(): void
    {
        $ass=AssDocumentBuilder::build(new Transcript('pt',[new SubtitleCue(0,1000,'Oi')],1000),EditorOptions::fromArray(['style'=>'minimal','position'=>'middle','caption_offset_y'=>120,'caption_margin_x'=>120]),80,40,1000);
        self::assertStringContainsString('\\pos(40,27)',$ass);
        $styles=$this->styles($ass);
        self::assertLessThan(80,(int)$styles['Caption'][18]+(int)$styles['Caption'][19]);
    }
}
