<?php
declare(strict_types=1);
use App\Core\Csrf;
$platformBranding ??= ['name'=>'ClipForge','description'=>'','logo_url'=>null,'favicon_url'=>null];
$platformFeatures ??= [];
$navigation = [
    'Operação'=>[
        '/admin'=>['Administração','Visão geral','layout-dashboard'],
        '/admin/projetos'=>['Projetos','Projetos','folder-open'],
        '/admin/videos'=>['Vídeos','Vídeos','film'],
        '/admin/jobs'=>['Jobs','Fila de jobs','list-video'],
        '/admin/erros'=>['Erros','Falhas','triangle-alert'],
    ],
    'Pessoas e planos'=>[
        '/admin/usuarios'=>['Usuários','Usuários','users'],
        '/admin/creditos'=>['Créditos','Créditos','coins'],
        '/admin/planos'=>['Planos','Planos','layers-2'],
    ],
    'Financeiro'=>[],
    'Comunicação'=>['/admin/conteudo'=>['Conteúdo público','Conteúdo público','notebook-text']],
    'Sistema'=>[
        '/admin/logs'=>['Logs do sistema','Logs do sistema','scroll-text'],
        '/admin/configuracoes/gemini'=>['Configuração Gemini','Gemini','sparkles'],
    ],
];
if (!empty($platformFeatures['admin_tools'])) {
    $navigation['Comunicação']['/admin/promocoes']=['Promoções','Banners e avisos','megaphone'];
    $navigation['Sistema']['/admin/configuracoes']=['Configurações gerais','Configurações gerais','settings-2'];
}
if (!empty($platformFeatures['billing'])) {
    $navigation['Financeiro']['/admin/financeiro']=['Financeiro','Pagamentos','wallet'];
    $navigation['Financeiro']['/admin/gateways']=['Gateways','Gateways','credit-card'];
    $navigation['Financeiro']['/admin/cupons']=['Cupons','Cupons','ticket'];
}
if (!empty($platformFeatures['communications'])) {
    $navigation['Comunicação']['/admin/emails']=['Templates de e-mail','Templates de e-mail','mail'];
    $navigation['Comunicação']['/admin/email-configuracao']=['Configuração de e-mail','Configuração de e-mail','settings'];
}
if (!empty($platformFeatures['campaigns'])) $navigation['Comunicação']['/admin/campanhas']=['Campanhas','Campanhas','send'];
$requestPath = rtrim((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: ''), '/');
$activeHref = null;
foreach ($navigation as $links) foreach ($links as $href => [$pageTitle]) {
    if ($requestPath === $href || ($href !== '/admin' && str_starts_with($requestPath, $href . '/'))) {
        if ($activeHref === null || strlen($href) > strlen($activeHref)) $activeHref = $href;
    }
}
if ($activeHref === null) foreach ($navigation as $links) foreach ($links as $href => [$pageTitle]) {
    if ($title === $pageTitle) $activeHref = $href;
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Administração segura do <?= e($platformBranding['name']) ?>.">
    <title><?= e($title) ?> — <?= e($platformBranding['name']) ?> Admin</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
    <?php require __DIR__.'/../components/favicon.php'; ?>
    <link rel="stylesheet" href="/assets/css/design-system.css">
    <link rel="stylesheet" href="/assets/css/platform.css">
    <link rel="stylesheet" href="/assets/css/workspace.css">
    <link rel="stylesheet" href="/assets/css/admin-polish.css">
</head>
<body class="admin-body">
<a class="admin-skip" href="#conteudo-admin">Pular para o conteúdo</a>
<div class="admin-shell">
    <button type="button" class="admin-drawer-backdrop" data-admin-drawer-backdrop aria-label="Fechar navegação" tabindex="-1" hidden></button>
    <aside id="admin-navigation" class="admin-sidebar" aria-label="Navegação administrativa" data-admin-drawer>
        <button type="button" class="admin-drawer-close" data-admin-drawer-close aria-label="Fechar navegação"><i data-lucide="x" aria-hidden="true"></i><span>Fechar menu</span></button>
        <a class="admin-brand" href="/admin"><span aria-hidden="true"><?= e(mb_strtoupper(mb_substr($platformBranding['name'],0,2))) ?></span><strong><?= e($platformBranding['name']) ?></strong><small>Administração</small></a>
        <nav class="admin-nav" aria-label="Administração">
            <?php foreach ($navigation as $group => $links): if ($links === []) continue; ?>
                <div class="admin-nav-group"><p><?= e($group) ?></p>
                <?php foreach ($links as $href => [$pageTitle, $label, $icon]): ?>
                    <a href="<?= e($href) ?>"<?= $activeHref === $href ? ' aria-current="page"' : '' ?>><i data-lucide="<?= e($icon) ?>" aria-hidden="true"></i><span><?= e($label) ?></span></a>
                <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>
        <div class="admin-sidebar-footer">
            <div class="admin-identity"><span>Conta administrativa</span><strong><?= e((string) ($admin['name'] ?? 'Administrador')) ?></strong><small><?= e((string) ($admin['email'] ?? '')) ?></small></div>
            <form method="post" action="/logout"><input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>"><button class="admin-button admin-button-quiet" type="submit"><i data-lucide="log-out" aria-hidden="true"></i>Sair da conta</button></form>
        </div>
    </aside>
    <div class="admin-main-wrap">
        <header class="admin-header"><div class="admin-header-title"><button type="button" class="admin-drawer-toggle" data-admin-drawer-toggle aria-controls="admin-navigation" aria-expanded="false" aria-label="Abrir navegação"><i data-lucide="menu" aria-hidden="true"></i></button><div><p><?= e($platformBranding['name']) ?> / Administração</p><h1><?= e($title) ?></h1></div></div><a class="admin-app-link" href="/dashboard"><span>Voltar ao app</span><i data-lucide="arrow-up-right" aria-hidden="true"></i></a></header>
        <main id="conteudo-admin" class="admin-content" tabindex="-1">
            <?php if (is_array($flash ?? null) && is_string($flash['message'] ?? null)): ?>
                <p class="admin-flash admin-flash-<?= ($flash['type'] ?? '') === 'success' ? 'success' : 'error' ?>" role="status"><?= e($flash['message']) ?></p>
            <?php endif; ?>
            <?= $content ?>
        </main>
    </div>
</div>
<script src="/assets/js/platform.js" defer></script>
<script src="/assets/vendor/lucide-0.468.0/lucide.min.js" defer></script>
<script src="/assets/js/workspace.js" defer></script>
<script src="/assets/js/admin-workspace.js" defer></script>
</body>
</html>
