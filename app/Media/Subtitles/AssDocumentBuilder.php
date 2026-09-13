<?php

declare(strict_types=1);

namespace App\Media\Subtitles;

use App\Media\Editor\EditorOptions;
use App\Media\OverlaySafeZone;
use InvalidArgumentException;

final class AssDocumentBuilder
{
    public static function build(?Transcript $transcript, EditorOptions $options, int $width, int $height, int $durationMs): string
    {
        if ($width < 2 || $height < 2 || $width > 8192 || $height > 8192 || $durationMs < 1 || $durationMs > 180000
            || ($transcript !== null && $transcript->durationMs() !== $durationMs)) {
            throw new InvalidArgumentException('As dimensões da legenda são inválidas.');
        }
        $alignment = ['top' => 8, 'middle' => 5, 'bottom' => 2][$options->position()];
        $fontSize = max(12, (int) round($options->fontSize() * min($width, $height) / 720));
        $legacyMargin = max(16, (int) round(min($width, $height) * 0.06));
        $insets = OverlaySafeZone::textInsets($width,$height);
        $captionInsets = OverlaySafeZone::textInsetsForLogo(
            $width,$height,$options->logoAssetId(),$options->logoPosition(),$options->logoScale(),$options->position()
        );
        $titleInsets = OverlaySafeZone::textInsetsForLogo(
            $width,$height,$options->logoAssetId(),$options->logoPosition(),$options->logoScale(),'top'
        );
        $brandInsets = OverlaySafeZone::textInsetsForLogo(
            $width,$height,$options->logoAssetId(),$options->logoPosition(),$options->logoScale(),'bottom'
        );
        $captionMargin = match ($options->position()) {
            'top' => $captionInsets['top'],
            'bottom' => $captionInsets['bottom'],
            default => $legacyMargin,
        };
        $o=$options->toArray();
        $reference=min($width,$height)/720;
        $extraMargin=(int)round($o['caption_margin_x']*$reference);
        if ($extraMargin>0) {
            $extraMargin=min($extraMargin,max(0,(int)floor(($width-$captionInsets['left']-$captionInsets['right']-min($width,2*$fontSize))/2)));
            $captionInsets['left']+=$extraMargin;
            $captionInsets['right']+=$extraMargin;
        }
        $captionPosition='';
        if ($o['caption_offset_y']!==0) {
            $offset=(int)round($o['caption_offset_y']*$reference);
            if ($options->position()==='middle') {
                $x=(int)round(($captionInsets['left']+$width-$captionInsets['right'])/2);
                $y=max(min($fontSize,(int)floor($height/2)),min($height-$fontSize,(int)round($height/2)+$offset));
                $captionPosition='{\\pos('.$x.','.$y.')}';
            } else {
                $captionMargin=max(0,min(max(0,$height-$fontSize),$captionMargin+($options->position()==='top' ? $offset : -$offset)));
            }
        }
        $primary = self::color($options->color());
        $accent = self::color($options->accentColor());
        $bold = $options->fontWeight() === 'bold' || ($options->fontWeight() === 'auto' && in_array($options->style(), ['viral', 'highlight', 'karaoke'], true)) ? -1 : 0;
        $outline = $options->outlineWidth();
        $shadow = $options->shadowDepth();
        $font = $options->fontFamily();
        $background = self::color($options->backgroundColor());
        $border = $options->style() === 'podcast' ? 3 : 1;
        $document = "[Script Info]\nScriptType: v4.00+\nPlayResX: $width\nPlayResY: $height\nWrapStyle: 0\nScaledBorderAndShadow: yes\n\n[V4+ Styles]\n"
            . "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n";
        $document .= "Style: Caption,$font,$fontSize,$primary,$accent,$background,&H80000000,$bold,0,0,0,100,100,0,0,$border,$outline,$shadow,$alignment,{$captionInsets['left']},{$captionInsets['right']},$captionMargin,1\n";
        $document .= "Style: Title,$font,$fontSize,$primary,$accent,$background,&H80000000,-1,0,0,0,100,100,0,0,1,$outline,$shadow,8,{$titleInsets['left']},{$titleInsets['right']},{$titleInsets['top']},1\n";
        $document .= "Style: CTA,$font,$fontSize,$primary,$accent,$background,&H80000000,-1,0,0,0,100,100,0,0,3,$outline,$shadow,5,{$insets['left']},{$insets['right']},$legacyMargin,1\n";
        $brandSize = max(12, (int) round($fontSize * 0.6));
        $document .= "Style: Brand,$font,$brandSize,$primary,$accent,$background,&H80000000,0,0,0,0,100,100,0,0,1,$outline,$shadow,3,{$brandInsets['left']},{$brandInsets['right']},{$brandInsets['bottom']},1\n\n[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";
        // Preserve the legacy style document byte-for-byte for neutral options.
        // A separate box layer allows explicit background and outline colors to coexist.
        $boxed=[];
        $boxStyles='';
        $document=preg_replace_callback('/^Style: (.+)$/m',static function (array $match) use ($o,$background,&$boxed,&$boxStyles): string {
            $s=explode(',',$match[1]);
            if ($o['font_weight']!=='auto') $s[7]=$o['font_weight']==='bold' ? '-1' : '0';
            $s[8]=$o['font_italic']===1 ? '-1' : '0';
            $s[13]=(string)$o['letter_spacing'];
            $border=$o['background_mode']==='auto' ? (int)$s[15] : ($o['background_mode']==='box' ? 3 : 1);
            if ($o['outline_color']!=='auto') $s[5]=self::color($o['outline_color']);
            if ($border===3 && ($o['background_mode']==='box' || $o['outline_color']!=='auto')) {
                $box=$s;
                $box[0].='Box';
                $box[3]='&HFF'.substr($s[3],4); $box[4]='&HFF'.substr($s[4],4);
                $box[5]=$background; $box[15]='3'; $box[17]='0';
                $boxStyles.='Style: '.implode(',',$box)."\n";
                $boxed[$s[0]]=true;
                $border=1;
            }
            $s[15]=(string)$border;
            return 'Style: '.implode(',',$s);
        },$document);
        if ($boxStyles!=='') $document=str_replace("\n[Events]",$boxStyles."\n[Events]",$document);
        $event=static function (int $layer,int $start,int $end,string $style,string $text) use ($boxed): string {
            if ($boxed!==[]) $layer*=2;
            $box=isset($boxed[$style]) ? self::event($layer,$start,$end,$style.'Box',$text) : '';
            return $box.self::event($boxed!==[] ? $layer+1 : $layer,$start,$end,$style,$text);
        };
        if ($transcript !== null && $options->style() !== 'none') {
            foreach ($transcript->cues() as $cue) {
                $text = self::escape($cue->text());
                if (in_array($options->style(), ['highlight', 'karaoke'], true) && $cue->words() !== []) {
                    $text = '';
                    $cursor = intdiv($cue->startMs(), 10);
                    foreach ($cue->words() as $index => $word) {
                        $start = intdiv($word['start_ms'], 10);
                        $end = max($start + 1, intdiv($word['end_ms'], 10));
                        if ($start > $cursor) {
                            $text .= '{\k' . ($start - $cursor) . '}';
                        }
                        $tag = $options->style() === 'highlight' ? '\k' : '\kf';
                        $text .= ($index > 0 ? ' ' : '') . '{' . $tag . ($end - $start) . '}' . self::escape($word['text']);
                        $cursor = $end;
                    }
                }
                $document .= $event(0, $cue->startMs(), $cue->endMs(), 'Caption', $captionPosition . self::animation($o,$cue->endMs()-$cue->startMs()) . $text);
            }
        }
        if ($options->title() !== '') {
            $document .= $event(1, 0, $durationMs, 'Title', self::escape($options->title()));
        }
        if ($options->watermark() !== '') {
            $document .= $event(2, 0, $durationMs, 'Brand', self::escape($options->watermark()));
        }
        if ($options->ctaText() !== '') {
            $document .= $event(3, max(0, $durationMs - 5000), $durationMs, 'CTA', self::animation($o,min(5000,$durationMs)) . self::escape($options->ctaText()));
        }
        return $document;
    }

    private static function escape(string $text): string
    {
        // ASS has no universally reliable literal-brace escape across libass versions.
        // Use visually similar Unicode punctuation; never concatenate user override syntax.
        return str_replace(["\\", "{", "}", "\n"], ["＼", "｛", "｝", '\N'], $text);
    }

    private static function animation(array $o,int $durationMs): string
    {
        $in=min($o['animation_duration_ms'],$durationMs);
        $out=($o['animation_out']==='fade' || ($o['animation_out']==='auto' && $o['animation']==='fade'))
            ? min($o['animation_out_duration_ms'],$durationMs) : 0;
        $tags='';
        if ($o['animation']==='pop' && $in>0) $tags='{\\fscx85\\fscy85\\t(0,'.$in.',\\fscx100\\fscy100)}';
        $fadeIn=$o['animation']==='fade' ? $in : 0;
        if ($fadeIn>0 || $out>0) $tags.='{\\fad('.$fadeIn.','.$out.')}';
        return $tags;
    }

    private static function color(string $rgb): string
    {
        return '&H00' . substr($rgb, 5, 2) . substr($rgb, 3, 2) . substr($rgb, 1, 2);
    }

    private static function event(int $layer, int $start, int $end, string $style, string $text): string
    {
        return "Dialogue: $layer," . self::timestamp($start) . ',' . self::timestamp($end) . ",$style,,0,0,0,,$text\n";
    }

    private static function timestamp(int $ms): string
    {
        return sprintf('%d:%02d:%02d.%02d', intdiv($ms, 3600000), intdiv($ms, 60000) % 60, intdiv($ms, 1000) % 60, intdiv($ms, 10) % 100);
    }
}
