<?php
declare(strict_types=1);
use App\Core\Csrf;
$labels=[
    'auth.welcome'=>'Boas-vindas','auth.password_reset'=>'Redefinir senha','auth.email_verification'=>'Verificar e-mail',
    'account.email_change_requested'=>'Confirmar novo e-mail','account.email_changed'=>'E-mail alterado','account.password_changed'=>'Senha alterada',
    'media.processing_completed'=>'Projeto concluído','media.processing_failed'=>'Falha no processamento','media.usage_limit_reached'=>'Limite do plano',
    'billing.payment_approved'=>'Pagamento aprovado','billing.payment_pending'=>'Pagamento pendente','billing.payment_failed'=>'Pagamento recusado',
    'billing.subscription_created'=>'Nova assinatura','billing.subscription_renewed'=>'Assinatura renovada','billing.subscription_canceled'=>'Assinatura cancelada',
    'billing.subscription_changed'=>'Assinatura atualizada','billing.refund_processed'=>'Reembolso processado','marketing.campaign'=>'Campanha de e-mail',
];
$categories=['account'=>'Conta e acesso','billing'=>'Financeiro','processing'=>'Projetos','usage'=>'Uso do plano','marketing'=>'Campanhas'];
$active=count(array_filter($templates,static fn($t)=>(bool)$t['is_active']));
$eventCount=count(array_unique(array_column($templates,'event')));
$selectedEvent=$editing['event']??array_key_first($events);
ob_start();
?>
<link rel="stylesheet" href="/assets/css/email-studio.css">
<div class="email-studio" data-email-studio>
<section class="admin-page-head email-studio-head"><div><p class="admin-eyebrow">Comunicação / Estúdio de e-mails</p><h2>Cada mensagem,<br>uma boa experiência.</h2><p>Do primeiro acesso ao próximo projeto. Cuide do texto, confira a prévia e publique a versão certa.</p></div><div class="email-head-actions"><a class="admin-button" href="#email-editor">Criar nova versão <span aria-hidden="true">↗</span></a><a class="email-text-link" href="/admin/email-configuracao">Configurar entrega SMTP <span aria-hidden="true">→</span></a></div></section>
<?php if($message): ?><p class="admin-flash" role="status"><?= e($message) ?></p><?php endif; ?>
<section class="email-stats" aria-label="Resumo dos modelos"><div><span>Eventos com modelos</span><strong><?= $eventCount ?></strong></div><div><span>Versões ativas</span><strong><?= $active ?></strong></div><div><span>Rascunhos para revisar</span><strong><?= count($templates)-$active ?></strong></div></section>
<section class="email-library" aria-labelledby="email-library-title">
<header class="email-section-heading"><div><p class="admin-eyebrow">Biblioteca de mensagens</p><h2 id="email-library-title">Seu tom, em cada momento.</h2></div><span class="email-count" data-email-count aria-live="polite"><?= count($templates) ?> versões</span></header>
<div class="email-filters" data-email-filters hidden><label>Buscar modelo<input type="search" placeholder="Nome, assunto ou evento" data-email-search></label><label>Categoria<select data-email-category><option value="">Todas as categorias</option><?php foreach($categories as $key=>$label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?></select></label><label>Status do modelo<select data-email-status><option value="">Todas as versões</option><option value="active">Ativas</option><option value="draft">Rascunhos</option></select></label><button class="admin-button admin-button-quiet" type="button" data-email-clear>Limpar filtros</button></div>
<p class="email-empty" data-email-empty hidden>Nenhum modelo encontrado. Ajuste a busca ou limpe os filtros.</p>
<div class="email-gallery">
<?php foreach($templates as $t): $category=$events[$t['event']]['category']??'account';$label=$labels[$t['event']]??$t['event']; ?>
<article class="email-card" data-email-card data-category="<?= e($category) ?>" data-status="<?= $t['is_active']?'active':'draft' ?>" data-search="<?= e($label.' '.$t['event'].' '.$t['subject_template']) ?>">
<div class="email-card-heading"><span class="email-category"><?= e($categories[$category]??$category) ?></span><span class="email-badge <?= $t['is_active']?'email-badge-active':'email-badge-draft' ?>"><?= $t['is_active']?'Ativo':'Rascunho' ?> · v<?= (int)$t['version'] ?></span></div>
<h3><?= e($label) ?></h3><p class="email-card-subject"><?= e($t['subject_template']) ?></p><code class="email-event-code"><?= e($t['event']) ?></code>
<a class="email-edit-link" href="/admin/emails?editar=<?= (int)$t['id'] ?>#email-editor">Editar como nova versão <span aria-hidden="true">↗</span></a>
<details class="email-card-detail"><summary>Visualizar e ações</summary><div class="email-preview-controls" data-email-controls hidden aria-label="Tamanho da prévia"><button type="button" data-email-size="desktop" aria-pressed="true">Desktop</button><button type="button" data-email-size="mobile" aria-pressed="false">Celular</button></div><div class="email-preview" data-email-preview data-size="desktop"><iframe title="Prévia de <?= e($label) ?>, versão <?= (int)$t['version'] ?>" src="/admin/emails/<?= (int)$t['id'] ?>/preview" sandbox="" loading="lazy" width="100%" height="520" referrerpolicy="no-referrer"></iframe></div><p class="email-preview-note">Prévia com dados de exemplo. O conteúdo pode variar entre clientes de e-mail.</p>
<div class="email-card-actions"><form method="post" action="/admin/emails/<?= (int)$t['id'] ?>/duplicar"><input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>"><button class="admin-button admin-button-quiet" type="submit">Duplicar rascunho</button></form><?php if(!$t['is_active']): ?><form method="post" action="/admin/emails/<?= (int)$t['id'] ?>/ativar"><input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>"><button class="admin-button" type="submit">Ativar esta versão</button></form><?php endif; ?></div>
<form class="email-test-action" method="post" action="/admin/email-configuracao/testar"><input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="template_id" value="<?= (int)$t['id'] ?>"><button class="email-text-link" type="submit">Enviar teste real para meu e-mail <span aria-hidden="true">→</span></button><small>Usa o SMTP configurado e envia apenas para você.</small></form></details>
</article>
<?php endforeach; ?>
</div></section>
<section class="admin-panel email-editor" id="email-editor" aria-labelledby="email-editor-title">
<header class="email-section-heading"><div><p class="admin-eyebrow">Texto e conteúdo</p><h2 id="email-editor-title"><?= $editing?((int)$editing['version']===0?'Personalize a sugestão ClipLab':'Uma nova versão de v'.(int)$editing['version']):'Escreva a próxima versão.' ?></h2></div><span class="email-badge email-badge-draft">Será salvo como rascunho</span></header>
<p class="email-editor-intro">Salvar cria uma nova versão. Para usá-la nos próximos envios, revise a prévia e ative o rascunho na biblioteca.</p>
<form class="email-system-picker" method="get" action="/admin/emails#email-editor"><label>Começar com uma sugestão ClipLab<select name="modelo"><?php foreach($events as $event=>$definition): ?><option value="<?= e($event) ?>" <?= $selectedEvent===$event?'selected':'' ?>><?= e($labels[$event]??$event) ?></option><?php endforeach; ?></select></label><button class="admin-button admin-button-quiet" type="submit">Carregar sugestão</button><p>Abre o texto no editor. Nenhuma versão ativa é substituída.</p></form>
<form class="admin-form-grid email-editor-form" method="post" action="/admin/emails" data-platform-form>
<input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">
<label>Evento da mensagem<select name="event" required data-email-event><?php foreach($events as $event=>$definition): ?><option value="<?= e($event) ?>" <?= $selectedEvent===$event?'selected':'' ?>><?= e($labels[$event]??$event) ?></option><?php endforeach; ?></select></label>
<label>Assunto<input name="subject_template" maxlength="255" required placeholder="O primeiro texto que a pessoa vai ler" value="<?= e($editing['subject_template']??'') ?>"></label>
<label class="email-code-field">Conteúdo HTML seguro<small>Escreva o conteúdo. A identidade visual é aplicada automaticamente no envio.</small><textarea name="html_template" rows="12" required spellcheck="false"><?= e($editing['html_template']??'<p>Olá, {{nome_usuario}}.</p>') ?></textarea></label>
<label class="email-code-field">Versão em texto simples<small>Mantenha a mesma mensagem e inclua os endereços dos links por extenso.</small><textarea name="text_template" rows="12" required><?= e($editing['text_template']??'Olá, {{nome_usuario}}.') ?></textarea></label>
<aside class="email-variable-help"><h3>Campos que se adaptam a cada pessoa</h3><p>Copie as variáveis exatamente como aparecem. Ao trocar o evento, revise os campos usados no assunto e nas duas versões.</p><?php foreach($events as $event=>$definition): ?><div data-email-variables="<?= e($event) ?>"><strong><?= e($labels[$event]??$event) ?></strong><p class="email-token-list"><?php foreach($definition['variables'] as $variable): ?><code>{{<?= e($variable) ?>}}</code><?php endforeach; ?></p></div><?php endforeach; ?></aside>
<details class="email-markup-help"><summary>Quais elementos posso usar no HTML?</summary><p>Parágrafos, títulos, listas, tabelas e links HTTPS. Imagens, estilos, scripts e conteúdo ativo não são aceitos. As variáveis são tratadas como texto para proteger cada mensagem.</p></details>
<div class="email-save-row"><button class="admin-button" type="submit">Salvar nova versão <span aria-hidden="true">↗</span></button><span>A ativação é uma etapa separada.</span></div>
</form></section></div>
<script src="/assets/js/email-studio.js" defer></script>
<?php $content=(string)ob_get_clean();require __DIR__.'/../layouts/admin.php'; ?>
