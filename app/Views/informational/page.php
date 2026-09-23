<?php

declare(strict_types=1);

/** @var string $page */
/** @var string $title */
/** @var string $introduction */
/** @var list<array{id:string,title:string,paragraphs:list<string>}> $sections */
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= e($introduction) ?>">
    <title><?= e($title) ?> — ClipLab</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="icon" href="/assets/images/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/informational.css">
    <link rel="stylesheet" href="/assets/css/design-system.css">
</head>
<body class="information-body">
<a class="skip-link" href="#conteudo">Pular para o conteúdo</a>
<header class="information-header">
    <nav class="information-shell information-navigation" aria-label="Navegação principal">
        <a class="brand" href="/" aria-label="ClipLab, início"><?php $logoId='information-header-logo'; require dirname(__DIR__).'/components/logo.php'; ?></a>
        <div class="information-links">
            <a href="/privacidade"<?= $page==='privacy' ? ' aria-current="page"' : '' ?>>Privacidade</a>
            <a href="/termos"<?= $page==='terms' ? ' aria-current="page"' : '' ?>>Termos</a>
            <a class="button button-small" href="/login">Entrar</a>
        </div>
    </nav>
</header>
<main id="conteudo" class="information-shell" tabindex="-1">
    <header class="information-intro">
        <p class="eyebrow">Transparência no processo</p>
        <h1><?= e($title) ?></h1>
        <p class="information-lede"><?= e($introduction) ?></p>
        <p class="information-date">Versão informativa · <time datetime="2026-09-06">6 de setembro de 2026</time></p>
    </header>
    <div class="information-grid">
        <nav class="information-contents" aria-label="Nesta página">
            <h2>Nesta página</h2>
            <ol>
                <?php foreach ($sections as $section): ?>
                    <li><a href="#<?= e($section['id']) ?>"><?= e($section['title']) ?></a></li>
                <?php endforeach; ?>
            </ol>
        </nav>
        <div class="information-article">
            <?php foreach ($sections as $section): ?>
                <section id="<?= e($section['id']) ?>" aria-labelledby="<?= e($section['id']) ?>-title">
                    <h2 id="<?= e($section['id']) ?>-title"><?= e($section['title']) ?></h2>
                    <?php foreach ($section['paragraphs'] as $paragraph): ?><p><?= e($paragraph) ?></p><?php endforeach; ?>
                </section>
            <?php endforeach; ?>
            <?php if ($page==='privacy'): ?>
                <aside class="information-note" aria-labelledby="fontes-title">
                    <h2 id="fontes-title">Documentação dos serviços externos</h2>
                    <p>Consulte as fontes do provedor para conhecer as condições que podem se aplicar ao processamento:</p>
                    <ul>
                        <li><a href="https://ai.google.dev/gemini-api/terms" rel="noreferrer">Termos adicionais da API Gemini (Google)</a></li>
                        <li><a href="https://github.com/google-ai-edge/mediapipe#privacy-notice" rel="noreferrer">Métricas do SDK MediaPipe (Google)</a></li>
                    </ul>
                </aside>
            <?php endif; ?>
            <div class="information-next">
                <a class="text-link" href="<?= $page==='privacy' ? '/termos' : '/privacidade' ?>"><?= $page==='privacy' ? 'Ler os termos de uso' : 'Ler sobre privacidade' ?> <span aria-hidden="true">→</span></a>
                <a class="text-link" href="#conteudo">Voltar ao início <span aria-hidden="true">↑</span></a>
            </div>
        </div>
    </div>
</main>
<footer class="site-footer information-footer">
    <div class="information-shell information-footer-content">
        <p>ClipLab · Conteúdo que continua em movimento.</p>
        <nav aria-label="Navegação do rodapé"><a href="/">Início</a><a href="/privacidade">Privacidade</a><a href="/termos">Termos</a></nav>
    </div>
</footer>
</body>
</html>
