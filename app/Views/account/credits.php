<?php

declare(strict_types=1);

/** @var array<string,mixed> $user */
/** @var array<string,mixed> $snapshot */
/** @var array<string,mixed> $ledger */

$labels = ['all' => 'Todos', 'additions' => 'Adições', 'consumption' => 'Consumos', 'refunds' => 'Reembolsos', 'adjustments' => 'Ajustes'];
$kindLabels = ['addition' => 'Adição', 'consumption' => 'Consumo', 'refund' => 'Reembolso', 'adjustment' => 'Ajuste'];
$signedAmount = static function (array $item): string {
    $amount = abs((int) $item['amount']);
    if ($item['kind'] === 'consumption') {
        return '−' . $amount;
    }
    if ($item['kind'] === 'adjustment' && (int) $item['amount'] < 0) {
        return '−' . $amount;
    }

    return '+' . $amount;
};
ob_start();
?>
<link rel="stylesheet" href="/assets/css/account.css">
<section class="account-hero" aria-labelledby="credits-title"><div><p class="eyebrow">Saldo da conta</p><h2 id="credits-title"><?= (int) $snapshot['credits'] ?> créditos</h2><p>Cada análise aparece aqui. Acompanhe o que entrou, o que foi usado e seu saldo.</p></div><a class="button button-small" href="/conta/plano">Ver plano e limites</a></section>

<nav class="ledger-filters" aria-label="Filtrar extrato">
<?php foreach ($labels as $key => $label): ?><a href="/conta/creditos?filter=<?= e($key) ?>" aria-current="<?= $ledger['filter'] === $key ? 'page' : 'false' ?>"><?= e($label) ?></a><?php endforeach; ?>
</nav>

<section class="panel ledger-panel" aria-labelledby="ledger-title"><div class="account-section-heading"><div><p class="eyebrow">Movimentações</p><h2 id="ledger-title">Extrato de créditos</h2></div><span><?= (int) $ledger['total'] ?> lançamentos</span></div>
<?php if ($ledger['items'] === []): ?>
    <div class="account-empty"><h3>Nenhum lançamento neste filtro</h3><p>Quando houver uma movimentação, ela aparecerá aqui.</p><a class="text-link" href="<?= $ledger['filter'] === 'all' ? '/projetos/novo' : '/conta/creditos?filter=all' ?>"><?= $ledger['filter'] === 'all' ? 'Começar um projeto' : 'Ver todas as movimentações' ?> <span aria-hidden="true">→</span></a></div>
<?php else: ?>
    <div class="ledger-table-wrap"><table class="ledger-table"><thead><tr><th scope="col">Data</th><th scope="col">Tipo</th><th scope="col">Descrição</th><th scope="col">Movimento</th><th scope="col">Saldo</th></tr></thead><tbody>
    <?php foreach ($ledger['items'] as $item): ?><tr><td><time datetime="<?= e((string) $item['created_at']) ?>"><?= e((string) $item['created_at']) ?></time></td><td><span class="ledger-kind kind-<?= e((string) $item['kind']) ?>"><?= e($kindLabels[$item['kind']] ?? 'Movimento') ?></span></td><td><?= e((string) ($item['description'] ?? 'Movimentação de créditos')) ?></td><td class="ledger-amount kind-<?= e((string) $item['kind']) ?>"><?= e($signedAmount($item)) ?></td><td><?= (int) $item['balance_after'] ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?>
<?php if ((int) $ledger['pages'] > 1): ?><nav class="ledger-pages" aria-label="Páginas do extrato"><?php if ((int) $ledger['page'] > 1): ?><a href="/conta/creditos?filter=<?= e((string) $ledger['filter']) ?>&amp;page=<?= (int) $ledger['page'] - 1 ?>">Página anterior</a><?php endif; ?><span>Página <?= (int) $ledger['page'] ?> de <?= (int) $ledger['pages'] ?></span><?php if ((int) $ledger['page'] < (int) $ledger['pages']): ?><a href="/conta/creditos?filter=<?= e((string) $ledger['filter']) ?>&amp;page=<?= (int) $ledger['page'] + 1 ?>">Próxima página</a><?php endif; ?></nav><?php endif; ?>
</section>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/../layouts/app.php';
