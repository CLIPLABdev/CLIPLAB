<?php

declare(strict_types=1);

use App\Core\Csrf;

$id=(int)$clip['id'];
$projectId=(int)$clip['project_id'];
$statusLabels=['suggested'=>'Pronto para editar','approved'=>'Aprovado','completed'=>'Concluído','queued'=>'Na fila','rendering'=>'Renderizando','failed'=>'Falhou'];
$status=(string)$clip['status'];
$editable=in_array($status,['suggested','approved','completed','failed'],true);
$styles=['minimal'=>'Minimalista','viral'=>'Viral','podcast'=>'Podcast','highlight'=>'Destaque','karaoke'=>'Karaokê','custom'=>'Personalizado'];
$ratios=['original'=>'Original','9:16'=>'Vertical · 9:16','1:1'=>'Quadrado · 1:1','16:9'=>'Horizontal · 16:9','4:5'=>'Retrato · 4:5'];
$modes=['original'=>'Original','center'=>'Centralizado','manual'=>'Foco manual'];
$destinations=['editor'=>'editor-errors','options'=>'editor-style','subtitles'=>'editor-srt','reframe'=>'editor-aspect_ratio'];
$optionFields=array_keys(\App\Media\Editor\EditorOptions::defaults());
$fieldErrors=static function (string $name) use ($errors,$optionFields): array {
    $keys=[$name];
    if (in_array($name,$optionFields,true)) $keys[]='options';
    if ($name==='srt' || $name==='transcript_mode') $keys[]='subtitles';
    if (in_array($name,['aspect_ratio','reframe_mode','focus_x','focus_y'],true)) $keys[]='reframe';
    return array_values(array_filter($keys,static fn (string $key): bool => isset($errors[$key])));
};
$attributes=static function (string $name,string $help='') use ($fieldErrors): string {
    $keys=$fieldErrors($name);
    $ids=$help!=='' ? [$help] : [];
    foreach ($keys as $key) $ids[]='editor-error-'.$key;
    return ($keys!==[] ? ' aria-invalid="true"' : '').($ids!==[] ? ' aria-describedby="'.e(implode(' ',$ids)).'"' : '');
};
$selected=static fn (string $name,string $value): string => (string)$values[$name]===$value ? ' selected' : '';
ob_start();
?>
<link rel="stylesheet" href="/assets/css/clip-editor.css">
<link rel="stylesheet" href="/assets/css/editor-effects.css">
<div class="clip-editor-page">
    <nav class="editor-breadcrumb" aria-label="Navegação estrutural"><a href="/clips">Clipes</a><span aria-hidden="true">/</span><a href="/projetos/<?= $projectId ?>"><?= e((string)$clip['project_name']) ?></a><span aria-hidden="true">/</span><span>Editor</span></nav>
    <header class="editor-heading">
        <div><p class="eyebrow">Seu próximo corte</p><h2><?= e((string)$clip['title']) ?></h2><p>Ajuste o corte, dê seu estilo e exporte uma nova versão.</p></div>
        <span class="editor-status"><?= e($statusLabels[$status] ?? 'Indisponível') ?></span>
    </header>
    <nav class="editor-workflow-links" aria-label="Próximas etapas"><a class="editor-secondary" href="/clips/<?= $id ?>/capas">Estúdio de thumbnail</a><a class="editor-secondary" href="/clips/<?= $id ?>/publicacao">Preparar publicação</a></nav>
    <?php if (is_string($feedback)): ?><p class="editor-notice" role="status"><?= e($feedback) ?></p><?php endif; ?>
    <?php if ($parentClipId!==null): ?><p class="editor-version-note">Versão do <a href="/clips/<?= (int)$parentClipId ?>/editar">clipe #<?= (int)$parentClipId ?></a>. O arquivo anterior continua disponível.</p><?php endif; ?>
    <?php if (!$editable): ?>
        <section class="editor-panel editor-processing" aria-labelledby="editor-processing-title">
            <span class="editor-processing-icon" aria-hidden="true"><i data-lucide="clapperboard"></i></span>
            <p class="eyebrow">Processamento em andamento</p><h3 id="editor-processing-title"><?= e($statusLabels[$status] ?? 'Processando') ?></h3>
            <p><?= $trackStatus==='pending' ? 'Preparando a transcrição e a renderização da sua versão.' : 'O arquivo da sua versão está sendo preparado.' ?> Você pode fechar esta página.</p>
            <div class="editor-actions"><a class="button button-small" href="/clips/<?= $id ?>/editar">Atualizar status</a><a class="editor-secondary" href="/projetos/<?= $projectId ?>">Abrir projeto</a></div>
            <?php if ($hasTranscript): ?><a class="editor-text-link" href="/clips/<?= $id ?>/legendas.srt">Baixar legendas SRT</a><?php endif; ?>
        </section>
    <?php else: ?>
        <?php if ($status==='failed'): ?><p class="editor-notice editor-warning" role="status"><?= e((string)$renderErrorMessage) ?> Revise o áudio ou use um SRT revisado e crie uma nova versão.</p><?php endif; ?>
        <?php if ($errors!==[]): ?>
            <div id="editor-errors" class="editor-error-summary" role="alert" tabindex="-1" data-editor-errors>
                <h3>Revise antes de exportar</h3><ul>
                <?php foreach ($errors as $field=>$message): ?><li id="editor-error-<?= e($field) ?>"><a href="#<?= e($destinations[$field] ?? 'editor-'.$field) ?>"><?= e($message) ?></a></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <form method="post" action="/clips/<?= $id ?>/editar" class="clip-editor-form" data-clip-editor data-reframe-editor
            data-clip-id="<?= $id ?>" data-source-preview-url="/clips/<?= $id ?>/source-preview" data-consent-active="<?= $consentActive ? '1' : '0' ?>"
            data-reframe-max-duration-ms="<?= $reframeConfig['duration'] ?>" data-reframe-max-frames="<?= $reframeConfig['frames'] ?>" data-reframe-max-edge="<?= $reframeConfig['edge'] ?>"
            data-render-max-duration="<?= $renderMaximum ?>">
            <script type="application/json" data-editor-defaults><?= json_encode(\App\Media\Editor\EditorOptions::defaults(),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
            <script type="application/json" data-preview-transcript><?= json_encode($previewTranscript ?? null,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
            <input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="request_key" value="<?= e((string)$values['request_key']) ?>">
            <input type="hidden" name="reframe_keyframes" value="<?= e((string)$values['reframe_keyframes']) ?>" data-reframe-keyframes>
            <aside class="editor-preview-column" aria-label="Prévia e intervalo">
                <section class="editor-panel editor-preview-panel">
                    <div class="editor-section-heading"><h3>Prévia do corte</h3><span>Privada</span></div>
                    <div class="editor-preview-stage">
                        <video controls playsinline preload="none" data-reframe-preview aria-label="Vídeo original para revisar o intervalo"></video>
                        <canvas id="editor-focus-canvas" class="editor-reframe-overlay" data-reframe-overlay data-focus-editing="false" hidden aria-hidden="true" tabindex="-1" aria-label="Foco do recorte. Use as setas para ajustar e Escape para sair."></canvas>
                    </div>
                    <div class="editor-preview-buttons"><button class="button button-small" type="button" data-reframe-preview-open hidden><i data-lucide="play" aria-hidden="true"></i>Abrir prévia</button><button class="editor-secondary" type="button" data-reframe-focus-edit aria-controls="editor-focus-canvas" aria-pressed="false" hidden disabled>Editar foco na prévia</button></div>
                    <p class="editor-help" data-reframe-status aria-live="polite" aria-atomic="true">A prévia só carrega quando você a abre. Sem reprodução automática.</p>
                    <noscript><p class="editor-help"><a href="/clips/<?= $id ?>/source-preview" target="_blank" rel="noopener">Abrir vídeo original</a> em outra aba para conferir os tempos. Todos os ajustes e a exportação funcionam neste formulário.</p></noscript>
                    <div class="editor-style-preview" data-style-preview aria-label="Amostra do estilo de texto" hidden>
                        <small>Prévia aproximada · confira o resultado final no MP4. Tempos por palavra e quebra de linhas podem diferir.</small>
                        <canvas class="editor-output-preview" data-output-preview hidden aria-label="Prévia aproximada do vídeo com recorte e textos"></canvas>
                        <label class="editor-safe-toggle"><input type="checkbox" data-safe-zones checked> Mostrar zonas seguras (guias, não exportadas)</label>
                        <p class="editor-help">Novas exportações em retrato protegem margens verticais para textos e logos. As guias são genéricas e podem variar entre plataformas. Versões já concluídas permanecem preservadas.</p>
                        <p class="editor-help" data-output-status aria-live="polite">Abra a prévia para ver o vídeo com seus ajustes.</p>
                        <div class="editor-overlay-sample" data-overlay-sample><span data-title-preview></span><span data-caption-preview></span><span data-watermark-preview></span><span data-cta-preview></span></div>
                    </div>
                </section>
                <section class="editor-panel">
                    <div class="editor-section-heading"><h3>Intervalo</h3><output data-editor-duration aria-live="polite"></output></div>
                    <div class="editor-two-fields">
                        <div><label for="editor-start_time">Início (segundos)</label><input id="editor-start_time" name="start_time" type="number" inputmode="decimal" min="0" max="<?= (int)$clip['source_duration_seconds'] ?>" step="0.001" value="<?= e((string)$values['start_time']) ?>" required<?= $attributes('start_time','editor-time-help') ?>></div>
                        <div><label for="editor-end_time">Fim (segundos)</label><input id="editor-end_time" name="end_time" type="number" inputmode="decimal" min="0" max="<?= (int)$clip['source_duration_seconds'] ?>" step="0.001" value="<?= e((string)$values['end_time']) ?>" required<?= $attributes('end_time','editor-time-help') ?>></div>
                    </div>
                    <p id="editor-time-help" class="editor-help">Use os tempos do vídeo original. Duração de 1 a <?= $renderMaximum ?> segundos; vídeo original com <?= (int)$clip['source_duration_seconds'] ?> segundos.</p>
                    <label class="editor-timeline-label" for="editor-preview-time" hidden data-editor-timeline-label>Posição na prévia</label>
                    <input id="editor-preview-time" class="editor-timeline" type="range" min="0" max="1" step="0.001" value="0" disabled hidden data-editor-timeline aria-label="Posição na prévia">
                    <p class="editor-help editor-warning" data-interval-warning hidden>O intervalo mudou. Revise os tempos do SRT ou gere a transcrição automática novamente.</p>
                </section>
            </aside>
            <div class="editor-controls-column">
                <section class="editor-panel" data-editor-library hidden>
                    <div class="editor-section-heading"><h3>Biblioteca e favoritos</h3><a class="editor-text-link" href="/templates">Gerenciar templates</a></div>
                    <button type="button" class="editor-secondary" data-library-load>Carregar biblioteca</button>
                    <div data-library-controls hidden>
                        <label for="editor-template">Template para aplicar</label><select id="editor-template" data-library-select></select>
                        <button type="button" class="editor-secondary" data-library-apply>Aplicar ao corte</button>
                    </div>
                    <label for="editor-template-name">Nome do novo template</label><input id="editor-template-name" type="text" maxlength="80" data-template-name placeholder="Minha identidade">
                    <button type="button" class="editor-secondary" data-library-save>Salvar ajustes como template</button>
                    <p class="editor-help" data-library-status role="status">Aplicar copia os valores para este formulário. A exportação depende do botão ao final.</p>
                </section>
                <section class="editor-panel">
                    <div class="editor-section-heading"><h3><span>01</span> Enquadramento</h3></div>
                    <div class="editor-two-fields">
                        <div><label for="editor-aspect_ratio">Proporção de saída</label><select id="editor-aspect_ratio" name="aspect_ratio" data-reframe-ratio<?= $attributes('aspect_ratio') ?>><?php foreach ($ratios as $value=>$label): ?><option value="<?= e($value) ?>"<?= $selected('aspect_ratio',$value) ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                        <div><label for="editor-reframe_mode">Modo de enquadramento</label><select id="editor-reframe_mode" name="reframe_mode" data-reframe-mode<?= $attributes('reframe_mode') ?>><?php foreach ($modes as $value=>$label): ?><option value="<?= e($value) ?>"<?= $selected('reframe_mode',$value) ?>><?= e($label) ?></option><?php endforeach; ?><option value="auto" data-reframe-auto-option<?= $values['reframe_mode']==='auto' && $values['reframe_keyframes']!=='' ? ' selected' : ' disabled hidden' ?>>Automático</option></select></div>
                    </div>
                    <div class="editor-two-fields editor-focus-fields">
                        <div><label for="editor-focus_x">Foco horizontal (0 a 1)</label><input id="editor-focus_x" name="focus_x" type="number" min="0" max="1" step="0.000001" value="<?= e((string)$values['focus_x']) ?>" placeholder="0.5"<?= $attributes('focus_x','editor-focus-help') ?>></div>
                        <div><label for="editor-focus_y">Foco vertical (0 a 1)</label><input id="editor-focus_y" name="focus_y" type="number" min="0" max="1" step="0.000001" value="<?= e((string)$values['focus_y']) ?>" placeholder="0.5"<?= $attributes('focus_y','editor-focus-help') ?>></div>
                    </div>
                    <p id="editor-focus-help" class="editor-help">No foco manual, 0 é esquerda/topo e 1 é direita/base. Campos vazios usam o centro (0.5). O reenquadramento aceita até <?= (int)($reframeConfig['duration']/1000) ?> segundos.</p>
                    <button class="editor-secondary editor-smart-button" type="button" data-reframe-auto hidden<?= !$consentActive ? ' disabled aria-describedby="editor-consent-help"' : '' ?>><i data-lucide="scan-face" aria-hidden="true"></i>Ativar enquadramento inteligente</button>
                    <p id="editor-consent-help" class="editor-help"><?= $consentActive ? 'Consentimento ativo. A detecção de rostos acontece neste dispositivo.' : 'Para ativar a detecção, autorize as métricas do SDK na seção de privacidade abaixo.' ?></p>
                </section>
                <section class="editor-panel">
                    <div class="editor-section-heading"><h3><span>02</span> Legendas</h3><?php if ($hasTranscript): ?><a class="editor-text-link" href="/clips/<?= $id ?>/legendas.srt">Baixar SRT</a><?php endif; ?></div>
                    <label for="editor-transcript_mode">Origem das legendas</label>
                    <select id="editor-transcript_mode" name="transcript_mode"<?= $attributes('transcript_mode','editor-transcription-help') ?>>
                        <option value="manual"<?= $selected('transcript_mode','manual') ?>>Usar o SRT revisado abaixo</option><option value="auto"<?= $selected('transcript_mode','auto') ?><?= !(bool)$clip['has_audio'] ? ' disabled' : '' ?>>Gerar com IA a partir do áudio</option>
                    </select>
                    <p id="editor-transcription-help" class="editor-help"><?= !(bool)$clip['has_audio'] ? 'Este vídeo não tem áudio para transcrição automática; use um SRT revisado não vazio para exportar. ' : '' ?>Ao gerar com IA, o áudio do intervalo selecionado é enviado ao Gemini. A transcrição e o render continuam com a página fechada. Para revisar depois, abra a versão criada e use seu SRT.</p>
                    <div class="editor-two-fields">
                        <div><label for="editor-style">Estilo de legenda</label><select id="editor-style" name="style"<?= $attributes('style','editor-style-help') ?>><?php foreach ($styles as $value=>$label): ?><option value="<?= e($value) ?>"<?= $selected('style',$value) ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                        <div><label for="editor-position">Posição da legenda</label><select id="editor-position" name="position"<?= $attributes('position') ?>><?php foreach (['top'=>'Topo','middle'=>'Centro','bottom'=>'Base'] as $value=>$label): ?><option value="<?= e($value) ?>"<?= $selected('position',$value) ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                    </div>
                    <p id="editor-style-help" class="editor-help">Destaque e karaokê usam tempos reais por palavra quando disponíveis. Correções com a mesma quantidade de palavras preservam esses tempos; mudanças no intervalo ou na estrutura usam segmentos completos.</p>
                    <div class="editor-three-fields">
                        <div><label for="editor-color">Cor do texto</label><input id="editor-color" type="color" name="color" value="<?= e(preg_match('/^#[0-9a-fA-F]{6}$/D',(string)$values['color']) ? (string)$values['color'] : '#FFFFFF') ?>"<?= $attributes('color') ?>></div>
                        <div><label for="editor-accent_color">Cor de destaque</label><input id="editor-accent_color" type="color" name="accent_color" value="<?= e(preg_match('/^#[0-9a-fA-F]{6}$/D',(string)$values['accent_color']) ? (string)$values['accent_color'] : '#FACC15') ?>"<?= $attributes('accent_color') ?>></div>
                        <div><label for="editor-font_size">Tamanho do texto</label><input id="editor-font_size" type="number" inputmode="numeric" name="font_size" min="18" max="96" step="1" value="<?= e((string)$values['font_size']) ?>" required<?= $attributes('font_size') ?>></div>
                    </div>
                    <div class="editor-three-fields">
                        <?php foreach (['font_family'=>['Fonte',['Arial'=>'Arial','Georgia'=>'Georgia','Verdana'=>'Verdana']], 'font_weight'=>['Peso da fonte',['auto'=>'Do estilo','normal'=>'Normal','bold'=>'Negrito']], 'animation'=>['Animação',['none'=>'Sem animação','fade'=>'Fade','pop'=>'Pop']]] as $name=>[$label,$choices]): ?>
                        <div><label for="editor-<?= e($name) ?>"><?= e($label) ?></label><select id="editor-<?= e($name) ?>" name="<?= e($name) ?>"<?= $attributes($name) ?>><?php foreach ($choices as $value=>$text): ?><option value="<?= e($value) ?>"<?= $selected($name,$value) ?>><?= e($text) ?></option><?php endforeach; ?></select></div>
                        <?php endforeach; ?>
                        <div><label for="editor-background_color">Fundo e contorno</label><input id="editor-background_color" name="background_color" type="color" value="<?= e(preg_match('/^#[0-9a-fA-F]{6}$/D',(string)$values['background_color']) ? (string)$values['background_color'] : '#151515') ?>"<?= $attributes('background_color') ?>></div>
                        <?php foreach (['outline_width'=>'Espessura do contorno','shadow_depth'=>'Profundidade da sombra'] as $name=>$label): ?>
                        <div><label for="editor-<?= e($name) ?>"><?= e($label) ?></label><input id="editor-<?= e($name) ?>" name="<?= e($name) ?>" type="number" min="0" max="6" step="1" value="<?= e((string)$values[$name]) ?>"<?= $attributes($name) ?>></div>
                        <?php endforeach; ?>
                    </div>
                    <?php $effectValues=$values; $effectNested=false; require dirname(__DIR__).'/editor-library/effects.php'; ?>
                    <section class="editor-cues" data-cue-editor hidden aria-label="Timeline de legendas">
                        <h4>Revisão por frase</h4><p class="editor-help">Editar ou remover uma legenda altera somente o texto. Para cortar vídeo e áudio, selecione um intervalo e confirme.</p>
                        <p class="editor-help" data-cue-status role="status"></p><div data-cue-list></div>
                        <div class="editor-trim-confirm" data-cue-trim hidden><p data-cue-trim-description></p><button type="button" class="editor-secondary" data-cue-trim-confirm>Confirmar intervalo da frase</button><button type="button" class="editor-secondary" data-cue-trim-cancel>Cancelar intervalo</button></div>
                    </section>
                    <label for="editor-srt">Revisar legendas SRT</label><textarea id="editor-srt" name="srt" rows="8" spellcheck="false" maxlength="262144"<?= $attributes('srt','editor-srt-help') ?>><?= e((string)$values['srt']) ?></textarea>
                    <p id="editor-srt-help" class="editor-help">Tempos relativos ao início deste corte, começando em 00:00:00,000. Use blocos numerados, sem sobreposição. Para exportar este texto, escolha “Usar o SRT revisado abaixo”.</p>
                    <?php if ($hasTranscript): ?><div class="editor-srt-confirm"><input id="editor-srt_interval_confirmed" name="srt_interval_confirmed" type="checkbox" value="1"<?= $values['srt_interval_confirmed']==='1' ? ' checked' : '' ?>><label for="editor-srt_interval_confirmed">Revisei o SRT para o intervalo escolhido. Confirme se você alterou o início ou o fim do corte.</label></div><?php endif; ?>
                    <details class="editor-srt-example"><summary>Como escrever um bloco SRT</summary><pre>1
00:00:00,000 --&gt; 00:00:02,000
Seu texto aqui.</pre></details>
                </section>
                <section class="editor-panel">
                    <div class="editor-section-heading"><h3><span>03</span> Sua identidade</h3></div>
                    <label for="editor-title">Título no vídeo <span class="editor-optional">(opcional)</span></label><input id="editor-title" name="title" type="text" maxlength="120" value="<?= e((string)$values['title']) ?>" placeholder="Um título para abrir a conversa"<?= $attributes('title') ?>>
                    <label for="editor-watermark">Marca textual <span class="editor-optional">(opcional)</span></label><input id="editor-watermark" name="watermark" type="text" maxlength="80" value="<?= e((string)$values['watermark']) ?>" placeholder="@seucanal"<?= $attributes('watermark') ?>>
                    <p class="editor-help">O título aparece no topo e a marca no canto inferior. Ajuste a legenda para evitar sobreposição.</p>
                    <label for="editor-cta_text">Chamada final (CTA)</label><input id="editor-cta_text" name="cta_text" type="text" maxlength="120" value="<?= e((string)$values['cta_text']) ?>"<?= $attributes('cta_text') ?>><p class="editor-help">Aparece nos últimos 5 segundos, ou durante todo o corte se ele for menor.</p>
                    <label for="editor-logo_asset_id">Logo da marca</label><select id="editor-logo_asset_id" name="logo_asset_id"<?= $attributes('logo_asset_id') ?>><option value="0"<?= $selected('logo_asset_id','0') ?>>Sem logo</option><?php if ((int)$values['logo_asset_id']>0): ?><option value="<?= (int)$values['logo_asset_id'] ?>" selected>Logo #<?= (int)$values['logo_asset_id'] ?></option><?php endif; ?></select>
                    <p class="editor-help">Carregue a biblioteca para escolher seus logos. <a href="/marca">Enviar PNG e gerenciar marca</a>.</p>
                    <div class="editor-two-fields">
                        <div><label for="editor-logo_position">Posição do logo</label><select id="editor-logo_position" name="logo_position"<?= $attributes('logo_position') ?>><?php foreach (['top_left'=>'Topo esquerdo','top_right'=>'Topo direito','bottom_left'=>'Base esquerda','bottom_right'=>'Base direita'] as $value=>$label): ?><option value="<?= e($value) ?>"<?= $selected('logo_position',$value) ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                        <div><label for="editor-logo_scale">Largura do logo (%)</label><input id="editor-logo_scale" name="logo_scale" type="number" min="5" max="25" step="1" value="<?= e((string)$values['logo_scale']) ?>"<?= $attributes('logo_scale') ?>></div>
                    </div>
                </section>
            </div>
            <footer class="editor-export-bar"><div><strong>Uma nova versão. O original preservado.</strong><p>Até 20 exportações por hora. O processamento continua mesmo se você fechar a página.</p></div><button type="submit" class="button" data-editor-submit><i data-lucide="clapperboard" aria-hidden="true"></i>Criar versão e exportar</button></footer>
        </form>
        <details class="editor-consent editor-panel"><summary>Privacidade do enquadramento inteligente</summary><p>Os frames usados para detectar rostos são processados neste dispositivo. O SDK pode enviar métricas técnicas de desempenho e uso, mas nenhum frame ou resultado facial é enviado pelo ClipLab.</p><form method="post" action="<?= $consentActive ? '/privacidade/consentimentos/mediapipe/revogar' : '/privacidade/consentimentos/mediapipe' ?>"><input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="return_project_id" value="<?= $projectId ?>"><button class="editor-secondary" type="submit"><?= $consentActive ? 'Revogar consentimento' : 'Autorizar métricas do SDK' ?></button></form><p>Após salvar, você voltará ao projeto e poderá reabrir este editor.</p></details>
        <?php if ($status==='completed'): ?><p class="editor-version-note">O MP4 atual continua disponível: <a href="/clips/<?= $id ?>/download">baixar versão concluída</a>.</p><?php endif; ?>
        <script type="module" src="/assets/js/reframe-editor.js" defer></script>
        <script type="module" src="/assets/js/clip-editor.js" defer></script>
    <?php endif; ?>
</div>
<?php
$content=(string)ob_get_clean();
require __DIR__.'/../layouts/app.php';
