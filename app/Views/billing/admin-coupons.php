<?php
declare(strict_types=1);

use App\Core\Csrf;

$csrf = Csrf::token();
$planNames = [];
foreach ($plans as $plan) $planNames[(int) $plan['id']] = (string) $plan['name'];
ob_start();
?>
<section class="admin-page-head"><div><p class="admin-eyebrow">Descontos recorrentes</p><h2>Cupons</h2><p>Desativar preserva reservas e pagamentos já registrados.</p></div></section>
<section class="admin-panel"><form method="post" action="/admin/cupons" class="admin-form-grid">
<input type="hidden" name="_token" value="<?= e($csrf) ?>"><input type="hidden" name="plan_scope_present" value="1">
<label>Código<input required name="code"></label><label>Tipo<select name="discount_type"><option value="percent">Percentual</option><option value="fixed">Fixo em centavos</option></select></label><label>Valor<input required name="discount_value"></label><label>Limite global<input name="max_redemptions"></label><label>Por usuário<input required name="per_user_limit" value="1"></label><label>Início UTC<input name="starts_at" placeholder="YYYY-MM-DD HH:MM:SS"></label><label>Fim UTC<input name="ends_at" placeholder="YYYY-MM-DD HH:MM:SS"></label>
<?php foreach ($plans as $plan): ?><label><input type="checkbox" name="plan_ids[]" value="<?= (int) $plan['id'] ?>"> <?= e($plan['name']) ?></label><?php endforeach; ?>
<label><input type="checkbox" name="is_active" value="1" checked> Ativo</label><button class="admin-button">Salvar cupom</button></form></section>
<section class="admin-panel"><table><thead><tr><th>Código</th><th>Desconto</th><th>Elegibilidade</th><th>Status</th><th>Ação</th></tr></thead><tbody>
<?php foreach ($coupons as $coupon): $selected=array_map('intval',$coupon['plan_ids']??[]); ?>
<tr><td><?= e($coupon['code']) ?></td><td><?= e($coupon['discount_type']) ?> <?= (int) $coupon['discount_value'] ?></td><td><?php if ($selected===[]): ?>Todos os planos<?php else: ?><?php foreach ($selected as $id): ?><span data-coupon-plan="<?= $id ?>"><?= e($planNames[$id]??('Plano '.$id)) ?></span><?php endforeach; ?><?php endif; ?></td><td><?= (int) $coupon['is_active']?'ativo':'inativo' ?></td><td><details><summary>Editar</summary><form method="post" action="/admin/cupons/<?= (int) $coupon['id'] ?>" class="admin-form-grid">
<input type="hidden" name="_token" value="<?= e($csrf) ?>"><input type="hidden" name="plan_scope_present" value="1">
<label>Código<input required name="code" value="<?= e($coupon['code']) ?>"></label><label>Tipo<select name="discount_type"><option value="percent"<?= $coupon['discount_type']==='percent'?' selected':'' ?>>Percentual</option><option value="fixed"<?= $coupon['discount_type']==='fixed'?' selected':'' ?>>Fixo em centavos</option></select></label><label>Valor<input required name="discount_value" value="<?= (int) $coupon['discount_value'] ?>"></label><input type="hidden" name="currency" value="<?= e((string) ($coupon['currency']??'BRL')) ?>"><label>Limite global<input name="max_redemptions" value="<?= e((string) ($coupon['max_redemptions']??'')) ?>"></label><label>Por usuário<input required name="per_user_limit" value="<?= (int) $coupon['per_user_limit'] ?>"></label><label>Início UTC<input name="starts_at" value="<?= e((string) ($coupon['starts_at']??'')) ?>"></label><label>Fim UTC<input name="ends_at" value="<?= e((string) ($coupon['ends_at']??'')) ?>"></label>
<?php foreach ($plans as $plan): ?><label><input type="checkbox" name="plan_ids[]" value="<?= (int) $plan['id'] ?>"<?= in_array((int) $plan['id'],$selected,true)?' checked':'' ?>> <?= e($plan['name']) ?></label><?php endforeach; ?>
<label><input type="checkbox" name="is_active" value="1"<?= (int) $coupon['is_active']?' checked':'' ?>> Ativo</label><button class="admin-button">Salvar alterações</button></form></details></td></tr>
<?php endforeach; ?></tbody></table></section>
<?php $content=(string) ob_get_clean(); require __DIR__.'/../layouts/admin.php';
