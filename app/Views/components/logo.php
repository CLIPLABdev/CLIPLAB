<?php

declare(strict_types=1);

/** @var string $logoId */
$logoId ??= 'logo';
$logoName=$platformBranding['name']??'ClipLab';
$showBrandMark ??= true;
?>
<?php if (!empty($platformBranding['logo_url'])): ?>
<img class="platform-brand-image" src="<?= e($platformBranding['logo_url']) ?>" alt="<?= e($logoName) ?>">
<?php else: ?>
<?php if ($showBrandMark): ?><span class="brand-mark" aria-hidden="true">
    <svg viewBox="0 0 32 32" role="img" focusable="false"><path d="M7 8.5A4.5 4.5 0 0 1 11.5 4h8A4.5 4.5 0 0 1 24 8.5v15a4.5 4.5 0 0 1-4.5 4.5h-8A4.5 4.5 0 0 1 7 23.5z"/><path d="m14 11 7 5-7 5z"/></svg>
</span><?php endif; ?>
<span id="<?= e($logoId) ?>" class="brand-wordmark"><?php if ($logoName==='ClipLab'): ?>Clip<span>Lab</span><?php else: ?><?= e($logoName) ?><?php endif; ?></span>
<?php endif; ?>
