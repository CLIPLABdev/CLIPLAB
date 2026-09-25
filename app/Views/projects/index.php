<?php

declare(strict_types=1);

/** @var array{id: int, name: string, email: string, credits: int, plan_name: string, monthly_minutes: int} $user */
/** @var array<int, array<string, mixed>> $projects */
/** @var bool $created */

$formatDuration = static function (mixed $seconds): string {
    if ($seconds === null || $seconds === '') { return 'A confirmar'; }
    $seconds = max(0, (int) $seconds);
    return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
};
$formatBytes = static function (mixed $bytes): string {
    if ($bytes === null || $bytes === '') { return 'A confirmar'; }
    $bytes = max(0, (int) $bytes);
    if ($bytes < 1024) { return $bytes . ' B'; }
    return number_format($bytes / 1024 / 1024, 1, ',', '.') . ' MB';
};
$terminalStatuses = ['ready', 'suggestions_ready', 'completed', 'awaiting_credits', 'failed'];
$suggestionStatuses = ['suggestions_ready', 'rendering', 'completed'];
$hasPollableProjects = false;
foreach ($projects as $candidate) {
    if (!in_array((string) ($candidate['status'] ?? 'queued'), $terminalStatuses, true)) {
        $hasPollableProjects = true;
        break;
    }
}
$sourceLabels = ['upload' => 'Envio de arquivo', 'direct_url' => 'Importação por URL'];
$statusLabels = [
    'receiving' => 'Recebendo',
    'queued' => 'Na fila',
    'fetching' => 'Baixando',
    'probing' => 'Analisando',
    'ready' => 'Pronto',
    'ai_queued' => 'Na fila da IA',
    'uploading_ai' => 'Enviando para a IA',
    'waiting_ai_file' => 'Preparando o vídeo na IA',
    'analyzing' => 'Analisando com IA',
    'identifying_clips' => 'Identificando cortes',
    'suggestions_ready' => 'Sugestões prontas',
    'rendering' => 'Renderizando',
    'completed' => 'Concluído',
    'awaiting_credits' => 'Aguardando créditos',
    'failed' => 'Falhou',
];
$statusMessages = [
    'receiving' => 'Seu vídeo está chegando ao estúdio.',
    'queued' => 'Seu vídeo está na fila. O processamento começa em breve.',
    'fetching' => 'Buscando o vídeo no link que você enviou.',
    'probing' => 'Conferindo duração, imagem e áudio.',
    'ready' => 'Vídeo conferido. A origem está pronta para continuar.',
    'ai_queued' => 'Seu vídeo está na fila para análise da IA.',
    'uploading_ai' => 'Enviando seu vídeo para a IA analisar.',
    'waiting_ai_file' => 'A IA está preparando seu vídeo para leitura.',
    'analyzing' => 'A IA está procurando trechos com potencial para cortes.',
    'identifying_clips' => 'Organizando os trechos encontrados em sugestões de cortes.',
    'suggestions_ready' => 'Seus cortes sugeridos estão prontos para revisão.',
    'rendering' => 'Preparando o arquivo do seu corte.',
    'completed' => 'Seus cortes exportados estão prontos para baixar.',
    'awaiting_credits' => 'Adicione créditos para iniciar a análise deste projeto.',
    'failed' => 'Não foi possível validar a origem do vídeo.',
];
ob_start();
?>
<link rel="stylesheet" href="/assets/css/projects.css">
<link rel="stylesheet" href="/assets/css/studio-polish.css">
<div class="studio-projects">
<section class="projects-intro" aria-labelledby="projetos-titulo">
    <div><p class="eyebrow">Biblioteca / Projetos</p><h2 id="projetos-titulo">Toda criação começa aqui.</h2><p>Acompanhe seus vídeos, revise as sugestões e continue de onde parou.</p></div>
    <a class="button" href="/projetos/novo"><i data-lucide="plus" aria-hidden="true"></i>Novo projeto</a>
</section>
<div class="studio-library-heading"><h3>Seus projetos <span><?= count($projects) ?></span></h3><a class="text-link" href="/clips">Ver meus clipes <i data-lucide="arrow-up-right" aria-hidden="true"></i></a></div>
<?php if ($created): ?><p class="form-notice" role="status">Projeto enviado. A validação e a análise começarão em breve.</p><?php endif; ?>
<?php if ($projects === []): ?>
    <section class="empty-state project-empty-state" aria-labelledby="biblioteca-vazia"><span class="empty-icon"><i data-lucide="film" aria-hidden="true"></i></span><h3 id="biblioteca-vazia">Nenhum projeto por enquanto</h3><p>Quando você enviar um vídeo ou informar uma URL direta, ele aparecerá nesta biblioteca.</p><a class="button button-small" href="/projetos/novo">Criar novo projeto</a></section>
<?php else: ?>
    <?php if ($hasPollableProjects): ?><p class="visually-hidden" aria-live="polite" aria-atomic="true" data-project-status-live></p><?php endif; ?>
    <section class="project-card-grid" aria-label="Projetos na biblioteca">
        <?php foreach ($projects as $project):
            $id = (int) ($project['id'] ?? 0);
            $status = (string) ($project['status'] ?? 'queued');
            if (!array_key_exists($status, $statusLabels)) { $status = 'queued'; }
            $sourceType = (string) ($project['source_type'] ?? 'upload');
            $progress = max(0, min(100, (int) ($project['progress'] ?? 0)));
            $projection = (new \App\Services\ProjectStatusService(static fn (): array => $project))
                ->forOwnedProject($id, (int) $user['id']);
        ?>
            <article class="library-project-card" data-project-id="<?= $id ?>"<?= in_array($status, $terminalStatuses, true) ? '' : ' data-project-status-url="/api/projects/' . $id . '/status"' ?>>
                <div class="library-project-icon" aria-hidden="true"><i data-lucide="film"></i></div>
                <div class="library-project-heading"><div><p class="project-source-label"><?= e($sourceLabels[$sourceType] ?? 'Origem de vídeo') ?></p><h3><?= e((string) ($project['name'] ?? 'Projeto sem nome')) ?></h3></div><span class="status-pill status-<?= e($status) ?>" data-project-status-text><?= e($projection['stage'] ?? $statusLabels[$status]) ?></span></div>
                <p class="project-status-message" data-project-status-message><?= e($projection['message'] ?? $statusMessages[$status]) ?></p>
                <dl class="project-meta"><div><dt>Duração</dt><dd><?= e($formatDuration($project['duration_seconds'] ?? null)) ?></dd></div><div><dt>Tamanho</dt><dd><?= e($formatBytes($project['size_bytes'] ?? null)) ?></dd></div><div><dt>Criado em</dt><dd><?= e((string) ($project['created_at'] ?? 'Data indisponível')) ?></dd></div></dl>
                <div class="project-progress"><div><span>Progresso</span><strong data-project-progress-value><?= $progress ?>%</strong></div><progress value="<?= $progress ?>" max="100" data-project-progress><?= $progress ?>%</progress></div>
                <div class="project-card-actions">
                    <?php if ($status === 'awaiting_credits'): ?><a class="button button-small" href="/projetos/<?= $id ?>">Retomar análise</a><?php endif; ?>
                    <?php if ($status === 'failed' && in_array($project['error_code'] ?? null, ['ai_unavailable','ai_timeout','ai_rate_limited'], true)): ?><a class="button button-small" href="/projetos/<?= $id ?>">Revisar análise</a><?php endif; ?>
                    <a class="button button-small project-clips-link" data-project-clips-link<?= in_array($status, $suggestionStatuses, true) ? ' href="/clips?projeto=' . $id . '"' : ' hidden' ?>><i data-lucide="scissors" aria-hidden="true"></i>Ver cortes</a>
                    <a class="project-suggestions-link text-link" data-project-suggestions-link<?= in_array($status, $suggestionStatuses, true) ? ' href="/projetos/' . $id . '"' : ' hidden' ?>>Ver sugestões</a>
                    <?php if (!in_array($status, $terminalStatuses, true)): ?><a class="project-refresh-link" href="/projetos">Atualizar status</a><?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
    <?php if ($hasPollableProjects): ?><script src="/assets/js/project-status.js" defer></script><?php endif; ?>
<?php endif; ?>
</div>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/../layouts/app.php';
