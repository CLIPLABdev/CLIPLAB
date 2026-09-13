<?php
declare(strict_types=1);
use App\Core\Csrf;
ob_start();
?>
<section class="panel profile-panel" aria-labelledby="confirm-email-title"><p class="eyebrow">Segurança da conta</p><h2 id="confirm-email-title">Confirmar novo e-mail</h2>
<?php if ($token===''): ?><p class="form-error" role="alert">Este link não é válido. Volte ao perfil e solicite uma nova confirmação.</p><a class="button" href="/perfil">Voltar ao perfil</a>
<?php else: ?><p>Abrir o link não muda sua conta. Confirme abaixo para substituir o e-mail de acesso. O link é exclusivo, expira em 30 minutos e só pode ser usado uma vez.</p><form method="post" action="/perfil/confirmar-email" data-platform-form><input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="token" value="<?= e($token) ?>"><button class="button" type="submit">Confirmar meu novo e-mail</button> <a class="text-link" href="/perfil">Agora não</a></form><?php endif; ?>
</section>
<?php
$content=(string)ob_get_clean();
require __DIR__.'/../layouts/app.php';
