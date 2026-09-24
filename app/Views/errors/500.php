<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Erro inesperado | ClipLab</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/design-system.css">
</head>
<body class="error-body">
    <main class="error-card">
        <a class="brand" href="/" aria-label="ClipLab, início"><span class="brand-mark" aria-hidden="true"><img src="/assets/images/logo-symbol.svg" alt=""></span><span class="brand-wordmark">ClipLab</span></a>
        <p class="eyebrow">500 / Pausa no estúdio</p>
        <h1>Ocorreu um erro inesperado.</h1>
        <p>Tente novamente em alguns instantes.</p>
        <?php if ($correlationId !== null): ?>
            <p>Código de referência: <?= e($correlationId) ?></p>
        <?php endif; ?>
        <?php if ($debug && $exception instanceof Throwable): ?>
            <section>
                <h2>Detalhes de depuração</h2>
                <p><?= e($exception::class) ?>: <?= e($exception->getMessage()) ?></p>
                <pre><?= e($exception->getFile() . ':' . $exception->getLine() . "\n" . $exception->getTraceAsString()) ?></pre>
            </section>
        <?php endif; ?>
        <p><a href="/">Voltar ao início</a></p>
    </main>
</body>
</html>
