<?php

declare(strict_types=1);

/** @var array{id:int,name:string,email:string,credits:int,plan_name:string,monthly_minutes:int} $user */
/** @var array{items:list<array<string,mixed>>,filter:string,page:int,per_page:int,total:int,last_page:int} $library */

$filters = [
    'recent' => 'Recentes',
    'processing' => 'Em processamento',
    'completed' => 'Concluídos',
    'failed' => 'Falhas',
];
$statusLabels = [
    'suggested' => 'Sugestão',
    'approved' => 'Aprovado',
    'queued' => 'Na fila',
    'rendering' => 'Renderizando',
    'completed' => 'Concluído',
    'failed' => 'Falhou',
];
$statusMessages = [
    'suggested' => 'Pronto para revisar e aprovar no projeto.',
    'approved' => 'Aprovado e aguardando a fila de renderização.',
    'queued' => 'Aguardando o início da renderização.',
    'rendering' => 'Gerando o arquivo final para download.',
    'completed' => 'Arquivo final pronto para download.',
    'failed' => 'Não foi possível concluir o render. Abra o projeto para tentar novamente.',
];
$modeLabels = [
    'original' => 'Original',
    'center' => 'Centralizado',
    'manual' => 'Foco manual',
    'auto' => 'Automático',
];
$aspectLabels = [
    'original' => 'Original',
    '9:16' => '9:16',
    '1:1' => '1:1',
    '16:9' => '16:9',
    '4:5' => '4:5',
];
$pollableStatuses = ['approved', 'queued', 'rendering'];
$items = is_array($library['items'] ?? null) ? $library['items'] : [];
$filter = is_string($library['filter'] ?? null) && array_key_exists($library['filter'], $filters)
    ? $library['filter']
    : 'recent';
$total = max(0, is_int($library['total'] ?? null) ? $library['total'] : 0);
$lastPage = max(1, is_int($library['last_page'] ?? null) ? $library['last_page'] : 1);
$page = max(1, min($lastPage, is_int($library['page'] ?? null) ? $library['page'] : 1));
$hasPollableClips = false;
foreach ($items as $candidate) {
    if (is_array($candidate) && in_array($candidate['status'] ?? null, $pollableStatuses, true)) {
        $hasPollableClips = true;
        break;
    }
}

$text = static function (mixed $value, string $fallback): string {
    return is_string($value) && trim($value) !== '' ? $value : $fallback;
};
$formatDuration = static function (mixed $value): string {
    if ($value === null || $value === '') { return 'A confirmar'; }
    $seconds = is_int($value) || is_float($value) ? max(0, (int) round($value)) : 0;
    if ($seconds >= 3600) {
        return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
};
$formatDate = static function (mixed $value): string {
    if (!is_string($value) || trim($value) === '') {
        return 'Data indisponível';
    }
    try {
        return (new \DateTimeImmutable($value))->format('d/m/Y \à\s H:i');
    } catch (\Throwable) {
        return $value;
    }
};
$pageUrl = static function (string $selectedFilter, int $selectedPage): string {
    return '/clips?filter=' . $selectedFilter . '&page=' . $selectedPage;
};
$emptyTitles = [
    'recent' => 'Sua biblioteca ainda está vazia',
    'processing' => 'Nenhum clipe em processamento',
    'completed' => 'Nenhum clipe concluído',
    'failed' => 'Nenhuma falha de renderização',
];
$emptyMessages = [
    'recent' => 'Os cortes das análises mais recentes dos seus projetos aparecerão aqui.',
    'processing' => 'Quando você aprovar um corte, acompanhe a renderização por este filtro.',
    'completed' => 'Finalize a renderização de um corte para liberar thumbnail e download.',
    'failed' => 'Se um render falhar, ele aparecerá aqui com acesso ao projeto para nova tentativa.',
];

ob_start();
?>
<link rel="stylesheet" href="/assets/css/clips.css">
<link rel="stylesheet" href="/assets/css/studio-polish.css">
<div class="clip-library">
    <section class="clip-library-hero" aria-labelledby="clip-library-title">
        <div class="clip-library-heading">
            <p class="eyebrow">Biblioteca / Clipes</p>
            <h2 id="clip-library-title">Seu conteúdo, em novos cortes.</h2>
            <p>Revise, continue editando ou baixe seus vídeos. Todos os projetos se encontram aqui.</p>
        </div>
        <div class="clip-library-summary" aria-label="Resumo da biblioteca">
            <span><?= $total === 1 ? '1 clipe' : $total . ' clipes' ?></span>
            <a href="<?= e('/clips?filter=' . $filter) ?>"><i data-lucide="refresh-cw" aria-hidden="true"></i>Atualizar</a>
        </div>
    </section>

    <section class="clip-library-toolbar" aria-label="Controles da biblioteca">
        <nav class="clip-library-filters" aria-label="Filtrar clipes">
            <?php foreach ($filters as $value => $label): ?>
                <a href="<?= e('/clips?filter=' . $value) ?>" aria-current="<?= $filter === $value ? 'page' : 'false' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <p class="clip-library-range">Página <?= $page ?> de <?= $lastPage ?></p>
    </section>

    <p class="clip-score-note" role="note"><i data-lucide="circle-alert" aria-hidden="true"></i>A pontuação é uma estimativa da IA e não garante viralização.</p>

    <?php if ($items === []): ?>
        <section class="clip-library-empty" aria-labelledby="clip-library-empty-title">
            <span class="clip-empty-icon" aria-hidden="true"><i data-lucide="scissors"></i></span>
            <p class="eyebrow"><?= e($filters[$filter]) ?></p>
            <h3 id="clip-library-empty-title"><?= e($emptyTitles[$filter]) ?></h3>
            <p><?= e($emptyMessages[$filter]) ?></p>
            <div class="clip-empty-actions">
                <a class="button button-small" href="/projetos/novo"><i data-lucide="plus" aria-hidden="true"></i>Criar projeto</a>
                <a class="clip-secondary-action" href="/projetos">Abrir projetos</a>
            </div>
        </section>
    <?php else: ?>
        <section class="clip-library-grid" aria-label="Clipes da biblioteca">
            <?php foreach ($items as $clip):
                if (!is_array($clip)) { continue; }
                $id = is_int($clip['id'] ?? null) ? $clip['id'] : 0;
                $projectId = is_int($clip['project_id'] ?? null) ? $clip['project_id'] : 0;
                if ($id <= 0 || $projectId <= 0) { continue; }
                $status = is_string($clip['status'] ?? null) && array_key_exists($clip['status'], $statusLabels)
                    ? $clip['status']
                    : 'suggested';
                $titleText = $text($clip['title'] ?? null, 'Clipe ' . $id);
                $projectName = $text($clip['project_name'] ?? null, 'Projeto sem nome');
                $sourceName = $text($clip['source_name'] ?? null, 'Origem de vídeo');
                $hook = $text($clip['hook'] ?? null, 'Gancho não informado.');
                $reason = $text($clip['reason'] ?? null, 'Motivo não informado.');
                $category = $text($clip['category'] ?? null, 'Sem categoria');
                $score = is_int($clip['viral_score'] ?? null) ? max(0, min(100, $clip['viral_score'])) : 0;
                $aspect = is_string($clip['output_aspect_ratio'] ?? null) && array_key_exists($clip['output_aspect_ratio'], $aspectLabels)
                    ? $clip['output_aspect_ratio']
                    : 'original';
                $mode = is_string($clip['reframe_mode'] ?? null) && array_key_exists($clip['reframe_mode'], $modeLabels)
                    ? $clip['reframe_mode']
                    : 'original';
                $isPollable = in_array($status, $pollableStatuses, true);
                $hasThumbnail = $status === 'completed' && ($clip['has_thumbnail'] ?? false) === true;
                $hasDownload = $status === 'completed' && ($clip['has_download'] ?? false) === true;
            ?>
                <article class="clip-library-card clip-status-<?= e($status) ?>" aria-labelledby="clip-title-<?= $id ?>" data-clip-card="<?= $id ?>"<?= $isPollable ? ' data-clip-status-url="/api/clips/' . $id . '/status"' : '' ?>>
                    <div class="clip-media">
                        <div class="clip-media-placeholder" aria-hidden="true"><i data-lucide="play"></i></div>
                        <img class="clip-thumbnail" alt="Prévia do corte <?= e($titleText) ?>" loading="lazy" decoding="async" data-clip-thumbnail<?= $hasThumbnail ? ' src="/clips/' . $id . '/thumbnail"' : ' hidden' ?>>
                        <div class="clip-media-topline">
                            <span class="clip-status" data-clip-status><?= e($statusLabels[$status]) ?></span>
                            <span class="clip-score" aria-label="Pontuação estimada: <?= $score ?> de 100"><strong><?= $score ?></strong><small>/100</small></span>
                        </div>
                    </div>
                    <div class="clip-card-content">
                        <div class="clip-card-heading">
                            <p class="clip-source"><i data-lucide="folder-open" aria-hidden="true"></i><?= e($projectName) ?> <span aria-hidden="true">·</span> <?= e($sourceName) ?></p>
                            <h3 id="clip-title-<?= $id ?>"><?= e($titleText) ?></h3>
                        </div>

                        <dl class="clip-meta">
                            <div><dt>Duração</dt><dd><i data-lucide="clock-3" aria-hidden="true"></i><?= e($formatDuration($clip['display_duration_seconds'] ?? null)) ?></dd></div>
                            <div><dt>Atualizado</dt><dd><i data-lucide="calendar-days" aria-hidden="true"></i><?= e($formatDate($clip['updated_at'] ?? null)) ?></dd></div>
                            <div><dt>Formato</dt><dd><?= e($aspectLabels[$aspect]) ?> · <?= e($modeLabels[$mode]) ?></dd></div>
                        </dl>

                        <div class="clip-insights">
                            <section aria-label="Gancho do clipe"><span>Gancho</span><p class="clip-copy"><?= e($hook) ?></p></section>
                            <section aria-label="Motivo da recomendação"><span>Por que a IA sugeriu</span><p class="clip-copy"><?= e($reason) ?></p></section>
                        </div>

                        <div class="clip-card-footer">
                            <span class="clip-category"><?= e($category) ?></span>
                            <p data-clip-message aria-live="polite"><?= e($statusMessages[$status]) ?></p>
                            <div class="clip-card-actions">
                                <a class="button button-small button-secondary" href="/clips/<?= $id ?>/editar">Editar corte</a>
                                <a class="clip-project-action" href="/projetos/<?= $projectId ?>">Abrir projeto <i data-lucide="arrow-up-right" aria-hidden="true"></i></a>
                                <a class="button button-small" data-clip-download<?= $hasDownload ? ' href="/clips/' . $id . '/download"' : ' hidden' ?>><i data-lucide="download" aria-hidden="true"></i>Baixar MP4</a>
                            </div>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <?php if ($lastPage > 1): ?>
            <nav class="clip-pagination" aria-label="Paginação dos clipes">
                <?php if ($page > 1): ?>
                    <a href="<?= e($pageUrl($filter, $page - 1)) ?>" rel="prev"><i data-lucide="arrow-left" aria-hidden="true"></i>Anterior</a>
                <?php else: ?>
                    <span aria-disabled="true"><i data-lucide="arrow-left" aria-hidden="true"></i>Anterior</span>
                <?php endif; ?>
                <strong><span class="visually-hidden">Página atual: </span><?= $page ?> <small>de <?= $lastPage ?></small></strong>
                <?php if ($page < $lastPage): ?>
                    <a href="<?= e($pageUrl($filter, $page + 1)) ?>" rel="next">Próxima<i data-lucide="arrow-right" aria-hidden="true"></i></a>
                <?php else: ?>
                    <span aria-disabled="true">Próxima<i data-lucide="arrow-right" aria-hidden="true"></i></span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>

        <?php if ($hasPollableClips): ?><script src="/assets/js/clip-status.js" defer></script><?php endif; ?>
    <?php endif; ?>
</div>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/../layouts/app.php';
