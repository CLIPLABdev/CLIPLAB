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
    <svg viewBox="0 0 852 682" focusable="false"><defs><linearGradient id="<?= e($logoId) ?>-mark" x1="0" y1="0" x2="852" y2="0" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#7DF5DD"/><stop offset=".42" stop-color="#17F0C5"/></linearGradient></defs><g fill="url(#<?= e($logoId) ?>-mark)"><path d="M455 0H852L739 170.4H341Z"/><path d="M113.6 170.4H341L227.4 340.8L341 511.2H113.6L0 340.8Z"/><path d="M341 511.2H739L852 681.6H455Z"/></g></svg>
</span><?php endif; ?>
<span id="<?= e($logoId) ?>" class="brand-wordmark"><?php if ($logoName==='ClipLab'): ?>Clip<span>Lab</span><?php else: ?><?= e($logoName) ?><?php endif; ?></span>
<?php endif; ?>
