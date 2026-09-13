<?php
declare(strict_types=1);
use App\Core\Csrf;
ob_start();
?>
<link rel="stylesheet" href="/assets/css/editor-library.css">
<div class="editor-library">
<header class="library-heading"><div><p class="eyebrow">Minha marca</p><h2>Seu visual, pronto para usar.</h2><p>Guarde suas fontes, cores, logo e chamada final. A marca fica privada na sua conta.</p></div><a class="button button-secondary" href="/templates">Ver templates</a></header>
<?php if ($error): ?><div class="library-message library-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($notice): ?><div class="library-message" role="status"><?= e($notice) ?></div><?php endif; ?>
<section class="library-panel" aria-labelledby="brand-logos"><div class="library-section-heading"><h3 id="brand-logos">Seus logos</h3><p><?= count($catalog['logos']) ?> de 5 usados</p></div>
<?php if ($catalog['logos'] === []): ?><p class="library-empty">Adicione um PNG transparente para assinar seus vídeos.</p><?php else: ?><div class="library-logos"><?php foreach ($catalog['logos'] as $logo): ?><figure><div class="library-logo-image"><img src="<?= e($logo['url']) ?>" alt="Logo <?= $logo['id'] ?>" loading="lazy"></div><figcaption>Logo <?= $logo['id'] ?> <small><?= $logo['width'] ?> × <?= $logo['height'] ?> px</small></figcaption></figure><?php endforeach; ?></div><?php endif; ?>
<form method="post" action="/marca/logo" enctype="multipart/form-data" class="library-upload"><input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="MAX_FILE_SIZE" value="2097152"><label>Arquivo PNG<input type="file" name="logo" accept="image/png,.png" required <?= count($catalog['logos']) >= 5 ? 'disabled' : '' ?> aria-describedby="logo-limits"></label><button class="button button-secondary" type="submit" <?= count($catalog['logos']) >= 5 ? 'disabled' : '' ?>>Enviar logo</button></form>
<p class="library-hint" id="logo-limits">PNG estático, até 2 MiB e 2048 × 2048 pixels. Até 5 logos; cada envio ocupa uma vaga permanente para preservar versões exportadas. Selecionar “Sem logo” desativa sua aplicação nos próximos valores salvos.</p></section>
<section class="library-panel" aria-labelledby="brand-defaults"><h3 id="brand-defaults">Padrões da marca</h3><p class="library-hint">Salve uma combinação para aplicar no editor. Estes valores não alteram cortes existentes.</p>
<form method="post" action="/marca"><input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">
<?php require __DIR__ . '/options.php'; ?>
<fieldset class="library-options"><legend>Templates favoritos</legend><?php if ($catalog['templates'] === []): ?><p class="library-hint">Crie um template para adicioná-lo aos favoritos.</p><?php endif; ?><div class="library-favorites"><?php foreach ($catalog['templates'] as $template): ?><label><input type="checkbox" name="favorites[]" value="<?= $template['id'] ?>" <?= in_array($template['id'], $form['favorites'], true) ? 'checked' : '' ?>> <span><?= e($template['name']) ?></span></label><?php endforeach; ?></div></fieldset>
<button class="button" type="submit">Salvar marca</button>
</form></section>
</div>
<?php $content = (string) ob_get_clean(); require __DIR__ . '/../layouts/app.php'; ?>
