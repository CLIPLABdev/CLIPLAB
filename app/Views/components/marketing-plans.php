<?php

declare(strict_types=1);

/** @var list<array<string,mixed>> $publicPlans */
$publicPlans = is_array($publicPlans ?? null) ? $publicPlans : [];
if ($publicPlans === []) {
    return;
}
$formatBytes = static function (int $bytes): string {
    if ($bytes >= 1073741824 && $bytes % 1073741824 === 0) {
        return (string) intdiv($bytes, 1073741824) . ' GB';
    }
    if ($bytes >= 1048576 && $bytes % 1048576 === 0) {
        return (string) intdiv($bytes, 1048576) . ' MB';
    }
    return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
};
$recommendedId = null;
foreach ($publicPlans as $plan) {
    if (($plan['slug'] ?? null) === 'louco') {
        $recommendedId = (int) ($plan['id'] ?? 0);
        break;
    }
}
if ($recommendedId === null) {
    foreach ($publicPlans as $plan) {
        if ((int) ($plan['price_cents'] ?? 0) > 0) {
            $recommendedId = (int) ($plan['id'] ?? 0);
            break;
        }
    }
}
$previousName = null;
?>
<section id="planos" class="section" aria-labelledby="planos-titulo">
    <div class="container">
        <div class="section-heading centered"><p class="eyebrow">Planos</p><h2 id="planos-titulo">Escolha o seu cientista.</h2><p>Comece de graça e evolua quando precisar de mais créditos por dia. Cada crédito vale 1 minuto de vídeo processado.</p></div>
        <div class="landing-plan-grid">
            <?php foreach ($publicPlans as $plan):
                $features = is_array($plan['features'] ?? null) ? $plan['features'] : [];
                $limits = is_array($features['limits'] ?? null) ? $features['limits'] : [];
                $recommended = $recommendedId !== null && (int) ($plan['id'] ?? 0) === $recommendedId;
            ?>
                <article class="landing-plan-card<?= $recommended ? ' landing-plan-card--recommended' : '' ?>">
                    <?php if ($recommended): ?><p class="landing-plan-badge">Mais escolhido</p><?php endif; ?>
                    <h3><?= e((string) ($plan['name'] ?? '')) ?></h3>
                    <?php if (trim((string) ($plan['description'] ?? '')) !== ''): ?><p class="landing-plan-description"><?= e((string) $plan['description']) ?></p><?php endif; ?>
                    <p class="landing-plan-price">R$ <?= e(number_format(((int) ($plan['price_cents'] ?? 0)) / 100, 2, ',', '.')) ?><span>/mês</span></p>
                    <ul>
                        <?php if ($previousName !== null): ?><li class="landing-plan-upgrade">Tudo do <?= e($previousName) ?>, e mais:</li><?php endif; ?>
                        <?php if ((int) ($plan['daily_credits'] ?? 0) > 0): ?><li><strong><?= (int) $plan['daily_credits'] ?> créditos por dia</strong></li><?php else: ?><li><?= e((string) (int) ($plan['credits'] ?? 0)) ?> créditos</li><?php endif; ?>
                        <li>Até <?= e((string) (int) ($plan['monthly_minutes'] ?? 0)) ?> minutos por mês</li>
                        <li><?= e($formatBytes((int) ($limits['max_upload_bytes'] ?? 0))) ?> por envio</li>
                        <li><?= e($formatBytes((int) ($limits['storage_bytes'] ?? 0))) ?> de armazenamento</li>
                        <?php if ($previousName === null): ?><li>Cortes automáticos com IA</li><li>Editor e revisão de cortes</li><li>Legendas editáveis</li><li>Exportação em MP4</li><?php endif; ?>
                    </ul>
                    <a class="button" href="/cadastro" aria-label="Começar com o plano <?= e((string) ($plan['name'] ?? '')) ?>">Começar agora</a>
                </article>
                <?php $previousName = (string) ($plan['name'] ?? ''); ?>
            <?php endforeach; ?>
        </div>
        <p class="landing-plan-note">Os créditos diários acumulam por até 30 dias. A mudança de plano é confirmada pela administração, sem cobrança automática nesta página.</p>
    </div>
</section>
