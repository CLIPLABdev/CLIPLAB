<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Media\RenderedDurationValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RenderedDurationValidatorTest extends TestCase
{
    /** @dataProvider matchingDurations */
    public function testAcceptsOnlySmallFrameAndContainerQuantization(float $requested,string $video,string $container,string $fps): void
    {
        RenderedDurationValidator::validate(self::metadata($video,$container,$fps),$requested);
        self::assertTrue(true,'Valid frame-granularity duration is accepted.');
    }

    public function matchingDurations(): iterable
    {
        yield 'fractional EOF'=>[45.011,'44.978267','45.011634','30000/1001'];
        yield 'subsecond video for one second request'=>[1.0,'0.967','1.020','30000/1001'];
        yield 'maximum drift at low fps'=>[1.0,'0.850','1.150','10/1'];
        yield 'minimum drift at high fps'=>[1.0,'0.950','1.050','120/1'];
    }

    /** @dataProvider rejectedDurations */
    public function testRejectsMissingOrMateriallyDifferentVideoAndContainer(array $metadata,float $requested=1.0): void
    {
        $this->expectException(InvalidArgumentException::class);
        RenderedDurationValidator::validate($metadata,$requested);
    }

    public function rejectedDurations(): iterable
    {
        yield 'EOF short'=>[self::metadata('44.978267','45.011634','30000/1001'),46.0];
        yield 'video early EOF despite audio'=>[self::metadata('0.600','1.000')];
        yield 'container short'=>[self::metadata('1.000','0.600')];
        yield 'video overshoot'=>[self::metadata('1.400','1.000')];
        yield 'container overshoot'=>[self::metadata('1.000','1.400')];
        yield 'low fps cannot permit second drift'=>[self::metadata('0.151','1.000','1/1')];
        yield 'missing video'=>[['format'=>['duration'=>'1'],'streams'=>[['codec_type'=>'audio','duration'=>'1']]]];
        yield 'missing container'=>[['streams'=>[['codec_type'=>'video','duration'=>'1','avg_frame_rate'=>'30/1']]]];
        foreach (['0/0','30/0','-30/1','NaN','1e300/1',str_repeat('9',100).'/1'] as $fps) yield 'invalid fps '.$fps=>[self::metadata('1','1',$fps)];
        foreach (['NaN','INF','0','-1','86400.001',str_repeat('9',100)] as $duration) yield 'invalid duration '.$duration=>[self::metadata($duration,'1')];
    }

    private static function metadata(string $video,string $container,string $fps='30/1'): array
    {
        return ['format'=>['duration'=>$container],'streams'=>[['codec_type'=>'audio','duration'=>$container],['codec_type'=>'video','duration'=>$video,'avg_frame_rate'=>$fps]]];
    }
}
