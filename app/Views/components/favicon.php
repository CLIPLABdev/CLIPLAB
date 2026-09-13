<?php
declare(strict_types=1);
$faviconUrl=$platformBranding['favicon_url']??'/assets/images/favicon.svg';
$faviconType=match(strtolower(pathinfo($faviconUrl,PATHINFO_EXTENSION))){
    'png'=>'image/png','jpg','jpeg'=>'image/jpeg','webp'=>'image/webp','ico'=>'image/x-icon',default=>'image/svg+xml',
};
?>
<link rel="icon" href="<?= e($faviconUrl) ?>" type="<?= e($faviconType) ?>">
