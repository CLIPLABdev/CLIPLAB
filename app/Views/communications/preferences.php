<?php declare(strict_types=1);use App\Core\Csrf;ob_start(); ?>
<link rel="stylesheet" href="/assets/css/communications.css">
<div class="communications-preferences">
<section class="account-hero"><div><p class="eyebrow">Do seu jeito</p><h2>Escolha o que receber.</h2><p>Controle separadamente os avisos por e-mail e dentro do app.</p></div><a href="/notificacoes">Ver notificações</a></section>
<?php if($message): ?><p class="communications-feedback" role="status"><?= e($message) ?></p><?php endif; ?>
<section class="panel"><h2>Preferências de comunicação</h2><form class="communications-form" method="post" action="/preferencias" data-platform-form><input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">
<?php foreach(['processing'=>'Processamento de vídeos e cortes','usage'=>'Consumo e limites do plano'] as $category=>$label): ?>
<fieldset class="communications-choice"><legend><?= e($label) ?></legend><label><input type="checkbox" name="<?= e($category) ?>_email" value="1" <?= $preferences[$category.'_email']?'checked':'' ?>><span>Receber por e-mail</span></label><label><input type="checkbox" name="<?= e($category) ?>_in_app" value="1" <?= $preferences[$category.'_in_app']?'checked':'' ?>><span>Mostrar dentro do app</span></label></fieldset>
<?php endforeach; ?>
<fieldset class="communications-choice"><legend>Novidades e ofertas</legend><label><input type="checkbox" name="marketing_opt_in" value="1" <?= $preferences['marketing_opt_in']?'checked':'' ?>><span>Quero receber novidades e promoções por e-mail.</span></label><p>Marketing exige sua autorização explícita. Você pode retirar essa autorização a qualquer momento.</p></fieldset>
<div class="communications-required"><p>Comunicações de segurança, conta e pagamentos são obrigatórias e continuam ativas.</p><p>Desativar avisos opcionais também cancela e-mails ainda aguardando envio; notificações já entregues permanecem no histórico.</p></div><button class="button" type="submit" data-submit-label="Salvando preferências…">Salvar preferências</button></form></section>
<section class="panel communications-verification"><h2>Confirmar meu endereço</h2><p>Se seu e-mail ainda não foi confirmado, solicite um novo link. Ele expira em uma hora.</p><form method="post" action="/verificar-email/reenviar" data-platform-form><input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>"><button class="button button-small" type="submit" data-submit-label="Solicitando confirmação…">Solicitar confirmação</button></form></section>
</div>
<?php $content=(string)ob_get_clean();require __DIR__.'/../layouts/app.php'; ?>
