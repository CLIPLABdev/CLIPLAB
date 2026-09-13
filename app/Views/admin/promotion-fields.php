<?php
declare(strict_types=1);
$selected=static fn(string $key,string $value,string $default=''):string=>(string)($promotionForm[$key]??$default)===$value?' selected':'';
$dateValue=static fn(mixed $value):string=>is_string($value)?substr(str_replace(' ','T',$value),0,16):'';
?>
<label>Título<input name="title" maxlength="120" required value="<?= e($promotionForm['title']??'') ?>"></label>
<label>Mensagem<textarea name="body" maxlength="500" rows="3" required><?= e($promotionForm['body']??'') ?></textarea></label>
<label>Texto do botão<input name="cta_label" maxlength="60" value="<?= e($promotionForm['cta_label']??'') ?>" placeholder="Conhecer meu plano"></label>
<label>Link do botão<input name="cta_url" maxlength="255" value="<?= e($promotionForm['cta_url']??'') ?>" placeholder="/conta/plano ou https://…"></label>
<label>Imagem local<input name="image_url" maxlength="255" value="<?= e($promotionForm['image_url']??'') ?>" placeholder="/assets/images/campanha.png"><small>PNG, JPG ou WebP já disponível em assets/images; imagens ausentes não serão exibidas.</small></label>
<label>Apresentação<select name="delivery_kind"><option value="banner"<?= $selected('delivery_kind','banner','banner') ?>>Banner no painel</option><option value="notice"<?= $selected('delivery_kind','notice') ?>>Aviso discreto</option><option value="popup"<?= $selected('delivery_kind','popup') ?>>Janela com opção de fechar</option></select></label>
<label>Onde exibir<select name="placement"><option value="dashboard"<?= $selected('placement','dashboard','dashboard') ?>>Visão geral</option><option value="projects"<?= $selected('placement','projects') ?>>Projetos</option><option value="account"<?= $selected('placement','account') ?>>Conta e perfil</option></select></label>
<label>Público<select name="audience"><option value="all"<?= $selected('audience','all','all') ?>>Todos os usuários ativos</option><option value="plan"<?= $selected('audience','plan') ?>>Plano específico</option><option value="user"<?= $selected('audience','user') ?>>Usuário específico</option></select></label>
<label>Plano de destino<select name="plan_id"><option value="">Selecionar quando o público for plano</option><?php foreach($plans as $plan): ?><option value="<?= (int)$plan['id'] ?>"<?= $selected('plan_id',(string)$plan['id']) ?>><?= e($plan['name']) ?></option><?php endforeach; ?></select></label>
<label>ID do usuário de destino<input type="number" name="user_id" min="1" step="1" value="<?= e((string)($promotionForm['user_id']??'')) ?>"><small>Somente quando o público for um usuário específico.</small></label>
<label>Início (UTC)<input type="datetime-local" name="starts_at" value="<?= e($dateValue($promotionForm['starts_at']??null)) ?>"><small>Em branco: disponível imediatamente.</small></label>
<label>Fim (UTC)<input type="datetime-local" name="ends_at" value="<?= e($dateValue($promotionForm['ends_at']??null)) ?>"><small>Em branco: sem data de encerramento.</small></label>
<label><input type="checkbox" name="is_active" value="1"<?= !empty($promotionForm['is_active'])?' checked':'' ?>> Ativa — exibir dentro da janela configurada</label>
