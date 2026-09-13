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
    <title>Entrar — ClipForge</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/design-system.css">
</head>
<body class="auth-body">
<a class="skip-link" href="#auth-content">Pular para o conteúdo</a>
<main class="auth-card auth-studio-card" id="auth-content">
    <?php require __DIR__ . '/studio-intro.php'; ?>
    <div class="auth-form-panel">
    <h1>Entre na sua conta</h1>
    <p>Seu estúdio está aqui. Retome seus projetos e dê forma ao próximo clipe.</p>
    <?php if ($message !== null): ?><p class="notice"><?= e($message) ?></p><?php endif; ?>
    <?php if (isset($errors['form'])): ?><p class="error" id="form-error" role="alert"><?= e($errors['form']) ?></p><?php endif; ?>
    <form method="post" action="/login">
        <input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">
        <label for="email">E-mail</label>
        <input id="email" name="email" type="email" value="<?= e($old['email'] ?? '') ?>" autocomplete="email" required<?= isset($errors['form']) ? ' aria-invalid="true" aria-describedby="form-error" autofocus' : '' ?>>
        <label for="password">Senha</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required<?= isset($errors['form']) ? ' aria-invalid="true" aria-describedby="form-error"' : '' ?>>
        <button type="submit">Entrar</button>
    </form>
    <p><a href="/esqueci-minha-senha">Esqueceu sua senha?</a></p>
    <p>Ainda não tem uma conta? <a href="/cadastro">Crie sua conta</a>.</p>
    </div>
</main>
</body>
</html>
