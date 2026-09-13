<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Media\Subtitles\TranscriptValidator;
use App\Media\Subtitles\SrtCodec;
use App\Media\Subtitles\AssDocumentBuilder;
use App\Media\Editor\EditorOptions;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SubtitleDomainTest extends TestCase
{
    public function testSrtRoundtripPreservesUnicodeTimesAndUntrustedText(): void
    {
        $text = "Olá <script>x</script> {\\pos(1,1)}\nPróxima linha";
        $track = TranscriptValidator::fromArray(['language'=>'pt-BR','cues'=>[
            ['start_ms'=>1,'end_ms'=>1234,'text'=>$text],
            ['start_ms'=>1500,'end_ms'=>2500,'text'=>'Fim.'],
        ]], 3000);
        $srt = SrtCodec::format($track);
        self::assertStringContainsString('00:00:00,001 --> 00:00:01,234', $srt);
        self::assertSame($track->toArray()['cues'], SrtCodec::parse($srt,3000)->toArray()['cues']);
    }

    /** @dataProvider invalidTracks */
    public function testRejectsInvalidTracks(array $input, int $duration): void
    {
        $this->expectException(InvalidArgumentException::class);
        TranscriptValidator::fromArray($input,$duration);
    }

    public function invalidTracks(): iterable
    {
        $cue = ['start_ms'=>0,'end_ms'=>1000,'text'=>'Oi'];
        foreach (['start_ms'=>-1,'end_ms'=>3001,'text'=>'','words'=>[['start_ms'=>0,'end_ms'=>1001,'text'=>'Oi']],'extra'=>'x'] as $key=>$value) {
            yield $key=>[['language'=>'pt','cues'=>[array_replace($cue,[$key=>$value])]],3000];
        }
        yield 'float'=>[['language'=>'pt','cues'=>[array_replace($cue,['start_ms'=>0.0])]],3000];
        yield 'reversed'=>[['language'=>'pt','cues'=>[array_replace($cue,['end_ms'=>0])]],3000];
        yield 'overlap'=>[['language'=>'pt','cues'=>[$cue,$cue]],3000];
        yield 'invalid UTF8'=>[['language'=>'pt','cues'=>[array_replace($cue,['text'=>"\xFF"])]],3000];
        yield 'control'=>[['language'=>'pt','cues'=>[array_replace($cue,['text'=>"Oi\x00"])]],3000];
        yield 'too long'=>[['language'=>'pt','cues'=>[array_replace($cue,['text'=>str_repeat('a',351)])]],3000];
        yield 'word mismatch'=>[['language'=>'pt','cues'=>[array_replace($cue,['words'=>[['start_ms'=>0,'end_ms'=>1000,'text'=>'outro']]])]],3000];
        yield 'language injection'=>[['language'=>'pt\\n','cues'=>[$cue]],3000];
        yield 'unknown root'=>[['language'=>'pt','cues'=>[$cue],'secret'=>'x'],3000];
        yield 'associative cues'=>[['language'=>'pt','cues'=>['x'=>$cue]],3000];
        yield 'too many'=>[['language'=>'pt','cues'=>array_fill(0,501,$cue)],3000];
        $words=[];
        for ($i=0;$i<151;$i++) $words[]=['start_ms'=>$i,'end_ms'=>$i+1,'text'=>'a'];
        yield 'too many aligned words in one cue'=>[['language'=>'pt','cues'=>[[
            'start_ms'=>0,'end_ms'=>151,'text'=>trim(str_repeat('a ',151)),'words'=>$words,
        ]]],1000];
        yield 'duration'=>[['language'=>'pt','cues'=>[]],180001];
    }

    public function testSilenceAndAlignedWordsAreAcceptedWithoutInventingTimestamps(): void
    {
        self::assertSame([],TranscriptValidator::fromArray(['language'=>'und','cues'=>[]],1000)->cues());
        $track=TranscriptValidator::fromArray(['language'=>'pt','cues'=>[['start_ms'=>0,'end_ms'=>1000,'text'=>'Oi mundo','words'=>[
            ['start_ms'=>0,'end_ms'=>400,'text'=>'Oi'],['start_ms'=>600,'end_ms'=>1000,'text'=>'mundo'],
        ]]]],1000);
        self::assertCount(2,$track->cues()[0]->words());
        $ass=AssDocumentBuilder::build($track,EditorOptions::fromArray(['style'=>'karaoke']),720,1280,1000);
        self::assertStringContainsString('{\\kf40}Oi',$ass);
        self::assertStringContainsString('{\\k20}',$ass);
    }

    public function testHighlightUsesWordStepsWhileKaraokeUsesProgressiveFill(): void
    {
        $track=TranscriptValidator::fromArray(['language'=>'pt','cues'=>[['start_ms'=>0,'end_ms'=>1000,'text'=>'Olá','words'=>[
            ['start_ms'=>0,'end_ms'=>1000,'text'=>'Olá'],
        ]]]],1000);
        $highlight=AssDocumentBuilder::build($track,EditorOptions::fromArray(['style'=>'highlight']),720,1280,1000);
        $karaoke=AssDocumentBuilder::build($track,EditorOptions::fromArray(['style'=>'karaoke']),720,1280,1000);
        self::assertStringContainsString('{\\k100}Olá',$highlight);
        self::assertStringContainsString('{\\kf100}Olá',$karaoke);
    }

    public function testBrandRendersAtBottomRightAsTheEditorDescribes(): void
    {
        $ass=AssDocumentBuilder::build(null,EditorOptions::fromArray(['watermark'=>'@canal']),720,1280,1000);
        preg_match('/^Style: Brand,(.+)$/m',$ass,$match);
        $style=explode(',',trim($match[1]));
        self::assertSame('3',$style[17]);
    }

    public function testRejectsWordLimitEvenWithoutOptionalWordAlignment(): void
    {
        $cues=[];
        for ($i=0;$i<61;$i++) $cues[]=['start_ms'=>$i*1000,'end_ms'=>($i+1)*1000,'text'=>trim(str_repeat('a ',100))];
        $this->expectException(InvalidArgumentException::class);
        TranscriptValidator::fromArray(['language'=>'pt','cues'=>$cues],61000);
    }

    public function testRejectsBlankLinesInsideCuesSoSrtRoundtripIsUnambiguous(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TranscriptValidator::fromArray(['language'=>'pt','cues'=>[['start_ms'=>0,'end_ms'=>1000,'text'=>"uma\n\nduas"]]],1000);
    }

    public function testPreservesTrailingTextSpacesInSrt(): void
    {
        $track=TranscriptValidator::fromArray(['language'=>'pt','cues'=>[['start_ms'=>0,'end_ms'=>1000,'text'=>'Oi  ']]],1000);
        self::assertSame('Oi  ',SrtCodec::parse(SrtCodec::format($track),1000)->cues()[0]->text());
    }

    /** @dataProvider invalidSrt */
    public function testRejectsMalformedSrt(string $srt): void
    {
        $this->expectException(InvalidArgumentException::class);
        SrtCodec::parse($srt,2000);
    }

    public function invalidSrt(): iterable
    {
        yield ['1\n00:00:00,000 --> 00:00:01,000\nOi'];
        yield ["1\n00:00:00,000 --> 00:00:03,000\nOi"];
        yield ["1\n00:00:02,000 --> 00:00:01,000\nOi"];
        yield ["1\n00:00:00,000 --> 00:00:01,000\n"];
        yield ["2\n00:00:00,000 --> 00:00:01,000\nOi"];
    }

    public function testAssUsesControlledStylesAndCannotExecuteUserTags(): void
    {
        $track=TranscriptValidator::fromArray(['language'=>'pt','cues'=>[['start_ms'=>0,'end_ms'=>1000,'text'=>'{\\pos(1,1)}Olá']]],1000);
        $options=EditorOptions::fromArray(['style'=>'custom','position'=>'top','color'=>'#12ab34','accent_color'=>'#ffcc00','font_size'=>48,'title'=>'{\\an9}Título','watermark'=>'Marca']);
        $ass=AssDocumentBuilder::build($track,$options,720,1280,1000);
        self::assertStringContainsString('PlayResX: 720',$ass);
        self::assertStringContainsString('&H0034AB12',$ass);
        self::assertStringNotContainsString('{\\pos(1,1)}',$ass);
        self::assertStringNotContainsString('{\\an9}Título',$ass);
        self::assertStringContainsString('Marca',$ass);
        self::assertStringNotContainsString('{\\kf', $ass);
    }

    /** @dataProvider invalidOptions */
    public function testRejectsInvalidOptions(array $options): void
    {
        $this->expectException(InvalidArgumentException::class);
        EditorOptions::fromArray($options);
    }

    public function invalidOptions(): iterable
    {
        foreach ([['style'=>'evil'],['position'=>'left'],['color'=>'red'],['font_size'=>0],['font_size'=>'32'],['watermark'=>str_repeat('a',81)],['title'=>"a\nb"],['font'=>'/secret'],['style'=>null]] as $case) yield [$case];
    }
}
