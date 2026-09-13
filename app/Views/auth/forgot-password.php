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
    <title>Recupere sua senha — ClipForge</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/design-system.css">
</head>
<body class="auth-body">
<a class="skip-link" href="#auth-content">Pular para o conteúdo</a>
<main class="auth-card" id="auth-content">
    <a class="brand" href="/" aria-label="ClipForge, início"><?php $logoId = 'auth-logo'; require __DIR__ . '/../components/logo.php'; ?></a>
    <h1>Recupere sua senha</h1>
    <p>Enviaremos um link de redefinição se houver uma conta com este e-mail.</p>
    <?php if ($message !== null): ?><p class="notice" role="status"><?= e($message) ?></p><?php endif; ?>
    <?php if (isset($errors['form'])): ?><p class="error" id="form-error" role="alert"><?= e($errors['form']) ?></p><?php endif; ?>
    <form method="post" action="/esqueci-minha-senha">
        <input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">
        <label for="email">E-mail</label>
        <input id="email" name="email" type="email" value="<?= e($old['email'] ?? '') ?>" autocomplete="email" required<?= isset($errors['form']) ? ' aria-invalid="true" aria-describedby="form-error" autofocus' : '' ?>>
        <button type="submit">Enviar link de redefinição</button>
    </form>
    <p><a href="/login">Voltar para entrar</a>.</p>
</main>
</body>
</html>
