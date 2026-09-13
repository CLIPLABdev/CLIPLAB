<?php

declare(strict_types=1);

use App\Core\Csrf;

/** @var array{id:int,name:string,email:string,credits:int,plan_name:string,monthly_minutes:int} $user */
/** @var array{id:int,name:string,status:string,progress:int,stage:string,message:string,analysis_status:?string,video_summary:?string,updated_at:string} $project */
/** @var list<array{id:int,title:string,start_time:float,end_time:float,duration_seconds:float,viral_score:int,hook:string,reason:string,category:string,status:string,render_start_time?:?float,render_end_time?:?float,output_aspect_ratio?:string,reframe_mode?:string,source_preview_url?:string}> $clips */
/** @var string|null $clipRenderFeedback */
/** @var array<string,string> $clipRenderErrors */
/** @var array{clip_id:int,start_time:string,end_time:string}|array{} $clipRenderOld */
/** @var bool $mediaPipeConsentActive */
/** @var array{max_duration_ms:int,max_frames:int,max_edge:int} $reframeUiConfig */

$formatTimestamp = static function (float $seconds): string {
    $milliseconds = max(0, (int) round($seconds * 1000));
    $hours = intdiv($milliseconds, 3600000);
    $minutes = intdiv($milliseconds % 3600000, 60000);
    $wholeSeconds = intdiv($milliseconds % 60000, 1000);
    $fraction = $milliseconds % 1000;

    return $hours > 0
        ? sprintf('%d:%02d:%02d.%03d', $hours, $minutes, $wholeSeconds, $fraction)
        : sprintf('%d:%02d.%03d', $minutes, $wholeSeconds, $fraction);
};
$formatDecimal = static function (float $value): string {
    $formatted = rtrim(rtrim(number_format(max(0, $value), 3, '.', ''), '0'), '.');

    return $formatted === '' ? '0' : $formatted;
};
$status = (string) $project['status'];
$analysisReady = $project['analysis_status'] === 'completed';
$clipRenderFeedback = isset($clipRenderFeedback) && is_string($clipRenderFeedback) ? $clipRenderFeedback : null;
$clipRenderErrors = isset($clipRenderErrors) && is_array($clipRenderErrors) ? $clipRenderErrors : [];
$clipRenderOld = isset($clipRenderOld) && is_array($clipRenderOld) ? $clipRenderOld : [];
$mediaPipeConsentActive = isset($mediaPipeConsentActive) && $mediaPipeConsentActive === true;
$rawReframeConfig = isset($reframeUiConfig) && is_array($reframeUiConfig) ? $reframeUiConfig : [];
$reframeUiConfig = [
    'max_duration_ms' => is_int($rawReframeConfig['max_duration_ms'] ?? null)
        && $rawReframeConfig['max_duration_ms'] >= 1000
        && $rawReframeConfig['max_duration_ms'] <= 180000
        ? $rawReframeConfig['max_duration_ms'] : 90000,
    'max_frames' => is_int($rawReframeConfig['max_frames'] ?? null)
        && $rawReframeConfig['max_frames'] >= 2
        && $rawReframeConfig['max_frames'] <= 180
        ? $rawReframeConfig['max_frames'] : 180,
    'max_edge' => is_int($rawReframeConfig['max_edge'] ?? null)
        && $rawReframeConfig['max_edge'] >= 64
        && $rawReframeConfig['max_edge'] <= 320
        ? $rawReframeConfig['max_edge'] : 320,
];
$aspectLabels = [
    'original' => 'Original',
    '9:16' => '9:16',
    '1:1' => '1:1',
    '16:9' => '16:9',
    '4:5' => '4:5',
];
$modeLabels = [
    'original' => 'Original',
    'center' => 'Centralizado',
    'manual' => 'Foco manual',
    'auto' => 'Automático',
];
$hasEditableClips = false;
foreach ($clips as $candidateClip) {
    if (is_array($candidateClip) && in_array((string) ($candidateClip['status'] ?? ''), ['suggested', 'failed'], true)) {
        $hasEditableClips = true;
        break;
    }
}
$clipStages = [
    'suggested' => ['Sugestão pronta', 'Revise o intervalo e aprove este corte para renderizar.'],
    'approved' => ['Aprovado', 'Preparando a renderização do corte.'],
    'queued' => ['Na fila para renderização', 'O corte está aguardando a renderização.'],
    'rendering' => ['Renderizando vídeo', 'O vídeo está sendo renderizado.'],
    'completed' => ['Concluído', 'O vídeo está pronto para download.'],
    'failed' => ['Falhou', 'Não foi possível renderizar este corte. Revise o intervalo e tente novamente.'],
];
ob_start();
?>
<link rel="stylesheet" href="/assets/css/projects.css">
<link rel="stylesheet" href="/assets/css/projects-nojs.css">
<nav class="project-breadcrumb" aria-label="Navegação estrutural"><a href="/projetos"><i data-lucide="arrow-left" aria-hidden="true"></i>Voltar aos projetos</a></nav>
<section class="suggestions-hero" aria-labelledby="sugestoes-titulo">
    <div>
        <p class="eyebrow">Análise de cortes</p>
        <h2 id="sugestoes-titulo"><?= e($project['name']) ?></h2>
        <p><?= e($project['message']) ?></p>
    </div>
    <span class="status-pill status-<?= e($status) ?> suggestion-stage"><?= e($project['stage']) ?></span>
</section>

<?php if (isset($analysisResumeFeedback) && is_string($analysisResumeFeedback)): ?>
    <p class="form-notice" role="status"><?= e($analysisResumeFeedback) ?></p>
<?php endif; ?>
<?php if (!$analysisReady): ?>
    <section class="panel suggestion-pending" aria-labelledby="andamento-titulo">
        <div>
            <p class="eyebrow">Andamento atual</p>
            <h3 id="andamento-titulo"><?= e($project['stage']) ?></h3>
            <p><?= e($project['message']) ?></p>
        </div>
        <div class="project-progress">
            <div><span>Progresso</span><strong><?= (int) $project['progress'] ?>%</strong></div>
            <progress value="<?= (int) $project['progress'] ?>" max="100"><?= (int) $project['progress'] ?>%</progress>
        </div>
        <?php if ($status === 'awaiting_credits'): ?>
            <form method="post" action="/projetos/<?= (int) $project['id'] ?>/retomar-analise">
                <input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">
                <button class="button button-small" type="submit">Retomar análise</button>
            </form>
            <a class="text-link" href="/conta/creditos">Ver créditos</a>
        <?php endif; ?>
        <a class="button button-small" href="/projetos/<?= (int) $project['id'] ?>">Atualizar status</a>
        <?php if ($status === 'failed' && isset($analysisRecoveryToken) && is_int($analysisRecoveryToken) && $analysisRecoveryToken > 0): ?>
            <form method="post" action="/projetos/<?= (int) $project['id'] ?>/retomar-analise">
                <input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">
                <input type="hidden" name="expected_refund_id" value="<?= $analysisRecoveryToken ?>">
                <p>Os créditos da tentativa anterior foram devolvidos. Uma nova tentativa reservará os créditos da análise novamente.</p>
                <button class="button button-small" type="submit">Tentar análise novamente</button>
            </form>
        <?php endif; ?>
    </section>
<?php else: ?>
    <?php if ($clipRenderFeedback !== null): ?>
        <p class="form-notice clip-render-feedback" role="status"><?= e($clipRenderFeedback) ?></p>
    <?php endif; ?>
    <?php if ($project['video_summary'] !== null && trim($project['video_summary']) !== ''): ?>
        <section class="panel analysis-summary" aria-labelledby="resumo-analise">
            <p class="eyebrow">Resumo da análise</p>
            <h3 id="resumo-analise">O que a IA encontrou</h3>
            <p><?= e($project['video_summary']) ?></p>
        </section>
    <?php endif; ?>

    <?php if ($hasEditableClips): ?>
        <section class="panel reframe-consent" aria-labelledby="reframe-consent-title">
            <details>
                <summary id="reframe-consent-title">Privacidade do enquadramento inteligente</summary>
                <p>Os frames usados para detectar rostos são processados neste dispositivo. O SDK pode enviar métricas técnicas de desempenho e uso, mas nenhum frame ou resultado facial é enviado pelo ClipForge.</p>
            </details>
            <form method="post" action="<?= $mediaPipeConsentActive ? '/privacidade/consentimentos/mediapipe/revogar' : '/privacidade/consentimentos/mediapipe' ?>">
                <input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">
                <input type="hidden" name="return_project_id" value="<?= (int) $project['id'] ?>">
                <button class="button button-small" type="submit"><?= $mediaPipeConsentActive ? 'Revogar consentimento' : 'Concordar e ativar enquadramento inteligente' ?></button>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($clips === []): ?>
        <section class="empty-state suggestion-empty" aria-labelledby="sem-sugestoes"><h3 id="sem-sugestoes">Nenhuma sugestão disponível</h3><p>Atualize a página em instantes. Se o estado persistir, tente processar o projeto novamente mais tarde.</p><a class="button button-small" href="/projetos/<?= (int) $project['id'] ?>">Atualizar status</a></section>
    <?php else: ?>
        <section class="suggestion-list" aria-labelledby="cortes-sugeridos">
            <div class="suggestion-list-heading"><div><p class="eyebrow">Melhores momentos</p><h3 id="cortes-sugeridos"><?= count($clips) ?> <?= count($clips) === 1 ? 'corte sugerido' : 'cortes sugeridos' ?></h3></div><p>Revise cada intervalo antes de aprovar a renderização.</p></div>
            <div class="suggestion-grid">
                <?php foreach ($clips as $clip):
                    $clipId = (int) $clip['id'];
                    $clipStatus = array_key_exists((string) $clip['status'], $clipStages) ? (string) $clip['status'] : 'suggested';
                    [$clipStage, $clipMessage] = $clipStages[$clipStatus];
                    if ($clipStatus === 'failed' && is_string($clip['render_error_message'] ?? null)) {
                        $clipMessage = $clip['render_error_message'];
                    }
                    $active = in_array($clipStatus, ['approved', 'queued', 'rendering'], true);
                    $completed = $clipStatus === 'completed';
                    $editable = in_array($clipStatus, ['suggested', 'failed'], true);
                    $renderStart = ($clip['render_start_time'] ?? null) === null ? (float) $clip['start_time'] : (float) $clip['render_start_time'];
                    $renderEnd = ($clip['render_end_time'] ?? null) === null ? (float) $clip['end_time'] : (float) $clip['render_end_time'];
                    $hasOld = (int) ($clipRenderOld['clip_id'] ?? 0) === $clipId;
                    $startValue = $hasOld ? (string) ($clipRenderOld['start_time'] ?? '') : $formatDecimal($renderStart);
                    $endValue = $hasOld ? (string) ($clipRenderOld['end_time'] ?? '') : $formatDecimal($renderEnd);
                    $startError = $hasOld ? ($clipRenderErrors['start_time'] ?? null) : null;
                    $endError = $hasOld ? ($clipRenderErrors['end_time'] ?? null) : null;
                    $clipAspect = array_key_exists((string) ($clip['output_aspect_ratio'] ?? ''), $aspectLabels)
                        ? (string) $clip['output_aspect_ratio'] : 'original';
                    $clipMode = array_key_exists((string) ($clip['reframe_mode'] ?? ''), $modeLabels)
                        ? (string) $clip['reframe_mode'] : 'original';
                    $selectedAspect = $hasOld && array_key_exists((string) ($clipRenderOld['aspect_ratio'] ?? ''), $aspectLabels)
                        ? (string) $clipRenderOld['aspect_ratio'] : $clipAspect;
                    $selectedMode = $hasOld && array_key_exists((string) ($clipRenderOld['reframe_mode'] ?? ''), $modeLabels)
                        ? (string) $clipRenderOld['reframe_mode'] : $clipMode;
                    if ($selectedMode === 'auto') {
                        $selectedMode = $selectedAspect === 'original' ? 'original' : 'center';
                    }
                    if ($selectedAspect === 'original') {
                        $selectedMode = 'original';
                    }
                    $focusX = $hasOld && $selectedMode === 'manual'
                        ? (string) ($clipRenderOld['focus_x'] ?? '') : '';
                    $focusY = $hasOld && $selectedMode === 'manual'
                        ? (string) ($clipRenderOld['focus_y'] ?? '') : '';
                    $keyframesValue = $hasOld && $selectedMode === 'auto'
                        ? (string) ($clipRenderOld['reframe_keyframes'] ?? '') : '';
                    $sourcePreviewUrl = '/clips/' . $clipId . '/source-preview';
                ?>
                    <article class="suggestion-card clip-status-<?= e($clipStatus) ?>" data-clip-card="<?= $clipId ?>"<?= $active ? ' data-clip-status-url="/api/clips/' . $clipId . '/status"' : '' ?>>
                        <header><span class="suggestion-score" aria-label="Score viral estimado: <?= (int) $clip['viral_score'] ?> de 100"><strong><?= (int) $clip['viral_score'] ?></strong><small>/100</small></span><span class="suggestion-category"><?= e($clip['category']) ?></span></header>
                        <img class="clip-thumbnail" data-clip-thumbnail<?= $completed ? ' src="/clips/' . $clipId . '/thumbnail"' : ' hidden' ?> alt="Thumbnail do corte <?= $clipId ?>">
                        <h4><?= e($clip['title']) ?></h4>
                        <p class="reframe-badge" aria-label="Enquadramento atual"><?= e($aspectLabels[$clipAspect]) ?> · <?= e($modeLabels[$clipMode]) ?></p>
                        <p class="suggestion-timeline"><i data-lucide="scissors" aria-hidden="true"></i><?= e($formatTimestamp($clip['start_time'])) ?> — <?= e($formatTimestamp($clip['end_time'])) ?> <span><?= e(number_format($clip['duration_seconds'], 1, ',', '.')) ?> s</span></p>
                        <dl><div><dt>Gancho</dt><dd><?= e($clip['hook']) ?></dd></div><div><dt>Por que funciona</dt><dd><?= e($clip['reason']) ?></dd></div></dl>
                        <div class="clip-render-state">
                            <strong class="clip-status" data-clip-status aria-live="polite" aria-atomic="true"><?= e($clipStage) ?></strong>
                            <p data-clip-message><?= e($clipMessage) ?></p>
                        </div>
                        <?php if ($completed): ?>
                            <p class="clip-final-duration">Duração final: <?= e(number_format(max(0, $renderEnd - $renderStart), 1, ',', '.')) ?> s</p>
                        <?php endif; ?>
                        <a class="button button-small clip-download" data-clip-download<?= $completed ? ' href="/clips/' . $clipId . '/download"' : ' hidden' ?> download>Baixar MP4</a>
                        <a class="button button-small button-secondary" href="/clips/<?= $clipId ?>/editar">Editor e legendas</a>
                        <?php if ($editable): ?>
                            <form class="clip-render-form" method="post" action="/clips/<?= $clipId ?>/render">
                                <input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">
                                <?php if ($hasOld && isset($clipRenderErrors['form'])): ?><p class="form-error" role="alert"><?= e($clipRenderErrors['form']) ?></p><?php endif; ?>
                                <div class="clip-render-fields">
                                    <div>
                                        <label for="clip-start-<?= $clipId ?>">Início (segundos)</label>
                                        <input id="clip-start-<?= $clipId ?>" name="start_time" type="number" inputmode="decimal" min="0" step="0.001" value="<?= e($startValue) ?>" required<?= $startError !== null ? ' aria-describedby="clip-start-error-' . $clipId . '" aria-invalid="true"' : '' ?>>
                                        <?php if ($startError !== null): ?><small class="form-error" id="clip-start-error-<?= $clipId ?>"><?= e($startError) ?></small><?php endif; ?>
                                    </div>
                                    <div>
                                        <label for="clip-end-<?= $clipId ?>">Fim (segundos)</label>
                                        <input id="clip-end-<?= $clipId ?>" name="end_time" type="number" inputmode="decimal" min="0" step="0.001" value="<?= e($endValue) ?>" required<?= $endError !== null ? ' aria-describedby="clip-end-error-' . $clipId . '" aria-invalid="true"' : '' ?>>
                                        <?php if ($endError !== null): ?><small class="form-error" id="clip-end-error-<?= $clipId ?>"><?= e($endError) ?></small><?php endif; ?>
                                    </div>
                                </div>
                                <section class="reframe-editor" data-reframe-editor data-clip-id="<?= $clipId ?>"
                                    data-source-preview-url="<?= e($sourcePreviewUrl) ?>"
                                    data-consent-active="<?= $mediaPipeConsentActive ? '1' : '0' ?>"
                                    data-reframe-max-duration-ms="<?= (int) $reframeUiConfig['max_duration_ms'] ?>"
                                    data-reframe-max-frames="<?= (int) $reframeUiConfig['max_frames'] ?>"
                                    data-reframe-max-edge="<?= (int) $reframeUiConfig['max_edge'] ?>">
                                    <div class="reframe-controls">
                                        <div>
                                            <label for="clip-aspect-<?= $clipId ?>">Proporção de saída</label>
                                            <select id="clip-aspect-<?= $clipId ?>" name="aspect_ratio" data-reframe-ratio>
                                                <option value="original"<?= $selectedAspect === 'original' ? ' selected' : '' ?>>Original</option>
                                                <option value="9:16"<?= $selectedAspect === '9:16' ? ' selected' : '' ?>>Vertical 9:16 — 720 × 1280</option>
                                                <option value="1:1"<?= $selectedAspect === '1:1' ? ' selected' : '' ?>>Quadrado 1:1 — 720 × 720</option>
                                                <option value="16:9"<?= $selectedAspect === '16:9' ? ' selected' : '' ?>>Horizontal 16:9 — 1280 × 720</option>
                                                <option value="4:5"<?= $selectedAspect === '4:5' ? ' selected' : '' ?>>Retrato 4:5 — 720 × 900</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label for="clip-mode-<?= $clipId ?>">Modo de enquadramento</label>
                                            <select id="clip-mode-<?= $clipId ?>" name="reframe_mode" data-reframe-mode>
                                                <option value="original"<?= $selectedMode === 'original' ? ' selected' : '' ?>>Original</option>
                                                <option value="center"<?= $selectedMode === 'center' ? ' selected' : '' ?>>Centralizado</option>
                                                <option value="manual"<?= $selectedMode === 'manual' ? ' selected' : '' ?>>Foco manual</option>
                                                <option value="auto" disabled hidden data-reframe-auto-option>Automático</option>
                                            </select>
                                        </div>
                                        <div class="reframe-focus-fields">
                                            <div><label for="clip-focus-x-<?= $clipId ?>">Foco horizontal (0 a 1)</label><input id="clip-focus-x-<?= $clipId ?>" name="focus_x" type="number" min="0" max="1" step="0.000001" value="<?= e($focusX) ?>"></div>
                                            <div><label for="clip-focus-y-<?= $clipId ?>">Foco vertical (0 a 1)</label><input id="clip-focus-y-<?= $clipId ?>" name="focus_y" type="number" min="0" max="1" step="0.000001" value="<?= e($focusY) ?>"></div>
                                        </div>
                                        <input name="reframe_keyframes" type="hidden" value="<?= e($keyframesValue) ?>" data-reframe-keyframes>
                                        <details class="reframe-disclosure"><summary>Como funciona o reenquadramento?</summary><p>Use Original para manter o vídeo, Centralizado para um corte fixo ou Foco manual para informar o centro entre 0 e 1.</p></details>
                                        <p class="reframe-summary">Resumo do enquadramento: <?= e($aspectLabels[$selectedAspect]) ?> · <?= e($modeLabels[$selectedMode]) ?></p>
                                    </div>
                                    <div class="reframe-preview-shell">
                                        <button class="button button-small" type="button" data-reframe-preview-open hidden>Abrir prévia</button>
                                        <div class="reframe-preview-stage">
                                            <video controls preload="metadata" data-reframe-preview></video>
                                            <canvas id="clip-reframe-overlay-<?= $clipId ?>" class="reframe-overlay" data-reframe-overlay
                                                data-focus-editing="false" hidden aria-hidden="true" tabindex="-1"
                                                aria-label="Área de edição do foco. Use as setas para ajustar a posição."></canvas>
                                        </div>
                                        <button class="button button-small" type="button" data-reframe-focus-edit
                                            aria-controls="clip-reframe-overlay-<?= $clipId ?>" aria-pressed="false" hidden disabled>Editar foco na prévia</button>
                                        <button class="button button-small" type="button" data-reframe-auto hidden>Ativar enquadramento inteligente</button>
                                        <p data-reframe-status aria-live="polite" aria-atomic="true">Prévia ainda não carregada.</p>
                                    </div>
                                </section>
                                <button class="button button-small" type="submit"><?= $clipStatus === 'failed' ? 'Tentar novamente' : 'Aprovar e renderizar' ?></button>
                            </form>
                        <?php elseif ($active): ?>
                            <a class="project-refresh-link clip-refresh-link" href="/projetos/<?= (int) $project['id'] ?>">Atualizar página</a>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <script src="/assets/js/clip-status.js" defer></script>
        <script type="module" src="/assets/js/reframe-editor.js" defer></script>
    <?php endif; ?>
    <p class="ai-score-disclaimer">O score é uma estimativa da análise de IA e não garante viralização.</p>
<?php endif; ?>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/../layouts/app.php';
