<?php declare(strict_types=1);use App\Core\Csrf;ob_start(); ?>
<section class="account-hero"><div><p class="eyebrow">Sua conta em dia</p><h2>Novidades do seu espaço.</h2><p>Acompanhe atualizações da conta, pagamentos e processamento.</p></div><a class="button button-small" href="/preferencias">Minhas preferências</a></section>
<?php if($message): ?><p role="status"><?= e($message) ?></p><?php endif; ?>
<?php if($items===[]): ?><section class="panel"><h2>Tudo tranquilo por aqui.</h2><p>Quando houver uma atualização, ela aparecerá nesta central.</p><a href="/dashboard">Voltar ao painel</a></section><?php endif; ?>
<?php foreach($items as $item): ?><article class="panel"><h2><?= e($item['title']) ?></h2><p><?= e($item['body']) ?></p><p><time><?= e($item['created_at']) ?></time> · <?= $item['read_at']?'Lida':'Não lida' ?></p><?php if(!$item['read_at']): ?><form method="post" action="/notificacoes/<?= (int)$item['id'] ?>/ler"><input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>"><button class="button button-small">Marcar como lida</button></form><?php endif; ?></article><?php endforeach; ?>
<?php $content=(string)ob_get_clean();require __DIR__.'/../layouts/app.php'; ?>
