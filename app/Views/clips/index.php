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
$project = is_array($library['project'] ?? null) ? $library['project'] : null;
$projectQuery = $project !== null ? '&projeto=' . (int) $project['id'] : '';
$pageUrl = static function (string $selectedFilter, int $selectedPage) use ($projectQuery): string {
    return '/clips?filter=' . $selectedFilter . '&page=' . $selectedPage . $projectQuery;
};
$groups = [];
foreach ($items as $candidate) {
    if (!is_array($candidate) || !is_int($candidate['project_id'] ?? null)) {
        continue;
    }
    $groups[$candidate['project_id']] ??= [
        'name' => $text($candidate['project_name'] ?? null, 'Projeto sem nome'),
        'source' => $text($candidate['source_name'] ?? null, 'Origem de vídeo'),
        'items' => [],
    ];
    $groups[$candidate['project_id']]['items'][] = $candidate;
}
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
<link rel="stylesheet" href="/assets/css/studio-polish.css">
<link rel="stylesheet" href="/assets/css/clips-library.css">
<div class="clip-library clip-shelf">
    <header class="clip-shelf-header" aria-labelledby="clip-library-title">
        <div>
            <p class="eyebrow">Biblioteca</p>
            <h2 id="clip-library-title"><?= $project !== null ? e((string) $project['name']) : 'Seus clipes' ?></h2>
            <p><?= $project !== null ? 'Cortes gerados a partir deste projeto.' : 'Assista, baixe ou ajuste os cortes de todos os seus projetos.' ?></p>
        </div>
        <div class="clip-shelf-header-actions">
            <span class="clip-shelf-count"><?= $total === 1 ? '1 clipe' : $total . ' clipes' ?></span>
            <a class="button button-small" href="/projetos/novo"><i data-lucide="plus" aria-hidden="true"></i>Novo projeto</a>
        </div>
    </header>

    <section class="clip-shelf-toolbar" aria-label="Controles da biblioteca">
        <nav class="clip-shelf-filters" aria-label="Filtrar clipes">
            <?php foreach ($filters as $value => $label): ?>
                <a href="<?= e('/clips?filter=' . $value . $projectQuery) ?>" aria-current="<?= $filter === $value ? 'page' : 'false' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="clip-shelf-toolbar-end">
            <?php if ($project !== null): ?><a class="clip-shelf-chip" href="<?= e('/clips?filter=' . $filter) ?>" aria-label="Remover filtro do projeto"><i data-lucide="folder-open" aria-hidden="true"></i><?= e((string) $project['name']) ?><i data-lucide="x" aria-hidden="true"></i></a><?php endif; ?>
            <a class="clip-shelf-refresh" href="<?= e('/clips?filter=' . $filter . $projectQuery) ?>"><i data-lucide="refresh-cw" aria-hidden="true"></i>Atualizar</a>
        </div>
    </section>

    <?php if ($items === []): ?>
        <section class="clip-shelf-empty" aria-labelledby="clip-library-empty-title">
            <span class="clip-shelf-empty-icon" aria-hidden="true"><i data-lucide="scissors"></i></span>
            <h3 id="clip-library-empty-title"><?= e($emptyTitles[$filter]) ?></h3>
            <p><?= e($emptyMessages[$filter]) ?></p>
            <div class="clip-shelf-empty-actions">
                <a class="button button-small" href="/projetos/novo"><i data-lucide="plus" aria-hidden="true"></i>Criar projeto</a>
                <a class="clip-shelf-link" href="/projetos">Abrir projetos</a>
            </div>
        </section>
    <?php else: ?>
        <?php foreach ($groups as $groupProjectId => $group): ?>
        <section class="clip-group" aria-labelledby="clip-group-<?= (int) $groupProjectId ?>">
            <header class="clip-group-header">
                <div><h3 id="clip-group-<?= (int) $groupProjectId ?>"><?= e($group['name']) ?></h3><span><?= count($group['items']) === 1 ? '1 corte' : count($group['items']) . ' cortes' ?> · <?= e($group['source']) ?></span></div>
                <a class="clip-shelf-link" href="/projetos/<?= (int) $groupProjectId ?>">Abrir projeto <i data-lucide="arrow-up-right" aria-hidden="true"></i></a>
            </header>
            <div class="clip-grid">
            <?php foreach ($group['items'] as $clip):
                $id = is_int($clip['id'] ?? null) ? $clip['id'] : 0;
                $projectId = is_int($clip['project_id'] ?? null) ? $clip['project_id'] : 0;
                if ($id <= 0 || $projectId <= 0) { continue; }
                $status = is_string($clip['status'] ?? null) && array_key_exists($clip['status'], $statusLabels)
                    ? $clip['status']
                    : 'suggested';
                $titleText = $text($clip['title'] ?? null, 'Clipe ' . $id);
                $hook = $text($clip['hook'] ?? null, 'Gancho não informado.');
                $reason = $text($clip['reason'] ?? null, 'Motivo não informado.');
                $category = $text($clip['category'] ?? null, 'Sem categoria');
                $score = is_int($clip['viral_score'] ?? null) ? max(0, min(100, $clip['viral_score'])) : 0;
                $aspect = is_string($clip['output_aspect_ratio'] ?? null) && array_key_exists($clip['output_aspect_ratio'], $aspectLabels)
                    ? $clip['output_aspect_ratio']
                    : 'original';
                $isPollable = in_array($status, $pollableStatuses, true);
                $hasThumbnail = $status === 'completed' && ($clip['has_thumbnail'] ?? false) === true;
                $hasDownload = $status === 'completed' && ($clip['has_download'] ?? false) === true;
                $duration = $formatDuration($clip['display_duration_seconds'] ?? null);
            ?>
                <article class="clip-card clip-status-<?= e($status) ?>" aria-labelledby="clip-title-<?= $id ?>" data-clip-card="<?= $id ?>"<?= $isPollable ? ' data-clip-status-url="/api/clips/' . $id . '/status"' : '' ?>>
                    <div class="clip-card-media" data-clip-media>
                        <span class="clip-card-placeholder" aria-hidden="true"><i data-lucide="clapperboard"></i></span>
                        <img class="clip-card-thumbnail" alt="" loading="lazy" decoding="async" data-clip-thumbnail<?= $hasThumbnail ? ' src="/clips/' . $id . '/thumbnail"' : ' hidden' ?>>
                        <button type="button" class="clip-card-play" data-clip-preview="/clips/<?= $id ?>/preview" aria-label="Assistir ao corte <?= e($titleText) ?>"<?= $hasDownload ? '' : ' hidden' ?>><i data-lucide="play" aria-hidden="true"></i></button>
                        <span class="clip-card-status" data-clip-status><?= e($statusLabels[$status]) ?></span>
                        <span class="clip-card-score" title="Pontuação estimada pela IA (não garante viralização)" aria-label="Pontuação estimada: <?= $score ?> de 100"><i data-lucide="sparkles" aria-hidden="true"></i><?= $score ?></span>
                        <span class="clip-card-duration" aria-hidden="true"><?= e($duration) ?></span>
                    </div>
                    <div class="clip-card-body">
                        <h4 id="clip-title-<?= $id ?>"><?= e($titleText) ?></h4>
                        <dl class="clip-card-facts">
                            <div><dt>Duração</dt><dd><?= e($duration) ?></dd></div>
                            <div><dt>Formato</dt><dd><?= e($aspectLabels[$aspect]) ?></dd></div>
                            <div><dt>Categoria</dt><dd><?= e($category) ?></dd></div>
                        </dl>
                        <p class="clip-card-message" data-clip-message aria-live="polite"><?= e($statusMessages[$status]) ?></p>
                        <details class="clip-card-why">
                            <summary>Por que este corte?</summary>
                            <p><strong>Gancho:</strong> <?= e($hook) ?></p>
                            <p><?= e($reason) ?></p>
                            <p class="clip-card-updated">Atualizado em <?= e($formatDate($clip['updated_at'] ?? null)) ?></p>
                        </details>
                        <div class="clip-card-actions">
                            <a class="button button-small clip-card-download" data-clip-download<?= $hasDownload ? ' href="/clips/' . $id . '/download"' : ' hidden' ?>><i data-lucide="download" aria-hidden="true"></i>Baixar</a>
                            <a class="button button-small button-secondary" href="/clips/<?= $id ?>/editar"><i data-lucide="sliders-horizontal" aria-hidden="true"></i>Editar</a>
                            <?php if (!$hasDownload): ?><a class="clip-shelf-link" href="/projetos/<?= $projectId ?>">Abrir projeto</a><?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
            </div>
        </section>
        <?php endforeach; ?>

        <?php if ($lastPage > 1): ?>
            <nav class="clip-shelf-pagination" aria-label="Paginação dos clipes">
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

        <p class="clip-shelf-note" role="note"><i data-lucide="sparkles" aria-hidden="true"></i>A pontuação é uma estimativa da IA e não garante viralização.</p>
        <script src="/assets/js/clip-preview.js" defer></script>
        <?php if ($hasPollableClips): ?><script src="/assets/js/clip-status.js" defer></script><?php endif; ?>
    <?php endif; ?>
</div>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/../layouts/app.php';
