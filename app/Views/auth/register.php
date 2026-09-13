<?php

declare(strict_types=1);

use App\Core\Csrf;

/** @var array<string, string> $errors */
/** @var array<string, string> $old */
/** @var string|null $message */
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Crie sua conta — ClipForge</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/design-system.css">
</head>
<body class="auth-body">
<a class="skip-link" href="#auth-content">Pular para o conteúdo</a>
<main class="auth-card auth-studio-card" id="auth-content">
    <?php require __DIR__ . '/studio-intro.php'; ?>
    <div class="auth-form-panel">
    <h1>Crie sua conta</h1>
    <p>Comece no plano Free e traga seu primeiro vídeo. Encontre cortes e ajuste a edição no seu ritmo.</p>
    <?php if (isset($errors['form'])): ?><p class="error" role="alert"><?= e($errors['form']) ?></p><?php endif; ?>
    <form method="post" action="/cadastro">
        <input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">
        <label for="name">Nome</label>
        <input id="name" name="name" value="<?= e($old['name'] ?? '') ?>" autocomplete="name" required<?= isset($errors['name']) ? ' aria-invalid="true" aria-describedby="name-error" autofocus' : '' ?>>
        <?php if (isset($errors['name'])): ?><p class="error" id="name-error" role="alert"><?= e($errors['name']) ?></p><?php endif; ?>
        <label for="email">E-mail</label>
        <input id="email" name="email" type="email" value="<?= e($old['email'] ?? '') ?>" autocomplete="email" required<?= isset($errors['email']) ? ' aria-invalid="true" aria-describedby="email-error"' . (!isset($errors['name']) ? ' autofocus' : '') : '' ?>>
        <?php if (isset($errors['email'])): ?><p class="error" id="email-error" role="alert"><?= e($errors['email']) ?></p><?php endif; ?>
        <label for="password">Senha</label>
        <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required<?= isset($errors['password']) ? ' aria-invalid="true" aria-describedby="password-error"' . (!isset($errors['name']) && !isset($errors['email']) ? ' autofocus' : '') : '' ?>>
        <label for="password_confirmation">Confirme a senha</label>
        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required<?= isset($errors['password']) ? ' aria-invalid="true" aria-describedby="password-error"' : '' ?>>
        <?php if (isset($errors['password'])): ?><p class="error" id="password-error" role="alert"><?= e($errors['password']) ?></p><?php endif; ?>
        <button type="submit">Criar conta grátis</button>
    </form>
    <p>Já tem uma conta? <a href="/login">Entre</a>.</p>
    <p class="auth-footnote"><a href="/privacidade">Privacidade</a> · <a href="/termos">Termos de uso</a></p>
    </div>
</main>
</body>
</html>
