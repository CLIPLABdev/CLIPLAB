<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Media\Editor\EditorOptions;
use App\Media\Reframe\AspectRatio;
use App\Media\Subtitles\{Transcript,SubtitleCue,SrtCodec,TranscriptRevision,AssDocumentBuilder};
use PHPUnit\Framework\TestCase;

final class MediaHomologationTest extends TestCase
{
    public function testNewOutputsAndAllowlistedLegacySnapshots(): void
    {
        foreach ([['9:16',1080,1920,720,1280],['1:1',1080,1080,720,720],['16:9',1920,1080,1280,720],['4:5',1080,1350,720,900]] as [$ratio,$w,$h,$oldW,$oldH]) {
            self::assertSame([$w,$h],[AspectRatio::fromString($ratio)->outputWidth(),AspectRatio::fromString($ratio)->outputHeight()]);
            self::assertSame([$oldW,$oldH],[AspectRatio::fromStored($ratio,$oldW,$oldH)->outputWidth(),AspectRatio::fromStored($ratio,$oldW,$oldH)->outputHeight()]);
        }
        $this->expectException(\InvalidArgumentException::class);
        AspectRatio::fromStored('9:16',720,1282);
    }

    public function testTypographyAnimationAndSafeFinalCtaAreEmitted(): void
    {
        $track=new Transcript('pt',[new SubtitleCue(0,1000,'Oi')],12000);
        $options=EditorOptions::fromArray(['style'=>'podcast','font_family'=>'Georgia','background_color'=>'#123456','outline_width'=>5,'shadow_depth'=>3,'font_weight'=>'normal','animation'=>'fade','cta_text'=>'{\\pos(1,1)}Siga']);
        $ass=AssDocumentBuilder::build($track,$options,1080,1920,12000);
        self::assertStringContainsString('Style: Caption,Georgia,66,',$ass);
        self::assertStringContainsString('&H00563412',$ass);
        self::assertStringContainsString(',3,5,3,2,',$ass);
        self::assertStringContainsString('{\\fad(150,150)}Oi',$ass);
        self::assertStringContainsString('0:00:07.00,0:00:12.00,CTA',$ass);
        self::assertStringNotContainsString('{\\pos(1,1)}',$ass);
        $pop=AssDocumentBuilder::build($track,EditorOptions::fromArray(['style'=>'viral','font_weight'=>'bold','animation'=>'pop']),1080,1920,12000);
        self::assertStringContainsString('\\t(0,150,\\fscx100\\fscy100)',$pop);
        self::assertTrue(EditorOptions::fromArray(['cta_text'=>'Siga'])->hasOverlays());
    }

    /** @dataProvider portraitDimensions */
    public function testPortraitAssUsesAsymmetricSafeZonesForAnchoredText(int $width,int $height,int $top,int $bottom): void
    {
        $topDocument=AssDocumentBuilder::build(null,EditorOptions::fromArray([
            'position'=>'top','title'=>'Título','watermark'=>'Marca',
        ]),$width,$height,1000);
        self::assertSame([65,65,$top],self::styleMargins($topDocument,'Caption'));
        self::assertSame([65,65,$top],self::styleMargins($topDocument,'Title'));
        self::assertSame([65,65,$bottom],self::styleMargins($topDocument,'Brand'));

        $bottomDocument=AssDocumentBuilder::build(null,EditorOptions::fromArray(['position'=>'bottom']),$width,$height,1000);
        self::assertSame([65,65,$bottom],self::styleMargins($bottomDocument,'Caption'));
    }

    public function portraitDimensions(): iterable
    {
        yield '9:16'=>[1080,1920,192,346];
        yield '4:5'=>[1080,1350,135,243];
    }

    /** @dataProvider portraitLogoDimensions */
    public function testPortraitAssReservesTheTopLogoCornerForTitleAndTopCaption(int $width,int $height,int $top,int $bottom): void
    {
        $document=AssDocumentBuilder::build(null,EditorOptions::fromArray([
            'position'=>'top','title'=>'Título','watermark'=>'Marca',
            'logo_asset_id'=>1,'logo_position'=>'top_right','logo_scale'=>10,
        ]),$width,$height,1000);
        self::assertSame([65,195,$top],self::styleMargins($document,'Caption'));
        self::assertSame([65,195,$top],self::styleMargins($document,'Title'));
        self::assertSame([65,65,$bottom],self::styleMargins($document,'Brand'));
        self::assertSame([65,65,65],self::styleMargins($document,'CTA'));
    }

    public function portraitLogoDimensions(): iterable
    {
        yield '9:16'=>[1080,1920,192,346];
        yield '4:5'=>[1080,1350,135,243];
    }

    public function testPortraitAssReservesOnlyTheMatchingBottomLogoCorner(): void
    {
        $right=AssDocumentBuilder::build(null,EditorOptions::fromArray([
            'position'=>'bottom','title'=>'Título','watermark'=>'Marca',
            'logo_asset_id'=>1,'logo_position'=>'bottom_right','logo_scale'=>10,
        ]),1080,1920,1000);
        self::assertSame([65,195,346],self::styleMargins($right,'Caption'));
        self::assertSame([65,65,192],self::styleMargins($right,'Title'));
        self::assertSame([65,195,346],self::styleMargins($right,'Brand'));

        $left=AssDocumentBuilder::build(null,EditorOptions::fromArray([
            'position'=>'bottom','watermark'=>'Marca',
            'logo_asset_id'=>1,'logo_position'=>'bottom_left','logo_scale'=>10,
        ]),1080,1920,1000);
        self::assertSame([195,65,346],self::styleMargins($left,'Caption'));
        self::assertSame([195,65,346],self::styleMargins($left,'Brand'));

        $middle=AssDocumentBuilder::build(null,EditorOptions::fromArray([
            'position'=>'middle','logo_asset_id'=>1,'logo_position'=>'top_left','logo_scale'=>25,
        ]),1080,1920,1000);
        self::assertSame([65,65,65],self::styleMargins($middle,'Caption'));

        $maximumScale=AssDocumentBuilder::build(null,EditorOptions::fromArray([
            'title'=>'Título','logo_asset_id'=>1,'logo_position'=>'top_left','logo_scale'=>25,
        ]),1080,1920,1000);
        self::assertSame([357,65,192],self::styleMargins($maximumScale,'Title'));
    }

    /** @dataProvider nonPortraitDimensions */
    public function testSquareAndLandscapeAssKeepLegacyTextMargins(int $width,int $height): void
    {
        foreach ([
            ['position'=>'bottom','title'=>'Título','watermark'=>'Marca'],
            ['position'=>'bottom','title'=>'Título','watermark'=>'Marca','logo_asset_id'=>1,'logo_position'=>'bottom_right','logo_scale'=>25],
        ] as $options) {
            $document=AssDocumentBuilder::build(null,EditorOptions::fromArray($options),$width,$height,1000);
            foreach (['Caption','Title','Brand','CTA'] as $style) self::assertSame([65,65,65],self::styleMargins($document,$style));
        }
    }

    public function nonPortraitDimensions(): iterable
    {
        yield 'square'=>[1080,1080];
        yield 'landscape'=>[1920,1080];
    }

    /** @dataProvider invalidOptions */
    public function testRejectsUntrustedOptions(array $options): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EditorOptions::fromArray($options);
    }
    public function invalidOptions(): iterable
    {
        foreach ([['font_family'=>'Arial,evil'],['background_color'=>'red'],['outline_width'=>7],['shadow_depth'=>-1],['font_weight'=>'heavy'],['animation'=>'evil'],['cta_text'=>str_repeat('a',121)],['logo_asset_id'=>-1],['logo_asset_id'=>'1'],['logo_position'=>'middle'],['logo_scale'=>26]] as $case) yield [$case];
    }

    public function testRevisionsKeepOnlyKnownWordTimes(): void
    {
        $original=new Transcript('pt-BR',[
            new SubtitleCue(0,1000,'Oi mundo',[['start_ms'=>0,'end_ms'=>400,'text'=>'Oi'],['start_ms'=>600,'end_ms'=>1000,'text'=>'mundo']]),
            new SubtitleCue(1100,2000,'Até logo',[['start_ms'=>1100,'end_ms'=>1400,'text'=>'Até'],['start_ms'=>1600,'end_ms'=>2000,'text'=>'logo']]),
        ],3000);
        $unchanged=TranscriptRevision::merge(SrtCodec::parse(SrtCodec::format($original),3000),$original,true);
        self::assertSame($original->toArray(),$unchanged->toArray());
        $srt=str_replace(['Oi mundo','Até logo'],['Olá mundo','Até mais tarde'],SrtCodec::format($original));
        $updated=TranscriptRevision::merge(SrtCodec::parse($srt,3000),$original,true);
        self::assertSame(['start_ms'=>0,'end_ms'=>400,'text'=>'Olá'],$updated->cues()[0]->words()[0]);
        self::assertSame([],$updated->cues()[1]->words());
        self::assertStringContainsString('{\\kf40}Olá',AssDocumentBuilder::build($updated,EditorOptions::fromArray(['style'=>'karaoke']),1080,1920,3000));
        self::assertSame([],TranscriptRevision::merge(SrtCodec::parse($srt,3000),$original,false)->cues()[0]->words());
        $retimed=SrtCodec::parse(str_replace('00:00:00,000','00:00:00,010',SrtCodec::format($original)),3000);
        $merged=TranscriptRevision::merge($retimed,$original,true);
        self::assertSame([],$merged->cues()[0]->words());
        self::assertSame($original->cues()[1]->words(),$merged->cues()[1]->words());
    }

    /** @return array{int,int,int} */
    private static function styleMargins(string $document,string $style): array
    {
        self::assertSame(1,preg_match('/^Style: '.preg_quote($style,'/').',(.+)$/m',$document,$match));
        $fields=explode(',',trim($match[1]));
        return [(int)$fields[18],(int)$fields[19],(int)$fields[20]];
    }
}
