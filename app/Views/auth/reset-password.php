<?php

declare(strict_types=1);

use App\Core\Csrf;

/** @var array<string, string> $errors */
/** @var string|null $message */
/** @var string $token */
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Redefina sua senha — ClipLab</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/design-system.css">
</head>
<body class="auth-body">
<a class="skip-link" href="#auth-content">Pular para o conteúdo</a>
<main class="auth-card" id="auth-content">
    <a class="brand" href="/" aria-label="ClipLab, início"><?php $logoId = 'auth-logo'; require __DIR__ . '/../components/logo.php'; ?></a>
    <h1>Defina uma nova senha</h1>
    <p>Use uma senha nova com pelo menos 12 caracteres.</p>
    <?php if (isset($errors['form'])): ?><p class="error" id="form-error" role="alert"><?= e($errors['form']) ?></p><?php endif; ?>
    <form method="post" action="/redefinir-senha">
        <input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <label for="password">Nova senha</label>
        <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required<?= isset($errors['form']) ? ' aria-invalid="true" aria-describedby="form-error" autofocus' : '' ?>>
        <label for="password_confirmation">Confirme a nova senha</label>
        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required<?= isset($errors['form']) ? ' aria-invalid="true" aria-describedby="form-error"' : '' ?>>
        <button type="submit">Redefinir senha</button>
    </form>
    <p><a href="/login">Voltar para entrar</a>.</p>
</main>
</body>
</html>
