<?php

declare(strict_types=1);

/** @var array<string,mixed> $user */
/** @var array<string,mixed> $snapshot */
/** @var list<array<string,mixed>> $plans */

$formatPrice = static fn (int $cents): string => $cents === 0
    ? 'Grátis'
    : 'R$ ' . number_format($cents / 100, 2, ',', '.');
$formatBytes = static function (int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float) max(0, $bytes);
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }

    return number_format($value, $unit === 0 ? 0 : 1, ',', '.') . ' ' . $units[$unit];
};
$currentPlan = $snapshot['plan'];
$billingEnabled = !empty($platformFeatures['billing']);
$currentLimits = $currentPlan['features']['limits'];
$minuteMaximum = max(1, (int) $currentPlan['monthly_minutes']);
$storageMaximum = max(1, (int) $currentLimits['storage_bytes']);
ob_start();
?>
<link rel="stylesheet" href="/assets/css/account.css">
<section class="account-hero" aria-labelledby="account-plan-title">
    <div><p class="eyebrow">Sua assinatura</p><h2 id="account-plan-title">Plano <?= e((string) $currentPlan['name']) ?></h2><p>Limites aplicados à sua conta neste ciclo.</p></div>
    <div><?php if ($billingEnabled): ?><a class="button button-small" href="/conta/pagamentos">Ver pagamentos</a><?php endif; ?> <a class="button button-small" href="/conta/creditos">Ver extrato de créditos</a></div>
</section>

<section class="account-metrics" aria-label="Uso do plano">
    <article class="account-card"><span>Valor do plano</span><strong><?= e($formatPrice((int) $currentPlan['price_cents'])) ?></strong><small>por mês<?= $billingEnabled ? '; confira pagamentos ou revise um plano abaixo' : '; cobrança online será disponibilizada futuramente' ?></small></article>
    <article class="account-card"><span>Créditos</span><strong><?= (int) $snapshot['credits'] ?></strong><small><?= (int) $currentPlan['included_credits'] ?> incluídos na configuração do plano</small></article>
    <article class="account-card"><span>Upload por arquivo</span><strong><?= e($formatBytes((int) $currentLimits['max_upload_bytes'])) ?></strong><small>também sujeito ao limite seguro do servidor</small></article>
</section>

<section class="account-usage-grid">
    <article class="panel account-usage" aria-labelledby="minutes-title"><div class="account-section-heading"><div><p class="eyebrow">Ciclo mensal</p><h2 id="minutes-title">Minutos de processamento</h2></div><strong><?= (int) $snapshot['minutes_remaining'] ?> restantes</strong></div><p><?= (int) $snapshot['minutes_used'] ?> de <?= (int) $currentPlan['monthly_minutes'] ?> minutos usados.</p><progress value="<?= min((int) $snapshot['minutes_used'], $minuteMaximum) ?>" max="<?= $minuteMaximum ?>" aria-label="<?= (int) $snapshot['minutes_used'] ?> de <?= (int) $currentPlan['monthly_minutes'] ?> minutos usados"></progress></article>
    <article class="panel account-usage" aria-labelledby="storage-title"><div class="account-section-heading"><div><p class="eyebrow">Arquivos reais</p><h2 id="storage-title">Armazenamento</h2></div><strong><?= e($formatBytes((int) $snapshot['storage_remaining_bytes'])) ?> livres</strong></div><p><?= e($formatBytes((int) $snapshot['storage_bytes'])) ?> de <?= e($formatBytes((int) $currentLimits['storage_bytes'])) ?> usados.</p><progress value="<?= min((int) $snapshot['storage_bytes'], $storageMaximum) ?>" max="<?= $storageMaximum ?>" aria-label="<?= e($formatBytes((int) $snapshot['storage_bytes'])) ?> usados de <?= e($formatBytes((int) $currentLimits['storage_bytes'])) ?>"></progress></article>
</section>

<section class="panel account-plans" aria-labelledby="available-plans-title"><div class="account-section-heading"><div><p class="eyebrow">Planos ativos</p><h2 id="available-plans-title">Compare os limites</h2></div><p><?= $billingEnabled ? 'Escolha um plano para revisar a contratação; nenhuma cobrança é criada nesta tela.' : 'Alterações de plano são confirmadas pela administração.' ?></p></div><div class="plan-comparison">
<?php foreach ($plans as $plan): $isCurrent = (int) $plan['id'] === (int) $currentPlan['id']; ?>
    <article class="plan-option<?= $isCurrent ? ' is-current' : '' ?>">
        <div class="plan-option-heading"><h3><?= e((string) $plan['name']) ?></h3><?php if ($isCurrent): ?><span>Plano atual</span><?php endif; ?></div>
        <p class="plan-price"><?= e($formatPrice((int) $plan['price_cents'])) ?><small><?= (int) $plan['monthly_minutes'] ?> min/mês · <?= (int) $plan['credits'] ?> créditos</small></p>
        <ul><li>Upload de até <?= e($formatBytes((int) $plan['features']['limits']['max_upload_bytes'])) ?></li><li>Armazenamento de <?= e($formatBytes((int) $plan['features']['limits']['storage_bytes'])) ?></li></ul>
        <?php if (!$isCurrent): ?><?php if ($billingEnabled): ?><a class="button button-small" href="/checkout/plano/<?= (int)$plan['id'] ?>">Revisar contratação</a><?php else: ?><p class="plan-note">Solicite a alteração à administração. Nenhuma cobrança é feita nesta tela.</p><?php endif; ?><?php endif; ?>
    </article>
<?php endforeach; ?>
</div></section>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/../layouts/app.php';
