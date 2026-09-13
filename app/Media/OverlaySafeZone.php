<?php

declare(strict_types=1);

namespace App\Media;

use InvalidArgumentException;

final class OverlaySafeZone
{
    /** @return array{left:int,right:int,top:int,bottom:int} */
    public static function textInsets(int $width,int $height): array
    {
        self::validate($width,$height);
        if ($height>$width) {
            $side=max(16,(int)round($width*0.06));
            return [
                'left'=>$side,
                'right'=>$side,
                'top'=>max(16,(int)round($height*0.10)),
                'bottom'=>max(16,(int)round($height*0.18)),
            ];
        }
        return self::uniform(max(16,(int)round(min($width,$height)*0.06)));
    }

    /** @return array{left:int,right:int,top:int,bottom:int} */
    public static function logoInsets(int $width,int $height): array
    {
        self::validate($width,$height);
        if ($height>$width) return self::textInsets($width,$height);
        return self::uniform(max(8,(int)round(min($width,$height)*0.03)));
    }

    /** @return array{left:int,right:int,top:int,bottom:int} */
    public static function textInsetsForLogo(
        int $width,
        int $height,
        int $logoAssetId,
        string $logoPosition,
        int $logoScale,
        string $band
    ): array {
        $insets=self::textInsets($width,$height);
        if ($logoAssetId<0 || !in_array($logoPosition,['top_left','top_right','bottom_left','bottom_right'],true)
            || $logoScale<5 || $logoScale>25 || !in_array($band,['top','middle','bottom'],true)) {
            throw new InvalidArgumentException('Overlay logo reservation is invalid.');
        }
        if ($height<=$width || $logoAssetId===0 || $band==='middle'
            || !str_starts_with($logoPosition,$band.'_')) {
            return $insets;
        }
        $side=str_ends_with($logoPosition,'left') ? 'left' : 'right';
        $logoInsets=self::logoInsets($width,$height);
        $maximumLogoWidth=max(1,(int)floor($width*$logoScale/100));
        $gap=max(8,(int)round(min($width,$height)*0.02));
        $insets[$side]=max($insets[$side],$logoInsets[$side]+$maximumLogoWidth+$gap);
        return $insets;
    }

    /** @return array{left:int,right:int,top:int,bottom:int} */
    private static function uniform(int $margin): array
    {
        return ['left'=>$margin,'right'=>$margin,'top'=>$margin,'bottom'=>$margin];
    }

    private static function validate(int $width,int $height): void
    {
        if ($width<2 || $height<2 || $width>8192 || $height>8192) {
            throw new InvalidArgumentException('Overlay dimensions are invalid.');
        }
    }
}
