<?php

declare(strict_types=1);

use App\Core\Csrf;

/** @var list<array<string,mixed>> $testimonials */
/** @var array<string,string> $errors */
$errors = is_array($errors ?? null) ? $errors : [];
$errorAttributes = static function (int $slot, string $field) use ($errors): string {
    $key = $slot . '.' . $field;
    if (!isset($errors[$key])) {
        return '';
    }
    return ' aria-invalid="true" aria-describedby="testimonial-' . $slot . '-' . $field . '-error"';
};
$fieldError = static function (int $slot, string $field) use ($errors): string {
    $key = $slot . '.' . $field;
    if (!isset($errors[$key])) {
        return '';
    }
    return '<p class="admin-flash admin-flash-error" id="testimonial-' . $slot . '-' . $field . '-error" role="alert">' . e($errors[$key]) . '</p>';
};
$bySlot = [];
foreach ($testimonials as $testimonial) {
    $slot = (int) ($testimonial['slot'] ?? 0);
    if ($slot >= 1 && $slot <= 3) {
        $bySlot[$slot] = $testimonial;
    }
}
ob_start();
?>
<section class="admin-page-head"><div><p class="admin-eyebrow">Prova social verificável</p><h2>Conteúdo público</h2><p>Mantenha até três relatos reais. Nada é publicado sem autorização confirmada e a opção de publicação marcada.</p></div></section>
<form method="post" action="/admin/conteudo">
    <input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">
    <?php for ($index = 0; $index < 3; $index++): $slot = $index + 1; $item = $bySlot[$slot] ?? []; ?>
        <section class="admin-panel">
            <div class="admin-panel-head"><div><p class="admin-eyebrow">Relato <?= $slot ?></p><h2><?= $item === [] ? 'Espaço disponível' : e((string) ($item['name'] ?? 'Relato')) ?></h2></div></div>
            <div class="admin-form-grid">
                <label>Nome<input name="testimonials[<?= $index ?>][name]" maxlength="100" value="<?= e((string) ($item['name'] ?? '')) ?>"<?= $errorAttributes($slot, 'name') ?>><?= $fieldError($slot, 'name') ?></label>
                <label>Contexto<input name="testimonials[<?= $index ?>][context]" maxlength="160" value="<?= e((string) ($item['context'] ?? '')) ?>" placeholder="Ex.: criadora, educador, podcast"<?= $errorAttributes($slot, 'context') ?>><?= $fieldError($slot, 'context') ?></label>
                <label class="admin-field-wide">Citação<textarea name="testimonials[<?= $index ?>][quote]" maxlength="600" rows="4"<?= $errorAttributes($slot, 'quote') ?>><?= e((string) ($item['quote'] ?? '')) ?></textarea><?= $fieldError($slot, 'quote') ?></label>
                <label>Resultado verificado (opcional)<input name="testimonials[<?= $index ?>][result]" maxlength="160" value="<?= e((string) ($item['result'] ?? '')) ?>"<?= $errorAttributes($slot, 'result') ?>><?= $fieldError($slot, 'result') ?></label>
                <label>Fonte HTTPS (opcional)<input type="url" name="testimonials[<?= $index ?>][source_url]" maxlength="255" pattern="https://.*" value="<?= e((string) ($item['source_url'] ?? '')) ?>"<?= $errorAttributes($slot, 'source_url') ?>><?= $fieldError($slot, 'source_url') ?></label>
            </div>
            <div class="admin-checks">
                <label><input type="checkbox" name="testimonials[<?= $index ?>][authorization_confirmed]" value="1"<?= ($item['authorization_confirmed'] ?? false) ? ' checked' : '' ?><?= $errorAttributes($slot, 'authorization_confirmed') ?>> Confirmo que existe autorização para exibir publicamente este relato e seus dados.</label>
                <?= $fieldError($slot, 'authorization_confirmed') ?>
                <label><input type="checkbox" name="testimonials[<?= $index ?>][published]" value="1" <?= ($item['published'] ?? false) ? 'checked' : '' ?>> Publicar na landing page</label>
            </div>
            <p class="admin-context">Ao usar este espaço, nome, contexto e citação são obrigatórios. Limpe todos os campos e desmarque as opções para remover o relato.</p>
        </section>
    <?php endfor; ?>
    <button class="admin-button" type="submit">Salvar conteúdo público</button>
</form>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/../layouts/admin.php';
