<?php

declare(strict_types=1);

use App\Core\Csrf;
$platformBranding ??= ['name'=>'ClipLab','description'=>'','logo_url'=>null,'favicon_url'=>null];
$platformFeatures ??= [];
$notificationUnread ??= 0;
$headerAvatar ??= false;
$currentPromotions ??= [];

/** @var string $content */
/** @var string $title */
/** @var array{id: int, name: string, email: string, credits: int, plan_name: string, monthly_minutes: int} $user */
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Área autenticada do <?= e($platformBranding['name']) ?>.">
    <title><?= e($title) ?> — <?= e($platformBranding['name']) ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <?php require __DIR__.'/../components/favicon.php'; ?>
    <link rel="stylesheet" href="/assets/css/design-system.css">
    <link rel="stylesheet" href="/assets/css/platform.css">
    <link rel="stylesheet" href="/assets/css/workspace.css">
</head>
<body class="app-body">
<a class="skip-link" href="#conteudo-app">Pular para o conteúdo</a>
<div class="app-shell">
    <button class="drawer-backdrop" type="button" aria-label="Fechar navegação" data-drawer-backdrop></button>
    <aside class="app-sidebar" id="navegacao-app" aria-label="Navegação da área logada" data-app-sidebar>
        <a class="brand app-brand" href="/dashboard" aria-label="<?= e($platformBranding['name']) ?>, painel"><?php $logoId = 'app-logo'; require __DIR__ . '/../components/logo.php'; ?></a>
        <a class="workspace-create" href="/projetos/novo"><i data-lucide="plus" aria-hidden="true"></i>Novo projeto<i data-lucide="arrow-up-right" aria-hidden="true"></i></a>
        <nav class="app-nav" aria-label="Principal">
            <p class="workspace-nav-heading">Seu estúdio</p>
            <a href="/dashboard" aria-current="<?= $title === 'Visão geral' ? 'page' : 'false' ?>"><i data-lucide="layout-dashboard" aria-hidden="true"></i>Visão geral</a>
            <a href="/projetos" aria-current="<?= in_array($title, ['Projetos', 'Novo projeto'], true) ? 'page' : 'false' ?>"><i data-lucide="folders" aria-hidden="true"></i>Projetos</a>
            <a href="/clips" aria-current="<?= $title === 'Clipes' ? 'page' : 'false' ?>"><i data-lucide="scissors" aria-hidden="true"></i>Clipes</a>
            <a href="/templates" aria-current="<?= $title === 'Templates' ? 'page' : 'false' ?>"><i data-lucide="panels-top-left" aria-hidden="true"></i>Templates</a>
            <a href="/marca" aria-current="<?= $title === 'Minha marca' ? 'page' : 'false' ?>"><i data-lucide="palette" aria-hidden="true"></i>Minha marca</a>
            <p class="workspace-nav-heading">Plano e consumo</p>
            <a href="/conta/plano" aria-current="<?= $title === 'Plano e limites' ? 'page' : 'false' ?>"><i data-lucide="package" aria-hidden="true"></i>Plano e limites</a>
            <a href="/conta/creditos" aria-current="<?= $title === 'Créditos' ? 'page' : 'false' ?>"><i data-lucide="wallet" aria-hidden="true"></i>Créditos</a>
            <?php if (!empty($platformFeatures['billing'])): ?><a href="/conta/pagamentos" aria-current="<?= $title==='Pagamentos'?'page':'false' ?>"><i data-lucide="receipt" aria-hidden="true"></i>Pagamentos</a><?php endif; ?>
            <p class="workspace-nav-heading">Sua conta</p>
            <?php if (!empty($platformFeatures['communications'])): ?><a href="/notificacoes" aria-current="<?= $title==='Notificações'?'page':'false' ?>"><i data-lucide="bell" aria-hidden="true"></i>Notificações <?php if ($notificationUnread>0): ?><span class="platform-count"><?= min(99,(int)$notificationUnread) ?></span><?php endif; ?></a><a href="/preferencias" aria-current="<?= $title==='Preferências'?'page':'false' ?>"><i data-lucide="sliders-horizontal" aria-hidden="true"></i>Preferências</a><?php endif; ?>
            <a href="/perfil" aria-current="<?= $title === 'Perfil' ? 'page' : 'false' ?>"><i data-lucide="user-round" aria-hidden="true"></i>Perfil</a>
            <a href="/privacidade"><i data-lucide="lock-keyhole" aria-hidden="true"></i>Privacidade</a>
            <?php if (\App\Core\Session::get('admin_navigation') === true): ?>
                <a href="/admin"><i data-lucide="shield-check" aria-hidden="true"></i>Administração</a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-plan"><span>Plano atual</span><strong><?= e($user['plan_name']) ?></strong><small><?= (int) $user['credits'] ?> créditos em saldo</small></div>
        <form method="post" action="/logout" class="logout-form"><input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>"><button type="submit"><i data-lucide="log-out" aria-hidden="true"></i>Sair</button></form>
    </aside>
    <div class="app-main-wrap">
        <header class="app-header">
            <button class="drawer-toggle" type="button" aria-expanded="false" aria-controls="navegacao-app" data-drawer-toggle><i data-lucide="menu" aria-hidden="true"></i><span class="visually-hidden">Abrir navegação</span></button>
            <div class="workspace-page-title"><p class="app-kicker">Seu espaço de criação</p><h1><?= e($title) ?></h1></div>
            <div class="workspace-header-actions">
                <button class="workspace-finder-trigger" type="button" data-workspace-open aria-label="Buscar páginas" aria-haspopup="dialog" aria-controls="workspace-command" hidden><i data-lucide="search" aria-hidden="true"></i><span>Buscar páginas</span><kbd>Ctrl K</kbd></button>
                <a class="header-profile" href="/perfil" aria-label="Perfil de <?= e($user['name']) ?>"><span aria-hidden="true"><?php if ($headerAvatar): ?><img src="/perfil/avatar" alt="" width="38" height="38"><?php else: ?><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?><?php endif; ?></span><strong><?= e($user['name']) ?></strong></a>
            </div>
        </header>
        <main id="conteudo-app" class="app-content"><?php require __DIR__.'/../components/promotions.php'; ?><?= $content ?></main>
    </div>
</div>
<?php require __DIR__.'/../components/workspace-command.php'; ?>
<script src="/assets/vendor/lucide-0.468.0/lucide.min.js"></script>
<script src="/assets/js/dashboard.js" defer></script>
<script src="/assets/js/platform.js" defer></script>
<script src="/assets/js/workspace.js" defer></script>
</body>
</html>
