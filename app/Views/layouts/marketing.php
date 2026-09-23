<?php

declare(strict_types=1);
$platformBranding ??= ['name'=>'ClipLab','description'=>'','logo_url'=>null,'favicon_url'=>null];

/** @var string $content */
/** @var string $title */
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Seu próximo corte já está gravado. Encontre trechos com IA, revise no editor e exporte novos vídeos com o ClipLab.">
    <title><?= e($title) ?> — <?= e($platformBranding['name']) ?></title>
    <?php require __DIR__.'/../components/favicon.php'; ?>
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/design-system.css">
    <link rel="stylesheet" href="/assets/css/landing.css">
    <noscript><link rel="stylesheet" href="/assets/css/landing-nojs.css"></noscript>
</head>
<body class="marketing-page">
<?= $content ?>
<script src="/assets/vendor/lucide-0.468.0/lucide.min.js"></script>
<script src="/assets/js/app.js" defer></script>
<script src="/assets/js/landing-demo.js" defer></script>
</body>
</html>
