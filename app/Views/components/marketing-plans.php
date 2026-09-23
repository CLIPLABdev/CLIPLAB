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
    if ((int) ($plan['price_cents'] ?? 0) > 0) {
        $recommendedId = (int) ($plan['id'] ?? 0);
        break;
    }
}
?>
<section id="planos" class="section" aria-labelledby="planos-titulo">
    <div class="container">
        <div class="section-heading centered"><p class="eyebrow">Catálogo atual</p><h2 id="planos-titulo">Planos reais do ClipLab</h2><p>Valores e limites vêm do catálogo ativo. A contratação ou alteração é confirmada pela administração, sem cobrança online automática.</p></div>
        <div class="landing-plan-grid">
            <?php foreach ($publicPlans as $plan):
                $features = is_array($plan['features'] ?? null) ? $plan['features'] : [];
                $limits = is_array($features['limits'] ?? null) ? $features['limits'] : [];
                $recommended = $recommendedId !== null && (int) ($plan['id'] ?? 0) === $recommendedId;
            ?>
                <article class="landing-plan-card<?= $recommended ? ' landing-plan-card--recommended' : '' ?>">
                    <?php if ($recommended): ?><p class="landing-plan-badge">Recomendado pela equipe</p><?php endif; ?>
                    <h3><?= e((string) ($plan['name'] ?? '')) ?></h3>
                    <p class="landing-plan-price">R$ <?= e(number_format(((int) ($plan['price_cents'] ?? 0)) / 100, 2, ',', '.')) ?><span>/mês</span></p>
                    <ul>
                        <li><?= e((string) (int) ($plan['monthly_minutes'] ?? 0)) ?> minutos por mês</li>
                        <li><?= e((string) (int) ($plan['credits'] ?? 0)) ?> créditos</li>
                        <li><?= e($formatBytes((int) ($limits['max_upload_bytes'] ?? 0))) ?> por envio</li>
                        <li><?= e($formatBytes((int) ($limits['storage_bytes'] ?? 0))) ?> de armazenamento</li>
                        <li>Editor e revisão de cortes</li>
                        <li>Legendas editáveis</li>
                        <li>Exportação em MP4</li>
                    </ul>
                    <a class="button" href="/cadastro">Conhecer o plano</a>
                </article>
            <?php endforeach; ?>
        </div>
        <p class="landing-plan-note">A ativação do plano é confirmada pela administração. O ClipLab não realiza cobrança automática nesta página.</p>
    </div>
</section>
