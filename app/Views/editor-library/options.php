<?php
declare(strict_types=1);
$options = $form['options'];
$selects = [
    'style' => ['Estilo de legenda', ['none'=>'Sem legenda', 'minimal'=>'Minimalista', 'viral'=>'Viral', 'podcast'=>'Podcast', 'highlight'=>'Destaque', 'karaoke'=>'Karaokê', 'custom'=>'Personalizado']],
    'position' => ['Posição da legenda', ['top'=>'Topo', 'middle'=>'Centro', 'bottom'=>'Base']],
    'font_family' => ['Fonte', ['Arial'=>'Arial', 'Georgia'=>'Georgia', 'Verdana'=>'Verdana']],
    'font_weight' => ['Peso da fonte', ['auto'=>'Automático do estilo', 'normal'=>'Normal', 'bold'=>'Negrito']],
    'animation' => ['Animação', ['none'=>'Nenhuma', 'fade'=>'Fade', 'pop'=>'Pop']],
    'logo_position' => ['Posição do logo', ['top_left'=>'Superior esquerdo', 'top_right'=>'Superior direito', 'bottom_left'=>'Inferior esquerdo', 'bottom_right'=>'Inferior direito']],
];
?>
<fieldset class="library-options"><legend>Aparência</legend>
<link rel="stylesheet" href="/assets/css/editor-effects.css">
<div class="library-fields">
<label>Proporção<select name="aspect_ratio"><?php if (!in_array($form['aspect_ratio'], ['9:16', '1:1', '16:9'], true)): ?><option value="<?= e($form['aspect_ratio']) ?>" selected>Valor enviado inválido: <?= e($form['aspect_ratio']) ?></option><?php endif; ?><option value="9:16" <?= $form['aspect_ratio'] === '9:16' ? 'selected' : '' ?>>Vertical · 9:16</option><option value="1:1" <?= $form['aspect_ratio'] === '1:1' ? 'selected' : '' ?>>Quadrado · 1:1</option><option value="16:9" <?= $form['aspect_ratio'] === '16:9' ? 'selected' : '' ?>>Horizontal · 16:9</option></select></label>
<?php foreach ($selects as $key => [$label, $choices]): ?>
<label><?= e($label) ?><select name="options[<?= e($key) ?>]">
<?php if (!array_key_exists($options[$key], $choices)): ?><option value="<?= e($options[$key]) ?>" selected>Valor enviado inválido: <?= e($options[$key]) ?></option><?php endif; ?>
<?php foreach ($choices as $value => $text): ?><option value="<?= e($value) ?>" <?= $options[$key] === $value ? 'selected' : '' ?>><?= e($text) ?></option><?php endforeach; ?>
</select></label>
<?php endforeach; ?>
<?php foreach (['font_size'=>['Tamanho da fonte',18,96], 'outline_width'=>['Contorno',0,6], 'shadow_depth'=>['Sombra',0,6], 'logo_scale'=>['Largura do logo (%)',5,25]] as $key => [$label,$min,$max]): ?>
<label><?= e($label) ?><input type="<?= preg_match('/^-?[0-9]+$/D', (string) $options[$key]) ? 'number' : 'text' ?>" name="options[<?= e($key) ?>]" min="<?= $min ?>" max="<?= $max ?>" step="1" value="<?= e((string) $options[$key]) ?>" required></label>
<?php endforeach; ?>
<?php foreach (['color'=>'Cor do texto','accent_color'=>'Cor de destaque','background_color'=>'Fundo da legenda'] as $key => $label): ?>
<label><?= e($label) ?><input type="<?= preg_match('/^#[0-9a-fA-F]{6}$/D', $options[$key]) ? 'color' : 'text' ?>" name="options[<?= e($key) ?>]" value="<?= e($options[$key]) ?>"></label>
<?php endforeach; ?>
<label>Logo<select name="options[logo_asset_id]"><?php if (!in_array((string) $options['logo_asset_id'], array_merge(['0'], array_map('strval', array_column($catalog['logos'], 'id'))), true)): ?><option value="<?= e((string) $options['logo_asset_id']) ?>" selected>Logo enviado indisponível</option><?php endif; ?><option value="0" <?= (string) $options['logo_asset_id'] === '0' ? 'selected' : '' ?>>Sem logo</option><?php foreach ($catalog['logos'] as $logo): ?><option value="<?= $logo['id'] ?>" <?= (string) $options['logo_asset_id'] === (string) $logo['id'] ? 'selected' : '' ?>>Logo <?= $logo['id'] ?> · <?= $logo['width'] ?> × <?= $logo['height'] ?></option><?php endforeach; ?></select></label>
</div>
<div class="library-fields library-fields-text">
<?php foreach (['title'=>['Título na tela',120], 'watermark'=>['Marca em texto',80], 'cta_text'=>['Chamada final (CTA)',120]] as $key => [$label,$max]): ?>
<label><?= e($label) ?><input type="text" name="options[<?= e($key) ?>]" maxlength="<?= $max ?>" value="<?= e($options[$key]) ?>"></label>
<?php endforeach; ?>
</div><p class="library-hint">A chamada final aparece nos últimos 5 segundos, ou durante todo o corte se ele for mais curto. A caixa de fundo depende do estilo. Nenhum template altera intervalo, legendas ou reenquadramento.</p>
</fieldset>
<?php $effectValues=$options; $effectNested=true; require __DIR__.'/effects.php'; ?>
