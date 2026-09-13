<?php

declare(strict_types=1);

/** @var list<array<string,string>> $publicTestimonials */
$publicTestimonials = is_array($publicTestimonials ?? null) ? $publicTestimonials : [];
if ($publicTestimonials === []) {
    return;
}
?>
<section id="relatos" class="section section-muted" aria-labelledby="relatos-titulo">
    <div class="container">
        <div class="section-heading centered"><p class="eyebrow">Relatos autorizados</p><h2 id="relatos-titulo">Experiências publicadas com consentimento</h2></div>
        <div class="landing-proof-grid">
            <?php foreach (array_slice($publicTestimonials, 0, 3) as $testimonial): ?>
                <figure class="landing-proof-card">
                    <blockquote>“<?= e((string) ($testimonial['quote'] ?? '')) ?>”</blockquote>
                    <?php if (trim((string) ($testimonial['result'] ?? '')) !== ''): ?><p class="landing-proof-result"><?= e((string) $testimonial['result']) ?></p><?php endif; ?>
                    <figcaption><strong><?= e((string) ($testimonial['name'] ?? '')) ?></strong><span><?= e((string) ($testimonial['context'] ?? '')) ?></span></figcaption>
                    <?php
                    $sourceUrl = trim((string) ($testimonial['source_url'] ?? ''));
                    $parts = $sourceUrl !== '' ? parse_url($sourceUrl) : false;
                    $safeSource = is_array($parts) && strtolower((string) ($parts['scheme'] ?? '')) === 'https' && is_string($parts['host'] ?? null) && $parts['host'] !== '' && !isset($parts['user']) && !isset($parts['pass']);
                    ?>
                    <?php if ($safeSource): ?><a href="<?= e($sourceUrl) ?>" target="_blank" rel="noopener noreferrer">Ver fonte</a><?php endif; ?>
                </figure>
            <?php endforeach; ?>
        </div>
    </div>
</section>
