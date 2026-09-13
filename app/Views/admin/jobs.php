<?php

declare(strict_types=1);

/** @var string $mode */
/** @var array<string,mixed> $page */
$mode = ($mode ?? 'jobs') === 'errors' ? 'errors' : 'jobs';
$items = is_array($page['items'] ?? null) ? $page['items'] : [];
$filters = is_array($page['filters'] ?? null) ? $page['filters'] : [];
$total = max(0, (int) ($page['total'] ?? 0));
$current = max(1, (int) ($page['page'] ?? 1));
$last = max(1, (int) ($page['last_page'] ?? 1));
$base = $mode === 'errors' ? '/admin/erros' : '/admin/jobs';
$pageUrl = static function (int $page) use ($base, $filters): string { return $base . '?' . http_build_query($filters + ['page' => $page], '', '&', PHP_QUERY_RFC3986); };
$errors = [
    'ai_timeout' => 'O provedor de IA excedeu o tempo limite.',
    'ai_unavailable' => 'O provedor de IA está temporariamente indisponível.',
    'ai_provider_rejected' => 'O provedor de IA recusou a solicitação.',
    'source_unavailable' => 'A fonte do vídeo não está disponível.',
    'render_failed' => 'A renderização não pôde ser concluída.',
];
ob_start();
?>
<section class="admin-page-head"><div><p class="admin-eyebrow"><?= $mode === 'errors' ? 'Atenção à operação' : 'Fila de processamento' ?></p><h2><?= $mode === 'errors' ? 'Entenda as falhas.' : 'Acompanhe cada etapa.' ?></h2><p><?= $total ?> registros · <?= $mode === 'errors' ? 'Veja os motivos que impediram a conclusão dos jobs.' : 'Consulte status, progresso e tentativas de processamento.' ?></p></div></section>
<section class="admin-panel">
    <form class="admin-filter" method="get" action="<?= e($base) ?>">
        <?php if ($mode === 'jobs'): ?><label>Status<select name="status"><option value="all">Todos</option><?php foreach (['queued','running','retry','completed','failed'] as $status): ?><option value="<?= $status ?>"<?= ($filters['status'] ?? '') === $status ? ' selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select></label><?php endif; ?>
        <label>Tipo exato<input name="type" pattern="[a-z][a-z0-9_.-]*" value="<?= e((string) ($filters['type'] ?? '')) ?>"></label>
        <button class="admin-button admin-button-secondary" type="submit">Filtrar</button>
    </form>
    <div class="admin-table-wrap" role="region" aria-label="Registros de processamento" tabindex="0"><table><thead><tr><th>Job</th><th>Projeto</th><th>Conta</th><th>Status</th><th>Progresso</th><th>Tentativas</th><?php if ($mode === 'errors'): ?><th>Falha pública</th><?php endif; ?><th>Atualizado</th></tr></thead><tbody>
    <?php if ($items === []): ?><tr><td colspan="<?= $mode === 'errors' ? 8 : 7 ?>" class="admin-empty"><strong>Nenhum registro encontrado.</strong><small>Experimente outros filtros para ampliar a consulta.</small></td></tr><?php endif; ?>
    <?php foreach ($items as $row): if (!is_array($row)) { continue; } $code = (string) ($row['last_error_code'] ?? ''); ?>
        <tr><td><strong><?= e((string) ($row['type'] ?? 'job')) ?></strong><small>#<?= (int) ($row['id'] ?? 0) ?></small></td><td><?= e((string) ($row['project_name'] ?? '')) ?> <small>#<?= (int) ($row['project_id'] ?? 0) ?></small></td><td><?= e((string) ($row['user_email'] ?? '')) ?></td><td><span class="admin-badge admin-badge-<?= e((string) ($row['status'] ?? '')) ?>"><?= e((string) ($row['status'] ?? '')) ?></span></td><td><?= max(0, min(100, (int) ($row['progress'] ?? 0))) ?>%</td><td><?= (int) ($row['attempts'] ?? 0) ?> / <?= (int) ($row['max_attempts'] ?? 0) ?></td><?php if ($mode === 'errors'): ?><td><?= e($errors[$code] ?? 'O processamento não pôde ser concluído.') ?></td><?php endif; ?><td><?= e((string) ($row['updated_at'] ?? '')) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <nav class="admin-pagination" aria-label="Paginação"><span>Página <?= $current ?> de <?= $last ?></span><?php if ($current > 1): ?><a rel="prev" href="<?= e($pageUrl($current - 1)) ?>">Anterior</a><?php endif; ?><?php if ($current < $last): ?><a rel="next" href="<?= e($pageUrl($current + 1)) ?>">Próxima</a><?php endif; ?></nav>
</section>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/../layouts/admin.php';
