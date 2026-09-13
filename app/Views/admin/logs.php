<?php

declare(strict_types=1);

/** @var array<string,mixed> $page */
$items = is_array($page['items'] ?? null) ? $page['items'] : [];
$filters = is_array($page['filters'] ?? null) ? $page['filters'] : [];
$total = max(0, (int) ($page['total'] ?? 0));
$current = max(1, (int) ($page['page'] ?? 1));
$last = max(1, (int) ($page['last_page'] ?? 1));
$pageUrl = static fn (int $target): string => '/admin/logs?' . http_build_query($filters + ['page' => $target], '', '&', PHP_QUERY_RFC3986);
ob_start();
?>
<section class="admin-page-head"><div><p class="admin-eyebrow">Histórico operacional</p><h2>O que aconteceu no sistema.</h2><p><?= $total ?> eventos públicos · consulte o nível, a mensagem e o contexto de cada registro.</p></div></section>
<section class="admin-panel">
    <form class="admin-filter" method="get" action="/admin/logs"><label>Nível<select name="level"><option value="all">Todos</option><?php foreach (['info','warning','error'] as $level): ?><option value="<?= $level ?>"<?= ($filters['level'] ?? '') === $level ? ' selected' : '' ?>><?= e($level) ?></option><?php endforeach; ?></select></label><label>Evento exato<input name="event" value="<?= e((string) ($filters['event'] ?? '')) ?>"></label><button class="admin-button admin-button-secondary" type="submit">Filtrar</button></form>
    <div class="admin-table-wrap" role="region" aria-label="Eventos do sistema" tabindex="0"><table><thead><tr><th>Data</th><th>Nível</th><th>Evento</th><th>Mensagem pública</th><th>Contexto permitido</th><th>Ator / alvo</th></tr></thead><tbody>
    <?php if ($items === []): ?><tr><td colspan="6" class="admin-empty">Nenhum evento encontrado.</td></tr><?php endif; ?>
    <?php foreach ($items as $row): if (!is_array($row)) { continue; } ?><tr><td><?= e((string) ($row['created_at'] ?? '')) ?></td><td><span class="admin-badge admin-badge-<?= e((string) ($row['level'] ?? '')) ?>"><?= e((string) ($row['level'] ?? '')) ?></span></td><td><?= e((string) ($row['event_code'] ?? '')) ?></td><td><?= e((string) ($row['message'] ?? '')) ?></td><td><ul class="admin-context"><?php foreach ((array) ($row['context'] ?? []) as $key => $value): ?><li><strong><?= e((string) $key) ?>:</strong> <?= e(is_bool($value) ? ($value ? 'sim' : 'não') : (string) $value) ?></li><?php endforeach; ?></ul></td><td><?= isset($row['actor_id']) ? '#' . (int) $row['actor_id'] : 'sistema' ?> → <?= e((string) ($row['target_type'] ?? '—')) ?><?= isset($row['target_id']) ? ' #' . (int) $row['target_id'] : '' ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <nav class="admin-pagination" aria-label="Paginação"><span>Página <?= $current ?> de <?= $last ?></span><?php if ($current > 1): ?><a rel="prev" href="<?= e($pageUrl($current - 1)) ?>">Anterior</a><?php endif; ?><?php if ($current < $last): ?><a rel="next" href="<?= e($pageUrl($current + 1)) ?>">Próxima</a><?php endif; ?></nav>
</section>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/../layouts/admin.php';
