<?php
declare(strict_types=1);
use App\Core\Csrf;
$csrf=Csrf::token();
ob_start();
?>
<section class="admin-page-head"><div><p class="admin-eyebrow">Comunicação dentro do produto</p><h2>Banners, avisos e novidades</h2><p>Escolha o público e o período. O usuário pode dispensar a mensagem; uma edição permite apresentá-la novamente.</p></div></section>
<section class="admin-panel"><h2>Criar comunicado</h2><form class="admin-form-grid" method="post" action="/admin/promocoes" data-platform-form><input type="hidden" name="_token" value="<?= e($csrf) ?>"><?php $promotionForm=['is_active'=>1]; require __DIR__.'/promotion-fields.php'; ?><button class="admin-button" type="submit">Criar promoção</button></form></section>
<section class="admin-panel"><h2>Comunicados cadastrados <small>(<?= count($promotions) ?>)</small></h2>
<?php if ($promotions===[]): ?><div class="admin-empty"><h3>Uma novidade merece o momento certo.</h3><p>Crie o primeiro comunicado. Nenhum aviso será exibido antes de ser salvo como ativo.</p></div><?php endif; ?>
<?php foreach ($promotions as $promotion): ?>
<details class="admin-promotion-editor"><summary><strong><?= e($promotion['title']) ?></strong> <span><?= (int)$promotion['is_active']===1?'Ativa':'Pausada' ?></span> · Editar</summary><p><?= e($promotion['body']) ?></p>
<form class="admin-form-grid" method="post" action="/admin/promocoes/<?= (int)$promotion['id'] ?>" data-platform-form><input type="hidden" name="_token" value="<?= e($csrf) ?>"><?php $promotionForm=$promotion; require __DIR__.'/promotion-fields.php'; ?><button type="submit" class="admin-button">Salvar alterações</button></form>
<details class="admin-promotion-delete"><summary>Excluir este comunicado</summary><p>A exclusão remove apenas este aviso, sem afetar os usuários ou seus projetos. Para preservar o conteúdo, desmarque “Ativa” acima.</p><form method="post" action="/admin/promocoes/<?= (int)$promotion['id'] ?>/excluir" class="admin-form-grid" data-platform-form><input type="hidden" name="_token" value="<?= e($csrf) ?>"><label>Digite EXCLUIR para confirmar<input name="confirm" pattern="EXCLUIR" required autocomplete="off"></label><button type="submit" class="admin-button admin-button-small">Excluir comunicado</button></form></details>
</details>
<?php endforeach; ?>
</section>
<?php
$content=(string)ob_get_clean();
require __DIR__.'/../layouts/admin.php';
