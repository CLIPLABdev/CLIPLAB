<?php
declare(strict_types=1);
foreach (array_slice($currentPromotions??[],0,3) as $promotion):
    $key=(int)($user['id']??0).':'.(int)$promotion['id'].':'.substr(hash('sha256',(string)($promotion['updated_at']??$promotion['created_at']??'')),0,12);
?>
<section class="platform-promotion" aria-label="Comunicado" data-promotion-key="<?= e($key) ?>" data-promotion-kind="<?= e($promotion['delivery_kind']??'banner') ?>">
    <?php if (!empty($promotion['image_url'])): ?><img src="<?= e($promotion['image_url']) ?>" alt="" loading="lazy" width="96" height="96"><?php endif; ?>
    <div><span class="eyebrow">Para o seu estúdio</span><h2><?= e($promotion['title']) ?></h2><p><?= e($promotion['body']) ?></p><?php if (!empty($promotion['cta_url']) && !empty($promotion['cta_label'])): ?><a class="text-link" href="<?= e($promotion['cta_url']) ?>" rel="noopener noreferrer"><?= e($promotion['cta_label']) ?> →</a><?php endif; ?></div>
    <button type="button" class="platform-dismiss" data-promotion-dismiss aria-label="Dispensar este comunicado">×</button>
</section>
<?php endforeach; ?>
