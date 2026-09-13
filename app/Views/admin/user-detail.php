<?php
declare(strict_types=1);
use App\Core\Csrf;
$csrf=Csrf::token(); ob_start();
?>
<section class="admin-page-head"><div><p class="admin-eyebrow">Conta #<?= (int)$user['id'] ?></p><h2><?= e((string)$user['name']) ?></h2><p><?= e((string)$user['email']) ?> · <?= e((string)$user['plan_name']) ?></p></div><a href="/admin/usuarios">Voltar aos usuários</a></section>
<?php if(is_array($quota??null)): ?>
<section class="admin-panel"><h2>Consumo atual do plano</h2><div class="admin-metrics">
<article><span>Minutos neste mês (UTC)</span><strong><?= (int)$quota['minutes_used'] ?> / <?= (int)$quota['plan']['monthly_minutes'] ?> min</strong><small><?= (int)$quota['minutes_remaining'] ?> minutos disponíveis</small></article>
<article><span>Créditos disponíveis</span><strong><?= (int)$quota['credits'] ?></strong><small>Saldo do livro de créditos</small></article>
<article><span>Armazenamento utilizado</span><strong><?= e(number_format((int)$quota['storage_bytes']/1048576,2,',','.')) ?> MB</strong><small><?= e(number_format((int)$quota['storage_remaining_bytes']/1048576,2,',','.')) ?> MB disponíveis</small></article>
</div></section>
<?php endif; ?>
<?php if(is_array($financial??null)): $money=static fn(int $cents):string=>'R$ '.number_format($cents/100,2,',','.'); ?>
<section class="admin-panel"><h2>Resumo financeiro</h2><p>Produção · BRL. Valores líquidos de reembolsos; créditos não são receita.</p><div class="admin-metrics">
<article><span>Receita líquida total</span><strong><?= e($money($financial['net_total_cents'])) ?></strong></article>
<article><span>Receita líquida mensal</span><strong><?= e($money($financial['net_month_cents'])) ?></strong><small>Pagamentos do mês UTC</small></article>
<article><span>Assinaturas ativas</span><strong><?= (int)$financial['subscriptions_active'] ?></strong><small><?= (int)$financial['subscriptions_total'] ?> no total</small></article>
<article><span>Pagamentos confirmados</span><strong><?= (int)$financial['payments_paid'] ?></strong><small><?= (int)$financial['payments_pending'] ?> pendentes · <?= (int)$financial['payments_failed'] ?> falharam</small></article>
</div><p><a href="/admin/financeiro?user=<?= (int)$user['id'] ?>">Abrir financeiro deste usuário</a></p></section>
<section class="admin-panel"><h2>Pagamentos recentes</h2><p>Até 10 registros de produção em BRL.</p>
<?php if(($billingHistory['payments']??[])===[]): ?><p class="admin-empty">Nenhum pagamento registrado.</p><?php else: ?>
<div class="admin-table-wrap"><table><thead><tr><th>Registro</th><th>Plano</th><th>Gateway</th><th>Status</th><th>Valor líquido</th><th>Pagamento</th></tr></thead><tbody>
<?php foreach($billingHistory['payments'] as $row): ?><tr data-billing-payment="<?= (int)$row['id'] ?>"><td>#<?= (int)$row['id'] ?></td><td><?= e((string)$row['plan_name']) ?></td><td><?= e((string)$row['provider']) ?></td><td><?= e((string)$row['status']) ?></td><td><?= e($money((int)$row['paid_amount_cents']-(int)$row['refunded_amount_cents'])) ?></td><td><?= e((string)($row['paid_at']??'Não confirmado')) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>
<section class="admin-panel"><h2>Assinaturas recentes</h2><p>Até 10 registros de produção em BRL.</p>
<?php if(($billingHistory['subscriptions']??[])===[]): ?><p class="admin-empty">Nenhuma assinatura registrada.</p><?php else: ?>
<div class="admin-table-wrap"><table><thead><tr><th>Registro</th><th>Plano</th><th>Gateway</th><th>Status</th><th>Valor contratado</th><th>Fim do período</th></tr></thead><tbody>
<?php foreach($billingHistory['subscriptions'] as $row): ?><tr data-billing-subscription="<?= (int)$row['id'] ?>"><td>#<?= (int)$row['id'] ?></td><td><?= e((string)$row['plan_name']) ?></td><td><?= e((string)$row['provider']) ?></td><td><?= e((string)$row['status']) ?></td><td><?= e($money((int)$row['amount_cents'])) ?></td><td><?= e((string)($row['current_period_ends_at']??'Não confirmado')) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>
<?php endif; ?>
<section class="admin-panel"><h2>Editar conta</h2><form class="admin-form-grid" method="post" action="/admin/usuarios/<?= (int)$user['id'] ?>"><input type="hidden" name="_token" value="<?= e($csrf) ?>"><label>Nome<input required name="name" value="<?= e((string)$user['name']) ?>"></label><label>E-mail<input required type="email" name="email" value="<?= e((string)$user['email']) ?>"></label><label>Papel<select name="role"><option value="user"<?= $user['role']==='user'?' selected':'' ?>>Usuário</option><option value="admin"<?= $user['role']==='admin'?' selected':'' ?>>Administrador</option></select></label><label>Motivo<input required name="reason" maxlength="255"></label><button class="admin-button">Salvar alterações</button></form></section>
<section class="admin-panel"><h2>Histórico de créditos</h2><div class="admin-table-wrap" role="region" aria-label="Histórico de créditos da conta" tabindex="0"><table><thead><tr><th>Data</th><th>Variação</th><th>Saldo</th><th>Motivo</th></tr></thead><tbody><?php if($user['ledger']===[]): ?><tr><td colspan="4" class="admin-empty">Nenhum lançamento de crédito registrado.</td></tr><?php endif; ?><?php foreach($user['ledger'] as $row): ?><tr><td><?= e((string)$row['created_at']) ?></td><td><?= (int)$row['amount'] ?></td><td><?= (int)$row['balance_after'] ?></td><td><?= e((string)$row['description']) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="admin-panel"><h2>Projetos recentes</h2><div class="admin-table-wrap" role="region" aria-label="Projetos recentes da conta" tabindex="0"><table><thead><tr><th>Projeto</th><th>Status</th><th>Progresso</th></tr></thead><tbody><?php if($user['projects']===[]): ?><tr><td colspan="3" class="admin-empty">Esta conta ainda não criou projetos.</td></tr><?php endif; ?><?php foreach($user['projects'] as $row): ?><tr><td><?= e((string)$row['name']) ?></td><td><?= e((string)$row['status']) ?></td><td><?= (int)$row['progress'] ?>%</td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="admin-panel"><h2>Atividades</h2><?php if($user['activity']===[]): ?><p class="admin-empty">Nenhuma atividade registrada para esta conta.</p><?php endif; ?><?php foreach($user['activity'] as $row): ?><p><?= e((string)$row['created_at']) ?> · <?= e((string)$row['public_message']) ?></p><?php endforeach; ?></section>
<section class="admin-panel admin-danger-panel"><h2><?= $user['archived_at']===null?'Arquivar conta':'Restaurar conta' ?></h2><p>Esta ação preserva histórico, mídia e registros financeiros.</p><form class="admin-form-grid" method="post" action="/admin/usuarios/<?= (int)$user['id'] ?>/<?= $user['archived_at']===null?'arquivar':'restaurar' ?>"><input type="hidden" name="_token" value="<?= e($csrf) ?>"><label>Motivo<input required name="reason" maxlength="255"></label><label>Confirme digitando <?= $user['archived_at']===null?'ARQUIVAR':'RESTAURAR' ?><input required name="confirm"></label><button class="admin-button<?= $user['archived_at']===null?' admin-button-danger':'' ?>" type="submit"><?= $user['archived_at']===null?'Arquivar reversivelmente':'Restaurar conta' ?></button></form></section>
<?php $content=(string)ob_get_clean(); require __DIR__.'/../layouts/admin.php';
