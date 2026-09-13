<?php
declare(strict_types=1);
use App\Core\Csrf;
$id=(int)$clip['id'];$labels=['pending'=>'Na fila','ready'=>'Pronta','failed'=>'Falhou'];$pending=($set['status']??'')==='pending';foreach($thumbnails as $t)if($t['status']==='pending')$pending=true;
ob_start(); ?>
<link rel="stylesheet" href="/assets/css/thumbnail-studio.css">
<section class="thumbnail-studio" data-thumbnail-studio data-status-url="/clips/<?= $id ?>/capas/status" data-pending="<?= $pending?'1':'0' ?>">
 <nav aria-label="Navegação do clipe"><a href="/clips/<?= $id ?>/editar">Editor</a> · <a href="/clips/<?= $id ?>/publicacao">Preparar publicação</a></nav>
 <h2><?= e($clip['title']) ?></h2><p>Capas de momentos reais do vídeo. A seleção usa contraste e nitidez locais; não analisa rostos ou emoções.</p>
 <?php if($error):?><p role="alert" class="studio-error"><?= e($error) ?></p><?php endif;?><?php if($feedback):?><p role="status"><?= e($feedback) ?></p><?php endif;?>
 <form method="post" action="/clips/<?= $id ?>/capas/gerar"><input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="request_key" value="<?= e($requestKey) ?>"><button type="submit" <?= $set?'disabled':'' ?>>Gerar até 5 candidatos</button></form>
 <?php if($set):?><p data-job-status aria-live="polite">Candidatos: <?= e($labels[$set['status']]??$set['status']) ?><?= $set['status']==='ready'?' · '.(int)$set['candidate_count'].' imagens':'' ?>. <?= $set['status']==='failed'?'Não encontramos uma seleção válida. Escolha um tempo abaixo para criar uma variante.':'' ?></p><?php endif;?>
 <div class="thumbnail-grid"><figure><img src="/clips/<?= $id ?>/thumbnail" alt="Capa original do clipe" loading="lazy"><figcaption>Original · preservada</figcaption></figure>
 <?php foreach($thumbnails as $t):?><figure><?php if($t['status']==='ready'):?><img src="/thumbnails/<?= (int)$t['id'] ?>" alt="<?= $t['kind']==='candidate'?'Candidato':'Variante' ?> em <?= e((string)$t['offset_seconds']) ?> segundos" loading="lazy"><figcaption><?= $t['kind']==='candidate'?'Candidato':'Variante' ?> · <?= e((string)$t['offset_seconds']) ?> s</figcaption><a href="/thumbnails/<?= (int)$t['id'] ?>/download">Baixar JPEG</a> <button type="button" data-pick-thumbnail="<?= (int)$t['id'] ?>" data-offset="<?= e((string)$t['offset_seconds']) ?>">Usar momento</button><?php else:?><figcaption><?= e($labels[$t['status']]??$t['status']) ?> · Variante <?= (int)$t['id'] ?></figcaption><?php endif;?></figure><?php endforeach;?></div>
 <form method="post" action="/clips/<?= $id ?>/capas" class="thumbnail-design-form"><input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="request_key" value="<?= e($requestKey) ?>">
  <h3>Criar variante 1280 × 720</h3><p>O JPEG final é criado a partir do vídeo original. O quadro abaixo acompanha o momento escolhido.</p>
  <video controls preload="metadata" src="/clips/<?= $id ?>/source-preview" data-thumbnail-video aria-label="Vídeo original para escolher o momento"></video>
  <div class="studio-fields"><label>Momento do clipe (segundos)<input name="offset_seconds" type="number" min="0" max="<?= e((string)max(0,(float)$clip['render_end_time']-(float)$clip['render_start_time']-0.001)) ?>" step="0.001" value="<?= e((string)$values['offset_seconds']) ?>" data-source-start="<?= e((string)$clip['render_start_time']) ?>" required></label>
  <label>Usar candidato<select name="base_thumbnail_id"><option value="0">Momento personalizado</option><?php foreach($thumbnails as $t):if($t['status']!=='ready')continue;?><option value="<?= (int)$t['id'] ?>" data-offset="<?= e((string)$t['offset_seconds']) ?>" <?= (int)$values['base_thumbnail_id']===(int)$t['id']?'selected':'' ?>><?= $t['kind']==='candidate'?'Candidato':'Variante' ?> <?= (int)$t['id'] ?> · <?= e((string)$t['offset_seconds']) ?> s</option><?php endforeach;?></select></label>
  <label>Template<select name="template"><?php foreach(['clean'=>'Clean · imagem limpa','bold'=>'Bold · contraste e faixa','split'=>'Split · painel lateral'] as $v=>$label):?><option value="<?= $v ?>" <?= $values['template']===$v?'selected':'' ?>><?= e($label) ?></option><?php endforeach;?></select></label>
  <label>Título (até 120 caracteres)<textarea name="title" maxlength="120" rows="3"><?= e($values['title']) ?></textarea></label>
  <label>Fonte<select name="font_family"><?php foreach(['Arial','Georgia','Verdana'] as $v):?><option <?= $values['font_family']===$v?'selected':'' ?>><?= $v ?></option><?php endforeach;?></select></label>
  <label>Tamanho máximo (ajustado para caber)<input name="font_size" type="number" min="32" max="96" value="<?= (int)$values['font_size'] ?>" required></label>
  <label>Cor do texto<input name="color" type="color" value="<?= e($values['color']) ?>"></label><label>Cor de destaque<input name="accent_color" type="color" value="<?= e($values['accent_color']) ?>"></label>
  <label>Posição do título<select name="position"><?php foreach(['top'=>'Topo','center'=>'Centro','bottom'=>'Base'] as $v=>$label):?><option value="<?= $v ?>" <?= $values['position']===$v?'selected':'' ?>><?= $label ?></option><?php endforeach;?></select></label>
  <label>Logo da biblioteca<select name="logo_asset_id"><option value="0">Sem logo</option><?php foreach($logos as $logo):?><option value="<?= (int)$logo['id'] ?>" <?= (int)$values['logo_asset_id']===(int)$logo['id']?'selected':'' ?>><?= e((string)($logo['name']??'Logo '.(int)$logo['id'])) ?></option><?php endforeach;?></select></label></div>
  <button type="submit">Renderizar variante</button>
 </form><p>O candidato selecionado determina o momento da imagem. Para escolher outro tempo, use “Momento personalizado”. Sem JavaScript, salve e atualize esta página para acompanhar.</p>
</section><script src="/assets/js/thumbnail-studio.js" defer></script>
<?php $content=(string)ob_get_clean();require __DIR__.'/../layouts/app.php'; ?>
