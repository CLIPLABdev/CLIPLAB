<?php

declare(strict_types=1);

/** @var array{id: int, name: string, email: string, credits: int, plan_name: string, monthly_minutes: int, status: string} $user */
/** @var array{projects: int, processed: int, minutes: int, minutes_used: int, credits: int, storage_bytes: int, recent: array<int, array<string, int|string>>} $metrics */

$formatBytes = static function (int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    return number_format($bytes / 1024 / 1024, 1, ',', '.') . ' MB';
};
$monthlyMinutes = (int) $user['monthly_minutes'];
$statusLabels = [
    'draft' => 'Rascunho',
    'receiving' => 'Recebendo',
    'queued' => 'Na fila',
    'fetching' => 'Baixando',
    'probing' => 'Analisando',
    'ready' => 'Origem pronta',
    'ai_queued' => 'Na fila da IA',
    'uploading_ai' => 'Enviando para a IA',
    'waiting_ai_file' => 'Preparando na IA',
    'analyzing' => 'Analisando com IA',
    'identifying_clips' => 'Identificando cortes',
    'suggestions_ready' => 'Sugestões prontas',
    'rendering' => 'Renderizando',
    'awaiting_credits' => 'Aguardando créditos',
    'completed' => 'Concluído',
    'failed' => 'Falhou',
];
ob_start();
?>
<link rel="stylesheet" href="/assets/css/studio-polish.css">
<div class="studio-dashboard">
<section class="dashboard-intro" aria-labelledby="boas-vindas">
    <div class="studio-hero-copy"><p class="eyebrow">Seu estúdio / Visão geral</p><h2 id="boas-vindas">Olá, <?= e($user['name']) ?>.<br><span>Vamos ao próximo corte?</span></h2><p><?= (int) $metrics['projects'] === 0 ? 'Traga seu primeiro vídeo. A IA sugere os trechos, você decide o que merece virar um corte.' : 'Retome seus projetos ou traga uma nova ideia. Seu próximo vídeo começa aqui.' ?></p><div class="studio-hero-actions"><a class="button" href="/projetos/novo"><i data-lucide="plus" aria-hidden="true"></i>Novo projeto</a><a class="text-link" href="/clips">Ver meus clipes <i data-lucide="arrow-up-right" aria-hidden="true"></i></a></div></div>
    <div class="studio-hero-mark" aria-hidden="true"><span class="studio-frame studio-frame-one"></span><span class="studio-frame studio-frame-two"><i data-lucide="scissors"></i></span><span class="studio-mark-caption">CRIE. RECORTE. CONTE.</span></div>
</section>

<?php if ((int) $metrics['projects'] === 0): ?>
<section class="studio-journey" aria-labelledby="studio-journey-title">
    <div class="studio-journey-heading"><h2 id="studio-journey-title">Do vídeo inteiro ao trecho que importa.</h2><p>Três etapas. Você conduz a edição.</p></div>
    <ol class="studio-steps" aria-label="Etapas do seu projeto">
        <li><span>01 / ORIGEM</span><h3>Traga seu vídeo</h3><p>Envie um arquivo ou uma URL pública compatível.</p></li>
        <li><span>02 / DESCOBERTA</span><h3>Revise as sugestões</h3><p>A IA identifica trechos. Você escolhe os que fazem sentido.</p></li>
        <li><span>03 / EDIÇÃO</span><h3>Dê seu toque e exporte</h3><p>Ajuste o corte, o formato e as legendas. Baixe seu MP4.</p></li>
    </ol>
</section>
<?php endif; ?>

<section class="studio-activity" aria-label="Métricas da conta">
    <div class="studio-activity-title"><i data-lucide="activity" aria-hidden="true"></i><h2>Seu estúdio<br> em números</h2></div>
    <dl><div><dt>Projetos</dt><dd><?= (int) $metrics['projects'] ?></dd></div><div><dt>Vídeos analisados</dt><dd><?= (int) $metrics['processed'] ?></dd></div><div><dt>Armazenamento</dt><dd><?= e($formatBytes((int) $metrics['storage_bytes'])) ?></dd></div></dl>
</section>

<section class="dashboard-columns">
    <article class="panel projects-panel" aria-labelledby="recentes-titulo"><div class="panel-heading"><div><p class="eyebrow">Continue daqui</p><h2 id="recentes-titulo">Projetos recentes</h2></div><a class="text-link" href="/projetos">Ver todos <span class="visually-hidden">os projetos: <?= count($metrics['recent']) ?> de <?= (int) $metrics['projects'] ?></span><i data-lucide="arrow-up-right" aria-hidden="true"></i></a></div>
    <?php if ($metrics['recent'] === []): ?>
        <div class="empty-state"><span class="empty-icon"><i data-lucide="film" aria-hidden="true"></i></span><h3>O primeiro vídeo abre o estúdio.</h3><p>Uma entrevista, uma aula, uma conversa. Traga seu arquivo e encontre os trechos que merecem continuar.</p><a class="button button-small" href="/projetos/novo">Criar novo projeto</a></div>
    <?php else: ?>
        <ul class="project-list">
            <?php foreach ($metrics['recent'] as $project):
                $projectStatus = (string) $project['status'];
                if (!array_key_exists($projectStatus, $statusLabels)) { $projectStatus = 'queued'; }
                $hasSuggestions = in_array($projectStatus, ['suggestions_ready', 'rendering', 'completed'], true);
                $projectUrl = $hasSuggestions ? '/projetos/' . (int) $project['id'] : '/projetos';
            ?>
                <li>
                    <div class="project-thumb" aria-hidden="true"><i data-lucide="play" aria-hidden="true"></i></div>
                    <div class="project-summary"><h3><a href="<?= e($projectUrl) ?>"><?= e((string) $project['name']) ?></a></h3><p><?= e($statusLabels[$projectStatus]) ?> · <?= $hasSuggestions ? 'Abrir projeto' : 'Acompanhar na biblioteca' ?></p></div>
                    <span class="status-pill status-<?= e($projectStatus) ?>"><?= e($statusLabels[$projectStatus]) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    </article>
    <aside class="dashboard-aside"><section class="panel plan-panel" aria-labelledby="plano-titulo"><p class="eyebrow">Seu plano</p><div class="plan-title"><span class="metric-icon"><i data-lucide="badge-check" aria-hidden="true"></i></span><h2 id="plano-titulo"><?= e($user['plan_name']) ?></h2></div><p><?= $monthlyMinutes > 0 ? (int) $metrics['minutes_used'] . ' usados de ' . $monthlyMinutes . ' minutos incluídos neste ciclo; ' . (int) $metrics['minutes'] . ' disponíveis.' : (int) $metrics['minutes_used'] . ' minutos usados neste ciclo.' ?></p><progress class="usage-progress" aria-label="<?= (int) $metrics['minutes_used'] ?> minutos usados neste ciclo" value="<?= min((int) $metrics['minutes_used'], max(1, $monthlyMinutes)) ?>" max="<?= max(1, $monthlyMinutes) ?>"><?= $monthlyMinutes > 0 ? min(100, ((int) $metrics['minutes_used'] / $monthlyMinutes) * 100) : 0 ?>%</progress><a class="text-link" href="/conta/plano">Ver plano e limites <i data-lucide="arrow-right" aria-hidden="true"></i></a></section>
    <section class="panel credits-panel" aria-labelledby="creditos-titulo"><p class="eyebrow">Créditos disponíveis</p><h2 id="creditos-titulo"><?= (int) $metrics['credits'] ?> créditos</h2><p>Acompanhe o uso em cada análise.</p><a class="text-link" href="/conta/creditos">Abrir extrato <i data-lucide="arrow-right" aria-hidden="true"></i></a></section></aside>
</section>
</div>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/../layouts/app.php';
