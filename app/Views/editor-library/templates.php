<?php
declare(strict_types=1);
use App\Core\Csrf;
ob_start();
?>
<link rel="stylesheet" href="/assets/css/editor-library.css">
<div class="editor-library">
<header class="library-heading"><div><p class="eyebrow">Seu estúdio, seu estilo</p><h2>Uma identidade em cada corte.</h2><p>Comece com um preset ou salve sua própria combinação. Os valores são copiados quando você aplica um template.</p></div><a class="button button-secondary" href="/marca">Minha marca</a></header>
<?php if ($error): ?><div class="library-message library-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($notice): ?><div class="library-message" role="status"><?= e($notice) ?></div><?php endif; ?>
<section aria-labelledby="library-presets"><div class="library-section-heading"><h3 id="library-presets">Pontos de partida</h3><p>Prévia ilustrativa · a exportação define o resultado final.</p></div>
<div class="library-presets">
<?php foreach ($catalog['presets'] as $preset): ?>
<article class="library-preset"><div class="library-sample library-sample-<?= e($preset['category']) ?>" aria-hidden="true"><span class="library-sample-scene"></span><span class="library-sample-caption">Sua próxima<br><em>boa história.</em></span><span class="library-sample-tag">9:16</span></div><div class="library-preset-copy"><h4><?= e($preset['name']) ?></h4><p><?= e($preset['description']) ?></p><a href="/templates?preset=<?= e($preset['id']) ?>#template-form" class="text-link">Personalizar →</a></div></article>
<?php endforeach; ?>
</div></section>
<section class="library-panel" aria-labelledby="library-saved"><div class="library-section-heading"><h3 id="library-saved">Meus templates</h3><p><?= count($catalog['templates']) ?> de 50 usados</p></div>
<?php if ($catalog['templates'] === []): ?><p class="library-empty">Sua biblioteca começa aqui. Ajuste a aparência abaixo e salve o primeiro template.</p><?php else: ?>
<div class="library-saved-list">
<?php foreach ($catalog['templates'] as $template): ?>
<article class="library-saved-row"><div><h4><?= e($template['name']) ?></h4><p><?= e($template['aspect_ratio']) ?> · <?= e($template['options']['font_family']) ?> · <?= e($template['category']) ?></p></div><div class="library-actions"><a class="button button-secondary" href="/templates?edit=<?= $template['id'] ?>#template-form">Editar</a><form method="post" action="/templates"><input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="id" value="<?= $template['id'] ?>"><button class="button button-secondary" name="action" value="apply">Usar como padrão</button></form><form method="post" action="/templates"><input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="id" value="<?= $template['id'] ?>"><button class="library-remove" name="action" value="remove" aria-label="Excluir template <?= e($template['name']) ?>">Excluir</button></form></div></article>
<?php endforeach; ?></div><?php endif; ?>
<p class="library-hint">Usar como padrão copia o template para sua marca. Cortes já existentes permanecem com seus valores salvos.</p></section>
<section class="library-panel" id="template-form" aria-labelledby="template-form-heading"><div class="library-section-heading"><h3 id="template-form-heading"><?= $editing ? 'Editar template' : 'Criar template' ?></h3><?php if ($editing): ?><a class="text-link" href="/templates#template-form">Criar outro</a><?php endif; ?></div>
<form method="post" action="/templates" data-library-form><input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>"><?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>
<div class="library-fields"><label>Nome do template<input name="name" maxlength="80" required value="<?= e($form['name']) ?>" placeholder="Ex.: Podcast semanal"></label><label>Categoria<select name="category"><?php if (!in_array($form['category'], ['viral', 'podcast', 'clean', 'impact', 'custom'], true)): ?><option value="<?= e($form['category']) ?>" selected>Valor enviado inválido: <?= e($form['category']) ?></option><?php endif; ?><?php foreach (['viral'=>'Viral', 'podcast'=>'Podcast', 'clean'=>'Clean', 'impact'=>'Impacto', 'custom'=>'Personalizado'] as $value => $label): ?><option value="<?= e($value) ?>" <?= $form['category'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label></div>
<?php require __DIR__ . '/options.php'; ?>
<button class="button" type="submit"><?= $editing ? 'Salvar alterações' : 'Salvar template' ?></button>
</form></section>
</div>
<?php $content = (string) ob_get_clean(); require __DIR__ . '/../layouts/app.php'; ?>
