<?php
declare(strict_types=1);
// Shared controls: the library nests option names; the clip editor submits flat names.
$effectValues = array_replace(\App\Media\Editor\EditorOptions::defaults(), $effectValues);
$effectGroups = [
    'Tipografia e posição' => [
        'font_italic'=>['Inclinação',['0'=>'Normal','1'=>'Itálico']],
        'letter_spacing'=>['Espaçamento entre letras',0,10],
        'caption_margin_x'=>['Margem lateral adicional (ref. 720 px)',0,120],
        'caption_offset_y'=>['Deslocamento vertical da legenda (ref. 720 px)',-120,120],
        'outline_color'=>['Cor do contorno (auto ou #RRGGBB)'],
        'background_mode'=>['Caixa de fundo',['auto'=>'Do estilo','none'=>'Sem caixa','box'=>'Com caixa']],
    ],
    'Animação das legendas e chamada final' => [
        'animation_duration_ms'=>['Duração da entrada (ms)',0,2000],
        'animation_out'=>['Saída do texto',['auto'=>'Do estilo de animação','none'=>'Sem animação','fade'=>'Fade']],
        'animation_out_duration_ms'=>['Duração da saída (ms)',0,2000],
    ],
    'Imagem do vídeo' => [
        'brightness'=>['Brilho (0 = original)',-100,100], 'contrast'=>['Contraste (%)',0,200],
        'saturation'=>['Saturação (%)',0,300], 'blur'=>['Desfoque (0 = nenhum)',0,20],
        'noise'=>['Ruído (0 = nenhum)',0,30], 'vignette'=>['Vinheta (%)',0,100],
    ],
    'Movimento e entrada/saída do vídeo' => [
        'zoom_percent'=>['Zoom (%)',100,150],
        'motion'=>['Movimento',['none'=>'Sem movimento','zoom_in'=>'Aproximar','zoom_out'=>'Afastar','pan_left'=>'Mover para a esquerda','pan_right'=>'Mover para a direita']],
        'video_fade_in_ms'=>['Entrada do vídeo em fade (ms)',0,3000],
        'video_fade_out_ms'=>['Saída do vídeo em fade (ms)',0,3000],
    ],
];
?>
<?php foreach ($effectGroups as $effectHeading=>$effectControls): ?>
<fieldset class="editor-effects-group"><legend><?= e($effectHeading) ?></legend><div class="editor-effects-fields">
<?php foreach ($effectControls as $effectKey=>$effectDefinition):
    $effectName=$effectNested ? 'options['.$effectKey.']' : $effectKey;
    $effectId=($effectNested ? 'library-' : 'editor-').$effectKey;
    $effectValue=(string)$effectValues[$effectKey];
    $effectAttrs=isset($attributes) && !$effectNested ? $attributes($effectKey) : '';
?>
<div><label for="<?= e($effectId) ?>"><?= e($effectDefinition[0]) ?></label>
<?php if (isset($effectDefinition[1]) && is_array($effectDefinition[1])): ?>
<select id="<?= e($effectId) ?>" name="<?= e($effectName) ?>"<?= $effectAttrs ?>>
<?php if (!array_key_exists($effectValue,$effectDefinition[1])): ?><option selected value="<?= e($effectValue) ?>">Valor inválido: <?= e($effectValue) ?></option><?php endif; ?>
<?php foreach ($effectDefinition[1] as $effectChoice=>$effectLabel): ?><option value="<?= e((string)$effectChoice) ?>"<?= $effectValue===(string)$effectChoice ? ' selected' : '' ?>><?= e($effectLabel) ?></option><?php endforeach; ?>
</select>
<?php elseif (isset($effectDefinition[2])): ?>
<input id="<?= e($effectId) ?>" name="<?= e($effectName) ?>" type="<?= preg_match('/^[+-]?[0-9]+$/D',$effectValue) ? 'number' : 'text' ?>" min="<?= $effectDefinition[1] ?>" max="<?= $effectDefinition[2] ?>" step="1" required value="<?= e($effectValue) ?>"<?= $effectAttrs ?>>
<?php else: ?>
<input id="<?= e($effectId) ?>" name="<?= e($effectName) ?>" type="text" maxlength="7" pattern="auto|#[0-9a-fA-F]{6}" required value="<?= e($effectValue) ?>"<?= $effectAttrs ?>>
<?php endif; ?></div>
<?php endforeach; ?>
</div></fieldset>
<?php endforeach; ?>
<p class="editor-help">Movimento exige zoom acima de 100%. Os efeitos atingem o vídeo e preservam a legibilidade dos textos e o áudio. Os fades são a entrada e a saída deste corte. Margens e deslocamento ajustam somente a legenda.</p>
