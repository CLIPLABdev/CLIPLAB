<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Media\Editor\EditorOptions;
use App\Services\EditorLibraryService;
use PHPUnit\Framework\TestCase;

final class EditorEffectsOptionsTest extends TestCase
{
    public function testEffectsSurviveNormalizedJsonAndSignedForm(): void
    {
        $options=EditorOptions::fromArray(['brightness'=>25,'contrast'=>125,'font_italic'=>1]);
        self::assertSame(25,$options->toArray()['brightness']);
        self::assertSame($options->toArray(),EditorOptions::fromArray(json_decode(json_encode($options->toArray()),true))->toArray());
        $form=EditorOptions::fromForm(['brightness'=>'-25','contrast'=>'125']);
        self::assertSame(-25,$form->toArray()['brightness']);
        self::assertSame(125,$form->toArray()['contrast']);
        self::assertSame(-25,EditorLibraryService::options(['brightness'=>'-25'])->toArray()['brightness']);
        self::assertSame(0,EditorOptions::fromArray([])->toArray()['brightness']);
        self::assertSame(100,EditorOptions::defaults()['contrast']);
        self::assertContains('caption_offset_y',EditorOptions::integerFields());
    }

    /** @dataProvider invalidOptions */
    public function testMalformedOrUnboundedOptionsAreRejected(array $input,bool $form): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $form ? EditorOptions::fromForm($input) : EditorOptions::fromArray($input);
    }
    public function invalidOptions(): array
    {
        return [[['brightness'=>-101],false],[['unknown'=>1],false],[['motion'=>'none;movie=x'],false],
            [['brightness'=>'25'],false],[['brightness'=>'-25evil'],true],[['brightness'=>'1e2'],true],
            [['brightness'=>' 25'],true],[['brightness'=>'9999999999999999999999'],true],
            [['motion'=>'zoom_in'],false],[['outline_color'=>'red'],false],[['font_italic'=>2],false]];
    }

    public function testAllNumericBoundariesAreEnforcedInJsonAndForm(): void
    {
        $bounds=['font_italic'=>[0,1],'letter_spacing'=>[0,10],'caption_margin_x'=>[0,120],'caption_offset_y'=>[-120,120],
            'animation_duration_ms'=>[0,2000],'animation_out_duration_ms'=>[0,2000],'video_fade_in_ms'=>[0,3000],
            'video_fade_out_ms'=>[0,3000],'brightness'=>[-100,100],'contrast'=>[0,200],'saturation'=>[0,300],
            'blur'=>[0,20],'noise'=>[0,30],'vignette'=>[0,100],'zoom_percent'=>[100,150]];
        foreach ($bounds as $field=>[$min,$max]) {
            foreach ([$min,$max] as $value) {
                self::assertSame($value,EditorOptions::fromArray([$field=>$value])->toArray()[$field]);
                self::assertSame($value,EditorOptions::fromForm([$field=>(string)$value])->toArray()[$field]);
            }
            foreach ([$min-1,$max+1] as $value) {
                foreach ([false,true] as $form) {
                    try {
                        $form ? EditorOptions::fromForm([$field=>(string)$value]) : EditorOptions::fromArray([$field=>$value]);
                        self::fail($field.' accepted out of bounds value');
                    } catch (\InvalidArgumentException) { self::assertTrue(true); }
                }
            }
        }
    }
}
