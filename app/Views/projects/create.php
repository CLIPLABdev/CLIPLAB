<?php

declare(strict_types=1);

use App\Core\Csrf;

/** @var array{id: int, name: string, email: string, credits: int, plan_name: string, monthly_minutes: int} $user */
/** @var array<string, string> $errors */
/** @var array<string, string> $old */
/** @var string $idempotencyKey */
/** @var int $maxUploadBytes */

$sourceType = ($old['source_type'] ?? 'upload') === 'direct_url' ? 'direct_url' : 'upload';
$limit = $maxUploadBytes >= 1024 * 1024 ? number_format(intdiv($maxUploadBytes, 1024 * 1024), 0, ',', '.') . ' MB' : number_format(intdiv($maxUploadBytes, 1024), 0, ',', '.') . ' KB';
ob_start();
?>
<link rel="stylesheet" href="/assets/css/projects.css">
<link rel="stylesheet" href="/assets/css/projects-nojs.css">
<link rel="stylesheet" href="/assets/css/studio-polish.css">
<section class="project-create-layout studio-create" aria-labelledby="novo-projeto-titulo">
    <div class="project-create-copy"><a class="studio-back-link" href="/projetos"><i data-lucide="arrow-left" aria-hidden="true"></i>Seus projetos</a><p class="eyebrow">Novo projeto</p><h2 id="novo-projeto-titulo">Um vídeo.<br>Muitas possibilidades.</h2><p>Envie um arquivo ou cole um link. Vamos preparar seu vídeo e encontrar os trechos para você revisar.</p><ol class="studio-intake-steps"><li><span>01</span><div><strong>Escolha a origem</strong><p>Arquivo, YouTube ou link direto.</p></div></li><li><span>02</span><div><strong>Acompanhe a análise</strong><p>O status aparece na sua biblioteca.</p></div></li><li><span>03</span><div><strong>Revise seus cortes</strong><p>Você escolhe o que editar e exportar.</p></div></li></ol><ul class="project-intake-notes"><li>MP4, MOV e WEBM · até <?= e($limit) ?> por arquivo.</li><li>YouTube: vídeos públicos autorizados. Sem playlists, lives ou conteúdo que exija login.</li></ul></div>
    <form class="project-form panel" method="post" action="/projetos" enctype="multipart/form-data" novalidate data-project-form data-max-upload-bytes="<?= e((string) $maxUploadBytes) ?>">
        <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="idempotency_key" value="<?= e($idempotencyKey) ?>">
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= e((string) $maxUploadBytes) ?>">
        <p id="project-feedback" class="form-error" role="<?= $errors === [] ? 'status' : 'alert' ?>" aria-live="<?= $errors === [] ? 'polite' : 'assertive' ?>" tabindex="-1" data-project-feedback<?= $errors === [] ? ' hidden' : '' ?>><?= e(implode(' ', array_values($errors))) ?></p>
        <div class="studio-form-heading"><span class="studio-form-icon"><i data-lucide="clapperboard" aria-hidden="true"></i></span><div><h3>Adicione seu vídeo</h3><p>Comece pelo nome e escolha como enviar.</p></div></div>
        <label for="project-name">Nome do projeto</label>
        <input id="project-name" name="name" type="text" maxlength="255" required placeholder="Ex.: Entrevista com a equipe" value="<?= e($old['name'] ?? '') ?>" aria-describedby="project-name-error">
        <?php if (isset($errors['name'])): ?><p class="form-error" id="project-name-error"><?= e($errors['name']) ?></p><?php endif; ?>
        <fieldset class="source-choice" data-source-choice><legend>Como deseja enviar o vídeo?</legend><label><input type="radio" name="source_type" value="upload"<?= $sourceType === 'upload' ? ' checked' : '' ?> data-source-choice-input> Enviar arquivo</label><label><input type="radio" name="source_type" value="direct_url"<?= $sourceType === 'direct_url' ? ' checked' : '' ?> data-source-choice-input> Importar URL</label></fieldset>
        <noscript><p class="form-hint">Sem JavaScript, escolha uma origem acima e preencha apenas o campo correspondente abaixo.</p></noscript>
        <div class="source-tabs" hidden data-source-tabs>
            <button id="tab-upload" type="button" data-source-tab="upload"><i data-lucide="upload" aria-hidden="true"></i>Enviar arquivo</button>
            <button id="tab-url" type="button" data-source-tab="direct_url"><i data-lucide="link" aria-hidden="true"></i>Importar link</button>
        </div>
        <section id="painel-upload" class="source-panel" data-source-panel="upload">
            <label for="video-file">Arquivo de vídeo</label>
            <input id="video-file" name="video_file" type="file" accept="video/mp4,video/quicktime,video/webm,.mp4,.mov,.webm" aria-describedby="video-file-help video-file-error" data-video-file>
            <p id="video-file-help" class="form-hint">MP4, MOV ou WEBM, até <?= e($limit) ?>.</p><p class="form-hint" data-file-name aria-live="polite"></p>
            <?php if (isset($errors['video_file'])): ?><p class="form-error" id="video-file-error"><?= e($errors['video_file']) ?></p><?php endif; ?>
        </section>
        <section id="painel-url" class="source-panel" data-source-panel="direct_url">
            <label for="source-url">Link do YouTube ou URL direta de vídeo</label>
            <input id="source-url" name="source_url" type="url" inputmode="url" placeholder="https://www.youtube.com/watch?v=..." value="<?= e($old['source_url'] ?? '') ?>" aria-describedby="source-url-help source-url-error" data-source-url>
            <p id="source-url-help" class="form-hint">Cole um link de vídeo público do YouTube (incluindo Shorts e youtu.be) ou de um arquivo MP4, MOV ou WEBM. A disponibilidade da importação depende da plataforma; se houver bloqueio, envie o arquivo.</p>
            <div class="source-choice" data-youtube-consent>
                <label><input type="checkbox" name="youtube_rights_confirmed" value="1"<?= ($old['youtube_rights_confirmed'] ?? '') === '1' ? ' checked' : '' ?>> Confirmo que sou proprietário do vídeo ou tenho autorização para importá-lo.</label>
                <p class="form-hint">Confirmação necessária somente para YouTube.</p>
            </div>
            <?php if (isset($errors['source_url'])): ?><p class="form-error" id="source-url-error"><?= e($errors['source_url']) ?></p><?php endif; ?>
        </section>
        <fieldset class="source-choice">
            <legend>Depois da análise</legend>
            <input type="hidden" name="auto_render_requested" value="0">
            <label><input type="checkbox" name="auto_render_requested" value="1"<?= ($old['auto_render_requested'] ?? '1') === '1' ? ' checked' : '' ?>> Gerar meus vídeos automaticamente</label>
            <p class="form-hint">Exporta até 3 melhores cortes em MP4, na proporção original, com legendas automáticas. Depois você pode personalizar o enquadramento e revisar as legendas no editor. Sem novo débito de créditos pela exportação; o espaço do plano continua sendo respeitado.</p>
        </fieldset>
        <p class="form-hint">A análise envia o vídeo ao Gemini. Cada nova exportação exige legendas; a transcrição automática envia o áudio do corte ao Gemini. Falhas na legenda interrompem a exportação para revisão. Use apenas conteúdo que você tem autorização para processar. <a href="/privacidade">Privacidade</a> · <a href="/termos">Condições de uso</a>.</p>
        <button class="button" type="submit"><i data-lucide="upload" aria-hidden="true"></i>Criar projeto</button>
    </form>
</section>
<script src="/assets/js/projects.js" defer></script>
<?php
$content = (string) ob_get_clean();
require __DIR__ . '/../layouts/app.php';
