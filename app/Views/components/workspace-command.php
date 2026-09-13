<?php
declare(strict_types=1);

$workspacePages = [
    ['/projetos/novo', 'Novo projeto', 'Importe um vídeo e comece a criar', 'plus'],
    ['/dashboard', 'Visão geral', 'Acompanhe seu estúdio', 'layout-dashboard'],
    ['/projetos', 'Projetos', 'Encontre seus vídeos e processamentos', 'folders'],
    ['/clips', 'Clipes', 'Abra sua biblioteca de cortes', 'scissors'],
    ['/templates', 'Templates', 'Estilos reutilizáveis para seus cortes', 'panels-top-left'],
    ['/marca', 'Minha marca', 'Sua identidade visual', 'palette'],
    ['/conta/plano', 'Plano e limites', 'Confira os recursos do seu plano', 'package'],
    ['/conta/creditos', 'Créditos', 'Consulte seu saldo e consumo', 'wallet'],
    ['/perfil', 'Perfil', 'Seus dados e segurança de acesso', 'user-round'],
];
if (!empty($platformFeatures['billing'])) $workspacePages[] = ['/conta/pagamentos', 'Pagamentos', 'Histórico e acompanhamento das cobranças', 'receipt'];
if (!empty($platformFeatures['communications'])) {
    $workspacePages[] = ['/notificacoes', 'Notificações', 'Atualizações sobre sua conta e seus vídeos', 'bell'];
    $workspacePages[] = ['/preferencias', 'Preferências', 'Escolha as comunicações que deseja receber', 'sliders-horizontal'];
}
?>
<dialog class="workspace-command" id="workspace-command" data-workspace-dialog aria-labelledby="workspace-command-title">
    <div class="workspace-command-heading"><div><p class="workspace-eyebrow">Navegação rápida</p><h2 id="workspace-command-title">Aonde vamos?</h2></div><button type="button" data-workspace-close aria-label="Fechar busca de páginas"><i data-lucide="x" aria-hidden="true"></i></button></div>
    <label class="visually-hidden" for="workspace-search">Buscar uma página do estúdio</label>
    <div class="workspace-search-field"><i data-lucide="search" aria-hidden="true"></i><input id="workspace-search" type="search" placeholder="Projetos, clipes, minha marca…" autocomplete="off" data-workspace-search aria-controls="workspace-results"></div>
    <p class="workspace-search-status" aria-live="polite" data-workspace-count>Encontre uma página ou comece um novo projeto.</p>
    <ul id="workspace-results" class="workspace-results">
        <?php foreach ($workspacePages as [$href, $label, $description, $icon]): ?>
        <li data-workspace-item><a href="<?= e($href) ?>"><i data-lucide="<?= e($icon) ?>" aria-hidden="true"></i><span><strong><?= e($label) ?></strong><small><?= e($description) ?></small></span><i data-lucide="arrow-up-right" aria-hidden="true"></i></a></li>
        <?php endforeach; ?>
    </ul>
    <div class="workspace-search-empty" data-workspace-empty hidden><strong>Nenhuma página encontrada.</strong><p>Tente “clipes”, “perfil” ou outro nome do menu.</p></div>
    <p class="workspace-command-help"><span><kbd>↑</kbd> <kbd>↓</kbd> para navegar</span><span><kbd>Esc</kbd> para fechar</span></p>
</dialog>
