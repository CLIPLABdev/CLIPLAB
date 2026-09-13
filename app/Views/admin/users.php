<?php

declare(strict_types=1);

use App\Core\Csrf;

/** @var string $mode */
/** @var array<string,mixed> $page */
/** @var list<array<string,mixed>> $plans */
$mode = ($mode ?? 'users') === 'credits' ? 'credits' : 'users';
$items = is_array($page['items'] ?? null) ? $page['items'] : [];
$total = max(0, (int) ($page['total'] ?? 0));
$current = max(1, (int) ($page['page'] ?? 1));
$last = max(1, (int) ($page['last_page'] ?? 1));
$filters = is_array($page['filters'] ?? null) ? $page['filters'] : [];
$csrf = Csrf::token();
$base = $mode === 'credits' ? '/admin/creditos' : '/admin/usuarios';
$pageUrl = static function (int $page) use ($base, $filters): string {
    return $base . '?' . http_build_query($filters + ['page' => $page], '', '&', PHP_QUERY_RFC3986);
};
ob_start();
?>
<section class="admin-page-head"><div><p class="admin-eyebrow"><?= $mode === 'credits' ? 'Histórico de saldo' : 'Acesso e assinatura' ?></p><h2><?= $mode === 'credits' ? 'Cada crédito, registrado.' : 'Pessoas que movem a plataforma.' ?></h2><p><?= $total ?> <?= $mode === 'credits' ? 'lançamentos' : 'usuários' ?> · 25 por página</p></div></section>

<?php if ($mode === 'users'): ?><section class="admin-panel admin-form-panel"><h2>Criar usuário</h2><form class="admin-form-grid" method="post" action="/admin/usuarios"><input type="hidden" name="_token" value="<?= e($csrf) ?>"><label>Nome<input required maxlength="120" name="name"></label><label>E-mail<input required type="email" maxlength="254" name="email"></label><label>Senha temporária<input required type="password" minlength="12" name="password"></label><label>Plano<select required name="plan_id"><?php foreach($plans as $plan): ?><option value="<?= (int)$plan['id'] ?>"><?= e((string)$plan['name']) ?></option><?php endforeach; ?></select></label><label>Papel<select name="role"><option value="user">Usuário</option><option value="admin">Administrador</option></select></label><label>Motivo<input required maxlength="255" name="reason"></label><button class="admin-button">Criar usuário</button></form></section><?php endif; ?>

<?php if ($mode === 'credits'): ?>
<section class="admin-panel admin-form-panel" aria-labelledby="ajuste-creditos"><div class="admin-panel-head"><div><p class="admin-eyebrow">Ação auditada</p><h2 id="ajuste-creditos">Ajustar saldo</h2></div></div>
    <form class="admin-form-grid" method="post" action="/admin/creditos/ajustar">
        <input type="hidden" name="_token" value="<?= e($csrf) ?>">
        <label>ID do usuário<input required inputmode="numeric" pattern="[1-9][0-9]*" name="user_id"></label>
        <label>Quantidade (use negativo para débito)<input required inputmode="numeric" pattern="-?[0-9]+" name="amount"></label>
        <label class="admin-field-wide">Motivo obrigatório<input required maxlength="255" name="reason"></label>
        <button class="admin-button" type="submit">Registrar ajuste</button>
    </form>
</section>
<?php endif; ?>

<section class="admin-panel">
    <form class="admin-filter" method="get" action="<?= e($base) ?>">
        <label>Buscar<input name="q" value="<?= e((string) ($filters['q'] ?? '')) ?>" placeholder="Nome ou e-mail"></label>
        <?php if ($mode === 'users'): ?>
            <label>Status<select name="status"><option value="all">Todos</option><option value="active"<?= ($filters['status'] ?? '') === 'active' ? ' selected' : '' ?>>Ativos</option><option value="suspended"<?= ($filters['status'] ?? '') === 'suspended' ? ' selected' : '' ?>>Suspensos</option></select></label>
            <label>Plano<select name="plan"><option value="">Todos</option><?php foreach ($plans as $plan): ?><option value="<?= (int) $plan['id'] ?>"<?= (string) ($filters['plan'] ?? '') === (string) $plan['id'] ? ' selected' : '' ?>><?= e((string) $plan['name']) ?></option><?php endforeach; ?></select></label>
        <?php endif; ?>
        <button class="admin-button admin-button-secondary" type="submit">Filtrar</button>
    </form>
    <div class="admin-table-wrap" role="region" aria-label="<?= $mode === 'credits' ? 'Histórico de créditos' : 'Contas e ações' ?>" tabindex="0"><table><thead><tr>
        <?php if ($mode === 'credits'): ?><th>Data</th><th>Usuário</th><th>Tipo</th><th>Variação</th><th>Saldo</th><th>Motivo</th>
        <?php else: ?><th>Usuário</th><th>Função</th><th>Status</th><th>Plano</th><th>Créditos</th><th>Desde</th><th>Ações</th><?php endif; ?>
    </tr></thead><tbody>
    <?php if ($items === []): ?><tr><td colspan="<?= $mode === 'credits' ? 6 : 7 ?>" class="admin-empty"><strong>Nenhum registro encontrado.</strong><small>Revise a busca ou os filtros para encontrar outros resultados.</small></td></tr><?php endif; ?>
    <?php foreach ($items as $row): if (!is_array($row)) { continue; } ?>
        <?php if ($mode === 'credits'): ?>
            <tr><td><?= e((string) ($row['created_at'] ?? '')) ?></td><td><strong><?= e((string) ($row['user_name'] ?? 'Conta')) ?></strong><small><?= e((string) ($row['user_email'] ?? '')) ?></small></td><td><span class="admin-badge"><?= e((string) ($row['type'] ?? '')) ?></span></td><td><?= (int) ($row['amount'] ?? 0) ?></td><td><?= (int) ($row['balance_after'] ?? 0) ?></td><td><?= e((string) ($row['description'] ?? '—')) ?></td></tr>
        <?php else: ?>
            <tr><td><strong><?= e((string) ($row['name'] ?? 'Conta')) ?></strong><small><?= e((string) ($row['email'] ?? '')) ?> · #<?= (int) ($row['id'] ?? 0) ?></small></td><td><?= e((string) ($row['role'] ?? 'user')) ?></td><td><span class="admin-badge admin-badge-<?= e((string) ($row['status'] ?? '')) ?>"><?= e((string) ($row['status'] ?? '')) ?></span></td><td><?= e((string) ($row['plan_name'] ?? '')) ?></td><td><?= (int) ($row['credits'] ?? 0) ?></td><td><?= e((string) ($row['created_at'] ?? '')) ?></td><td class="admin-actions-cell"><a href="/admin/usuarios/<?= (int)$row['id'] ?>">Ver detalhe</a>
                <form method="post" action="/admin/usuarios/<?= (int) $row['id'] ?>/status"><input type="hidden" name="_token" value="<?= e($csrf) ?>"><input type="hidden" name="status" value="<?= ($row['status'] ?? '') === 'active' ? 'suspended' : 'active' ?>"><label>Motivo<span class="sr-only"> para alterar status de <?= e((string) ($row['name'] ?? 'usuário')) ?></span><input required maxlength="255" name="reason"></label><button class="admin-button admin-button-small<?= ($row['status'] ?? '') === 'active' ? ' admin-button-danger' : '' ?>" type="submit"><?= ($row['status'] ?? '') === 'active' ? 'Suspender' : 'Reativar' ?></button></form>
                <form method="post" action="/admin/usuarios/<?= (int) $row['id'] ?>/plano"><input type="hidden" name="_token" value="<?= e($csrf) ?>"><label>Novo plano<select name="plan_id" required><?php foreach ($plans as $plan): ?><option value="<?= (int) $plan['id'] ?>"<?= (int) ($row['plan_id'] ?? 0) === (int) $plan['id'] ? ' selected' : '' ?>><?= e((string) $plan['name']) ?></option><?php endforeach; ?></select></label><label>Motivo<input required maxlength="255" name="reason"></label><button class="admin-button admin-button-small admin-button-secondary" type="submit">Alterar plano</button></form>
            </td></tr>
        <?php endif; ?>
    <?php endforeach; ?>
    </tbody></table></div>
    <nav class="admin-pagination" aria-label="Paginação"><span>Página <?= $current ?> de <?= $last ?></span><?php if ($current > 1): ?><a rel="prev" href="<?= e($pageUrl($current - 1)) ?>">Anterior</a><?php endif; ?><?php if ($current < $last): ?><a rel="next" href="<?= e($pageUrl($current + 1)) ?>">Próxima</a><?php endif; ?></nav>
</section>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/../layouts/admin.php';
