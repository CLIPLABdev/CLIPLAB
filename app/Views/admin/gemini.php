<?php

declare(strict_types=1);

use App\Core\Csrf;

/** @var array<string,mixed> $settings */
$csrf = Csrf::token();
ob_start();
?>
<section class="admin-page-head"><div><p class="admin-eyebrow">Integração protegida</p><h2>Gemini</h2><p>A chave completa nunca é enviada ao navegador. Deixe o campo em branco para preservar a atual.</p></div></section>
<section class="admin-panel"><div class="admin-panel-head"><div><p class="admin-eyebrow">Estado efetivo</p><h2>Credencial e modelo</h2></div><span class="admin-badge admin-badge-<?= ($settings['has_effective_key'] ?? false) ? 'active' : 'error' ?>"><?= ($settings['has_effective_key'] ?? false) ? 'configurada' : 'ausente' ?></span></div>
    <div class="admin-secret-state"><strong>Chave</strong><p><?= e((string) ($settings['api_key_mask'] ?? 'Não configurada')) ?></p><small>Origem: <?= e((string) ($settings['source'] ?? 'missing')) ?><?= ($settings['configuration_error'] ?? false) ? ' · configuração inválida' : '' ?></small></div>
    <?php if (($settings['has_database_key'] ?? false) && ($settings['configuration_error'] ?? false)): ?>
        <p class="admin-flash admin-flash-error" id="gemini-encryption-help">Existe uma chave Gemini cifrada que não pode ser aberta. Restaure a APP_ENCRYPTION_KEY original na web e no worker; gerar outra chave não recupera esse segredo.</p>
    <?php elseif (!($settings['encryption_ready'] ?? false)): ?>
        <p class="admin-flash admin-flash-error" id="gemini-encryption-help">O servidor ainda não tem uma APP_ENCRYPTION_KEY válida. Antes de salvar uma nova chave aqui, execute <code>php bin/generate-encryption-key.php</code> no terminal privado do servidor.</p>
    <?php endif; ?>
    <form class="admin-gemini-form" method="post" action="/admin/configuracoes/gemini" data-admin-gemini-save data-pending-label="Salvando…">
        <input type="hidden" name="_token" value="<?= e($csrf) ?>">
        <label>Modelo<input required maxlength="128" pattern="[A-Za-z0-9][A-Za-z0-9._-]*" name="model" value="<?= e((string) ($settings['model'] ?? '')) ?>"></label>
        <label>Nova chave da API<input type="password" name="api_key" maxlength="512" autocomplete="new-password" value="" aria-describedby="gemini-secret-help<?= !($settings['encryption_ready'] ?? false) ? ' gemini-encryption-help' : '' ?>"><small id="gemini-secret-help">Em branco preserva a chave configurada.</small></label>
        <?php if ($settings['has_database_key'] ?? false): ?><label class="admin-checks"><input type="checkbox" name="clear_api_key" value="1">Remover override criptografado e voltar ao ambiente</label><?php endif; ?>
        <button class="admin-button" type="submit">Salvar configuração</button>
    </form>
</section>
<section class="admin-panel"><div class="admin-panel-head"><div><p class="admin-eyebrow">Diagnóstico limitado</p><h2>Testar conexão</h2></div></div><p>Envia somente uma frase sintética curta usando a configuração efetiva já salva. O teste não usa nem envia alterações ainda preenchidas no formulário acima.</p><p>Nenhum vídeo, dado de usuário ou resposta bruta é armazenado.</p><p>Último resultado: <strong><?= e((string) ($settings['last_test_status'] ?? 'untested')) ?></strong><?= is_string($settings['last_tested_at'] ?? null) ? ' em ' . e($settings['last_tested_at']) : '' ?></p><p class="admin-context" role="status" aria-live="polite" data-admin-gemini-feedback></p><form method="post" action="/admin/configuracoes/gemini/testar" data-admin-gemini-test data-pending-label="Testando…"><input type="hidden" name="_token" value="<?= e($csrf) ?>"><button class="admin-button admin-button-secondary" type="submit" data-admin-gemini-test-button disabled>Testar conexão</button><noscript><p>Salve a configuração e habilite JavaScript para testar sem perder alterações ainda não salvas.</p></noscript></form></section>
<script src="/assets/js/admin-gemini.js" defer></script>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/../layouts/admin.php';
