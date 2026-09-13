<?php
declare(strict_types=1);
namespace App\Media\Editor;

use InvalidArgumentException;

final class VideoEffectsFilterBuilder
{
    /** Only validated numbers and fixed expressions may enter a filtergraph. */
    public function build(EditorOptions $options,int $width,int $height,int $durationMs): string
    {
        if ($width<2 || $height<2 || $durationMs<1 || $durationMs>180000) {
            throw new InvalidArgumentException('As dimensões e a duração dos efeitos são inválidas.');
        }
        $o=$options->toArray();
        // The original neutral path supports source sizes beyond the effects limit.
        if ($o['zoom_percent']===100 && $o['motion']==='none' && $o['brightness']===0
            && $o['contrast']===100 && $o['saturation']===100 && $o['blur']===0 && $o['noise']===0
            && $o['vignette']===0 && $o['video_fade_in_ms']===0 && $o['video_fade_out_ms']===0) {
            return '';
        }
        if ($width>8192 || $height>8192) {
            throw new InvalidArgumentException('As dimensões e a duração dos efeitos são inválidas.');
        }
        $filters=[];
        if ($o['zoom_percent']>100) {
            $zoom=self::number($o['zoom_percent']/100);
            if ($o['motion']==='none') {
                $cropWidth=max(2,2*(int)floor($width*100/$o['zoom_percent']/2));
                $cropHeight=max(2,2*(int)floor($height*100/$o['zoom_percent']/2));
                $filters[]="crop=$cropWidth:$cropHeight:(iw-ow)/2:(ih-oh)/2,scale=$width:$height:flags=lanczos";
            } else {
                $lastFrame=max(1,(int)ceil($durationMs*30/1000)-1);
                $progress="min(1,on/$lastFrame)";
                $delta=self::number(($o['zoom_percent']-100)/100);
                $z=match ($o['motion']) {
                    'zoom_in'=>"1+$delta*$progress",
                    'zoom_out'=>"$zoom-$delta*$progress",
                    default=>$zoom,
                };
                $x=match ($o['motion']) {
                    'pan_left'=>"(iw-iw/zoom)*(1-$progress)",
                    'pan_right'=>"(iw-iw/zoom)*$progress",
                    default=>'iw/2-iw/zoom/2',
                };
                $filters[]="fps=30,setpts=PTS-STARTPTS,zoompan=z='$z':x='$x':y='ih/2-ih/zoom/2':d=1:s={$width}x{$height}:fps=30";
            }
        }
        if ($o['brightness']!==0 || $o['contrast']!==100 || $o['saturation']!==100) {
            $filters[]='eq=brightness='.self::number($o['brightness']/100).':contrast='.self::number($o['contrast']/100).':saturation='.self::number($o['saturation']/100);
        }
        if ($o['blur']>0) $filters[]='gblur=sigma='.$o['blur'];
        if ($o['noise']>0) $filters[]='noise=alls='.$o['noise'].':allf=t:all_seed=1';
        if ($o['vignette']>0) $filters[]='vignette=angle='.self::number(M_PI*$o['vignette']/400);
        $fadeIn=min($o['video_fade_in_ms'],$durationMs/2);
        $fadeOut=min($o['video_fade_out_ms'],$durationMs/2);
        if ($fadeIn>0) $filters[]='fade=t=in:st=0:d='.self::number($fadeIn/1000);
        if ($fadeOut>0) $filters[]='fade=t=out:st='.self::number(($durationMs-$fadeOut)/1000).':d='.self::number($fadeOut/1000);
        return implode(',',$filters);
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value,6,'.',''),'0'),'.');
    }
}
