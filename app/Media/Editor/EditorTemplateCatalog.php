<?php
declare(strict_types=1);
namespace App\Media\Editor;

final class EditorTemplateCatalog
{
    public static function all(): array
    {
        $presets = [
            ['viral', 'Viral', 'Destaque direto para vídeos curtos.', ['style' => 'viral', 'font_size' => 52, 'font_weight' => 'bold']],
            ['podcast', 'Podcast', 'Legendas com caixa para conversas.', ['style' => 'podcast', 'background_color' => '#151515']],
            ['clean', 'Clean', 'Texto discreto, sem efeitos.', ['style' => 'minimal', 'font_weight' => 'normal', 'outline_width' => 1]],
            ['impact', 'Impacto', 'Palavras em destaque e contorno forte.', ['style' => 'highlight', 'font_size' => 56, 'font_weight' => 'bold', 'outline_width' => 4]],
            ['custom', 'Personalizado', 'Uma base para a sua identidade.', ['style' => 'custom']],
        ];
        return array_map(static fn (array $preset): array => ['id' => $preset[0], 'name' => $preset[1], 'description' => $preset[2],
            'category' => $preset[0], 'aspect_ratio' => '9:16', 'options' => EditorOptions::fromArray($preset[3])->toArray()], $presets);
    }
}
