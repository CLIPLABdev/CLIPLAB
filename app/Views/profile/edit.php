<?php
declare(strict_types=1);
use App\Core\Csrf;
$pendingEmail ??= null;
$hasAvatar ??= false;
$securityAvailable ??= false;
ob_start();
?>
<link rel="stylesheet" href="/assets/css/account.css">
<link rel="stylesheet" href="/assets/css/profile.css">
<section class="account-hero"><div><p class="eyebrow">Sua conta, seu estúdio</p><h2>Um perfil pronto para criar.</h2><p>Gerencie seus dados, proteja o acesso e acompanhe seu plano.</p></div><a class="button button-small" href="/conta/plano">Plano e consumo</a></section>
<?php if ($message!==null): ?><p class="form-notice" role="status"><?= e($message) ?></p><?php endif; ?>
<?php if ($errors!==[]): ?><div class="profile-alert" role="alert"><strong>Confira antes de continuar</strong><ul><?php foreach ($errors as $error): ?><li><?= e((string)$error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="profile-workspace">
    <div class="profile-main">
        <section class="panel profile-section" aria-labelledby="profile-details-title">
            <div class="profile-section-heading"><span class="profile-step">01</span><div><h2 id="profile-details-title">Dados pessoais</h2><p>O novo e-mail só será utilizado após sua confirmação.</p></div></div>
            <form method="post" action="/perfil" class="profile-form" data-platform-form>
                <input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">
                <label for="profile-name">Nome</label><input id="profile-name" name="name" type="text" value="<?= e($old['name']??$user['name']) ?>" autocomplete="name" minlength="2" maxlength="120" required>
                <label for="profile-email">E-mail de acesso</label><input id="profile-email" name="email" type="email" value="<?= e($old['email']??$user['email']) ?>" autocomplete="email" maxlength="254" required aria-describedby="email-help"<?= $securityAvailable?'':' readonly' ?>>
                <small id="email-help"><?php if($securityAvailable): ?>Endereço atual: <?= e($user['email']) ?>. Para trocar, informe sua senha atual e confirme o link recebido no novo endereço.<?php else: ?>A troca de e-mail e senha aguarda a ativação da integração de segurança. Você já pode atualizar seu nome; o endereço de acesso será preservado.<?php endif; ?></small>
                <?php if ($securityAvailable): ?><label for="profile-current-password">Senha atual <span>(somente ao trocar o e-mail)</span></label><input id="profile-current-password" name="current_password" type="password" autocomplete="current-password" maxlength="4096"><?php endif; ?>
                <div><button class="button" type="submit" data-submit-label="Salvando…">Salvar dados <i data-lucide="save" aria-hidden="true"></i></button></div>
            </form>
            <?php if ($pendingEmail!==null): ?><div class="profile-pending"><strong>Confirmação pendente</strong><p><?= e($pendingEmail) ?> ainda não substituiu seu endereço de acesso. O link expira em 30 minutos.</p><form method="post" action="/perfil/email/cancelar" data-platform-form><input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>"><button type="submit" class="button button-small button-secondary">Cancelar troca</button></form></div><?php endif; ?>
        </section>
        <?php if ($securityAvailable): ?>
        <section class="panel profile-section" aria-labelledby="profile-security-title">
            <div class="profile-section-heading"><span class="profile-step">02</span><div><h2 id="profile-security-title">Segurança</h2><p>Uma senha longa e exclusiva protege seus projetos.</p></div></div>
            <form method="post" action="/perfil/senha" class="profile-form" data-platform-form>
                <input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">
                <label for="security-current">Senha atual</label><input id="security-current" name="current_password" type="password" autocomplete="current-password" required maxlength="4096">
                <div class="profile-two-fields"><div><label for="security-new">Nova senha</label><input id="security-new" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required aria-describedby="password-help"></div><div><label for="security-confirm">Confirmar nova senha</label><input id="security-confirm" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="72" required></div></div>
                <small id="password-help">Use ao menos 12 caracteres (máximo de 72 bytes). Ao salvar, links pendentes de recuperação e troca de e-mail serão revogados.</small>
                <div><button class="button" type="submit" data-submit-label="Protegendo conta…">Alterar senha <i data-lucide="lock-keyhole" aria-hidden="true"></i></button></div>
            </form>
        </section>
        <section class="panel profile-section" aria-labelledby="profile-avatar-title">
            <div class="profile-section-heading"><span class="profile-step">03</span><div><h2 id="profile-avatar-title">Foto do perfil</h2><p>Uma imagem sua para reconhecer seu espaço.</p></div></div>
            <div class="profile-avatar-row"><div class="profile-avatar-preview"><?php if ($hasAvatar): ?><img src="/perfil/avatar" alt="Sua foto de perfil" width="88" height="88"><?php else: ?><span aria-hidden="true"><?= e(mb_strtoupper(mb_substr($user['name'],0,1))) ?></span><?php endif; ?></div><form method="post" action="/perfil/avatar" enctype="multipart/form-data" class="profile-form" data-platform-form><input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="MAX_FILE_SIZE" value="2097152"><label for="profile-avatar">Selecionar imagem PNG</label><input id="profile-avatar" name="avatar" type="file" accept="image/png,.png" required aria-describedby="avatar-help"><small id="avatar-help">PNG estático, até 2 MiB e 2048 × 2048 pixels. Sua imagem fica em armazenamento privado.</small><button type="submit" class="button button-small" data-submit-label="Enviando foto…">Salvar foto</button></form></div>
            <?php if ($hasAvatar): ?><form method="post" action="/perfil/avatar/remover" class="profile-remove-avatar" data-platform-form><input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>"><button type="submit" class="button button-small button-secondary">Remover foto do perfil</button></form><?php endif; ?>
        </section>
        <?php endif; ?>
    </div>
    <aside class="profile-side">
        <section class="panel account-summary"><p class="eyebrow">Seu plano</p><h2><?= e($user['plan_name']) ?></h2><dl><div><dt>Créditos disponíveis</dt><dd><?= (int)$user['credits'] ?></dd></div><div><dt>Minutos mensais</dt><dd><?= (int)$user['monthly_minutes'] ?></dd></div><div><dt>Conta</dt><dd><?= $user['status']==='active'?'Ativa':'Indisponível' ?></dd></div></dl><a class="text-link" href="/conta/creditos">Ver histórico de consumo →</a></section>
        <section class="panel profile-section"><p class="eyebrow">Privacidade</p><h2>Você no controle.</h2><p>Senha, plano e saldo nunca são alterados por campos ocultos do perfil. Ações sensíveis são validadas no servidor.</p><a class="text-link" href="/privacidade">Conhecer os controles →</a></section>
    </aside>
</div>
<?php
$content=(string)ob_get_clean();
require __DIR__.'/../layouts/app.php';
