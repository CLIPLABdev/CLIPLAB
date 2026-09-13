<?php

declare(strict_types=1);

namespace App\Media\Editor;

use App\Media\Subtitles\SubtitleCue;
use InvalidArgumentException;

final class EditorOptions
{
    public const STYLES = ['none', 'minimal', 'viral', 'podcast', 'highlight', 'karaoke', 'custom'];
    public const POSITIONS = ['top', 'middle', 'bottom'];
    private array $options;

    private function __construct(array $options) { $this->options = $options; }

    private const INTEGER_LIMITS = ['font_size'=>[18,96], 'outline_width'=>[0,6], 'shadow_depth'=>[0,6],
        'logo_asset_id'=>[0,PHP_INT_MAX], 'logo_scale'=>[5,25], 'font_italic'=>[0,1], 'letter_spacing'=>[0,10],
        'caption_margin_x'=>[0,120], 'caption_offset_y'=>[-120,120], 'animation_duration_ms'=>[0,2000],
        'animation_out_duration_ms'=>[0,2000], 'video_fade_in_ms'=>[0,3000], 'video_fade_out_ms'=>[0,3000],
        'brightness'=>[-100,100], 'contrast'=>[0,200], 'saturation'=>[0,300], 'blur'=>[0,20], 'noise'=>[0,30],
        'vignette'=>[0,100], 'zoom_percent'=>[100,150]];

    public static function defaults(): array
    {
        return ['style' => 'none', 'position' => 'bottom', 'color' => '#FFFFFF', 'accent_color' => '#FACC15', 'font_size' => 44, 'title' => '', 'watermark' => '',
            'font_family'=>'Arial','background_color'=>'#151515','outline_width'=>2,'shadow_depth'=>0,'font_weight'=>'auto',
            'animation'=>'none','cta_text'=>'','logo_asset_id'=>0,'logo_position'=>'top_right','logo_scale'=>10,
            'font_italic'=>0,'letter_spacing'=>0,'caption_margin_x'=>0,'caption_offset_y'=>0,'outline_color'=>'auto',
            'background_mode'=>'auto','animation_duration_ms'=>150,'animation_out'=>'auto','animation_out_duration_ms'=>150,
            'video_fade_in_ms'=>0,'video_fade_out_ms'=>0,'brightness'=>0,'contrast'=>100,'saturation'=>100,'blur'=>0,
            'noise'=>0,'vignette'=>0,'zoom_percent'=>100,'motion'=>'none'];
    }

    public static function integerFields(): array { return array_keys(self::INTEGER_LIMITS); }

    public static function fromForm(array $input): self
    {
        foreach (self::INTEGER_LIMITS as $key=>[$min,$max]) {
            if (!array_key_exists($key,$input) || !is_string($input[$key])) continue;
            $raw=$input[$key];
            if (preg_match('/\A[+-]?[0-9]+\z/D',$raw)!==1) throw new InvalidArgumentException('Use um número inteiro válido.');
            $digits=ltrim(ltrim($raw,'+-'),'0');
            $maximum=(string)PHP_INT_MAX;
            if (strlen($digits)>strlen($maximum) || (strlen($digits)===strlen($maximum) && strcmp($digits,$maximum)>0)) {
                throw new InvalidArgumentException('Use um número inteiro válido.');
            }
            $input[$key]=(int)$raw;
        }
        return self::fromArray($input);
    }

    public static function fromArray(array $input): self
    {
        $defaults = self::defaults();
        if (array_diff(array_keys($input), array_keys($defaults)) !== []) {
            throw new InvalidArgumentException('As opções do editor são inválidas.');
        }
        $options = array_replace($defaults, $input);
        if (!in_array($options['style'], self::STYLES, true) || !in_array($options['position'], self::POSITIONS, true)
            || !is_int($options['font_size']) || $options['font_size'] < 18 || $options['font_size'] > 96) {
            throw new InvalidArgumentException('Escolha estilo, posição e tamanho de fonte válidos.');
        }
        foreach (['font_family'=>['Arial','Georgia','Verdana'],'font_weight'=>['auto','normal','bold'],
            'animation'=>['none','fade','pop'],'logo_position'=>['top_left','top_right','bottom_left','bottom_right'],
            'background_mode'=>['auto','none','box'],'animation_out'=>['auto','none','fade'],
            'motion'=>['none','zoom_in','zoom_out','pan_left','pan_right']] as $key=>$allowed) {
            if (!in_array($options[$key],$allowed,true)) throw new InvalidArgumentException('A aparência escolhida é inválida.');
        }
        foreach (self::INTEGER_LIMITS as $key=>[$min,$max]) {
            if (!is_int($options[$key]) || $options[$key]<$min || $options[$key]>$max) throw new InvalidArgumentException('A aparência escolhida é inválida.');
        }
        if ($options['motion']!=='none' && $options['zoom_percent']===100) throw new InvalidArgumentException('O movimento requer zoom maior que 100%.');
        foreach (['color', 'accent_color', 'background_color', 'outline_color'] as $key) {
            if ($key==='outline_color' && $options[$key]==='auto') continue;
            if (!is_string($options[$key]) || preg_match('/^#[0-9a-fA-F]{6}$/D', $options[$key]) !== 1) {
                throw new InvalidArgumentException('Escolha uma cor hexadecimal válida.');
            }
            $options[$key] = strtoupper($options[$key]);
        }
        foreach (['title' => 120, 'watermark' => 80, 'cta_text'=>120] as $key => $maximum) {
            if (!is_string($options[$key])) {
                throw new InvalidArgumentException('O título e a marca devem ser textos.');
            }
            if ($options[$key] !== '') {
                SubtitleCue::validateText($options[$key], $maximum, false);
            }
        }
        return new self($options);
    }

    public function style(): string { return $this->options['style']; }
    public function position(): string { return $this->options['position']; }
    public function color(): string { return $this->options['color']; }
    public function accentColor(): string { return $this->options['accent_color']; }
    public function fontSize(): int { return $this->options['font_size']; }
    public function title(): string { return $this->options['title']; }
    public function watermark(): string { return $this->options['watermark']; }
    public function fontFamily(): string { return $this->options['font_family']; }
    public function backgroundColor(): string { return $this->options['background_color']; }
    public function outlineWidth(): int { return $this->options['outline_width']; }
    public function shadowDepth(): int { return $this->options['shadow_depth']; }
    public function fontWeight(): string { return $this->options['font_weight']; }
    public function animation(): string { return $this->options['animation']; }
    public function ctaText(): string { return $this->options['cta_text']; }
    public function logoAssetId(): int { return $this->options['logo_asset_id']; }
    public function logoPosition(): string { return $this->options['logo_position']; }
    public function logoScale(): int { return $this->options['logo_scale']; }
    public function hasOverlays(): bool { return $this->style() !== 'none' || $this->title() !== '' || $this->watermark() !== '' || $this->ctaText() !== '' || $this->logoAssetId() > 0; }
    public function toArray(): array { return $this->options; }
}
