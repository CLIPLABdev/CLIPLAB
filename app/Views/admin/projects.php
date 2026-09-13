<?php

declare(strict_types=1);

/** @var string $mode */
/** @var array<string,mixed> $page */
$mode = ($mode ?? 'projects') === 'videos' ? 'videos' : 'projects';
$items = is_array($page['items'] ?? null) ? $page['items'] : [];
$filters = is_array($page['filters'] ?? null) ? $page['filters'] : [];
$total = max(0, (int) ($page['total'] ?? 0));
$current = max(1, (int) ($page['page'] ?? 1));
$last = max(1, (int) ($page['last_page'] ?? 1));
$base = $mode === 'videos' ? '/admin/videos' : '/admin/projetos';
$pageUrl = static function (int $page) use ($base, $filters): string { return $base . '?' . http_build_query($filters + ['page' => $page], '', '&', PHP_QUERY_RFC3986); };
$bytes = static fn (mixed $value): string => number_format(max(0, (int) $value) / 1048576, 1, ',', '.') . ' MB';
ob_start();
?>
<section class="admin-page-head"><div><p class="admin-eyebrow">Biblioteca da plataforma</p><h2><?= $mode === 'videos' ? 'Cada vídeo, em contexto.' : 'A produção em perspectiva.' ?></h2><p><?= $total ?> registros · consulte a conta responsável, o status e o uso de armazenamento.</p></div></section>
<section class="admin-panel">
    <form class="admin-filter" method="get" action="<?= e($base) ?>">
        <label>Buscar<input name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="Projeto ou conta"></label>
        <?php if ($mode === 'videos'): ?>
            <label>Origem<select name="source"><option value="all">Todas</option><option value="upload"<?= ($filters['source'] ?? '') === 'upload' ? ' selected' : '' ?>>Upload</option><option value="direct_url"<?= ($filters['source'] ?? '') === 'direct_url' ? ' selected' : '' ?>>URL direta</option></select></label>
            <label>Status<select name="status"><option value="all">Todos</option><?php foreach (['pending','stored','ready','failed'] as $status): ?><option value="<?= $status ?>"<?= ($filters['status'] ?? '') === $status ? ' selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select></label>
        <?php else: ?>
            <label>Status<select name="status"><option value="all">Todos</option><?php foreach (['draft','uploading','queued','processing','completed','failed'] as $status): ?><option value="<?= $status ?>"<?= ($filters['status'] ?? '') === $status ? ' selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select></label>
        <?php endif; ?>
        <button class="admin-button admin-button-secondary" type="submit">Filtrar</button>
    </form>
    <div class="admin-table-wrap" role="region" aria-label="Registros da biblioteca" tabindex="0"><table><thead><tr>
        <?php if ($mode === 'videos'): ?><th>Vídeo</th><th>Projeto</th><th>Conta</th><th>Origem</th><th>Tamanho</th><th>Duração</th><th>Codec</th><th>Status</th>
        <?php else: ?><th>Projeto</th><th>Conta</th><th>Status</th><th>Progresso</th><th>Duração</th><th>Armazenamento</th><th>Atualizado</th><?php endif; ?>
    </tr></thead><tbody>
    <?php if ($items === []): ?><tr><td colspan="<?= $mode === 'videos' ? 8 : 7 ?>" class="admin-empty"><strong>Nenhum registro encontrado.</strong><small>Experimente outra busca ou selecione todos os status.</small></td></tr><?php endif; ?>
    <?php foreach ($items as $row): if (!is_array($row)) { continue; } ?>
        <?php if ($mode === 'videos'): ?>
            <tr><td><strong><?= e((string) ($row['original_name'] ?? 'Vídeo sem nome')) ?></strong><small>#<?= (int) ($row['id'] ?? 0) ?> · <?= e((string) ($row['mime_type'] ?? 'tipo indisponível')) ?></small></td><td><?= e((string) ($row['project_name'] ?? '')) ?></td><td><?= e((string) ($row['user_email'] ?? '')) ?></td><td><?= e((string) ($row['source_type'] ?? '')) ?></td><td><?= e($bytes($row['size_bytes'] ?? 0)) ?></td><td><?= (int) ($row['duration_seconds'] ?? 0) ?>s</td><td><?= e((string) ($row['video_codec'] ?? '—')) ?> / <?= e((string) ($row['audio_codec'] ?? '—')) ?></td><td><span class="admin-badge admin-badge-<?= e((string) ($row['status'] ?? '')) ?>"><?= e((string) ($row['status'] ?? '')) ?></span></td></tr>
        <?php else: ?>
            <tr><td><strong><?= e((string) ($row['name'] ?? 'Projeto')) ?></strong><small>#<?= (int) ($row['id'] ?? 0) ?></small></td><td><strong><?= e((string) ($row['user_name'] ?? 'Conta')) ?></strong><small><?= e((string) ($row['user_email'] ?? '')) ?></small></td><td><span class="admin-badge admin-badge-<?= e((string) ($row['status'] ?? '')) ?>"><?= e((string) ($row['status'] ?? '')) ?></span></td><td><?= max(0, min(100, (int) ($row['progress'] ?? 0))) ?>%</td><td><?= (int) ($row['original_duration_seconds'] ?? 0) ?>s</td><td><?= e($bytes($row['storage_bytes'] ?? 0)) ?></td><td><?= e((string) ($row['updated_at'] ?? '')) ?></td></tr>
        <?php endif; ?>
    <?php endforeach; ?></tbody></table></div>
    <nav class="admin-pagination" aria-label="Paginação"><span>Página <?= $current ?> de <?= $last ?></span><?php if ($current > 1): ?><a rel="prev" href="<?= e($pageUrl($current - 1)) ?>">Anterior</a><?php endif; ?><?php if ($current < $last): ?><a rel="next" href="<?= e($pageUrl($current + 1)) ?>">Próxima</a><?php endif; ?></nav>
</section>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/../layouts/admin.php';
