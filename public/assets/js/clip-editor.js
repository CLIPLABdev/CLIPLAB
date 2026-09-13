const SRT_TIMING = /^(\d{2}):([0-5]\d):([0-5]\d),(\d{3}) --> (\d{2}):([0-5]\d):([0-5]\d),(\d{3})$/;

export const captionStyleForExport = style => style === 'none' ? 'minimal' : style;

// Preview only. The server's SrtCodec validates the complete export independently.
export function readPreviewCues(input) {
  if (typeof input !== 'string' || input.length > 262144) return [];
  const normalized = input.replace(/^\uFEFF/, '').replace(/\r\n?/g, '\n').trim();
  if (!normalized) return [];
  const blocks = normalized.split(/\n[ \t]*\n/);
  if (blocks.length > 500) return [];
  const result = [];
  for (const [index, block] of blocks.entries()) {
    const lines = block.split('\n');
    const time = lines[1]?.match(SRT_TIMING);
    if (lines[0] !== String(index + 1) || !time || lines.length < 3) return [];
    const milliseconds = offset => ((Number(time[offset]) * 60 + Number(time[offset + 1])) * 60 + Number(time[offset + 2])) * 1000 + Number(time[offset + 3]);
    const start = milliseconds(1);
    const end = milliseconds(5);
    if (start >= end || end > 180000 || (result.length && start < result.at(-1).end)) return [];
    result.push({ start, end, text: lines.slice(2).join('\n') });
  }
  return result;
}

export function captionAt(cues, milliseconds) {
  return cues.find(cue => milliseconds >= cue.start && milliseconds < cue.end)?.text ?? '';
}

export function enhanceClipEditor(form) {
  const field = name => form.querySelector(`[name="${name}"]`);
  const find = selector => form.querySelector(selector);
  const start = field('start_time');
  const end = field('end_time');
  const mode = field('transcript_mode');
  const style = field('style');
  const srt = field('srt');
  const video = find('[data-reframe-preview]');
  const timeline = find('[data-editor-timeline]');
  const sample = find('[data-overlay-sample]');
  const preview = find('[data-style-preview]');
  const caption = find('[data-caption-preview]');
  const initialInterval = [start.value, end.value];
  const busy = () => form.dataset.reframeBusy === '1';
  let cues = readPreviewCues(srt.value);
  const interval = () => {
    const first = Number(start.value);
    const last = Number(end.value);
    return start.value !== '' && end.value !== '' && Number.isFinite(first) && Number.isFinite(last) && first >= 0 && last > first
      ? { first, last, duration: last - first } : null;
  };

  const updateCaption = () => {
    const current = interval();
    const hasMedia = Boolean(video.getAttribute('src'));
    const text = hasMedia && current ? captionAt(cues, (video.currentTime - current.first) * 1000)
      : (cues[0]?.text || 'Prévia do estilo');
    caption.textContent = text;
    caption.hidden = style.value === 'none' || mode.value === 'none' || text === '';
  };
  const updateStyle = () => {
    sample.dataset.style = style.value;
    sample.dataset.position = field('position').value;
    const color = field('color').value;
    const accent = field('accent_color').value;
    if (/^#[0-9a-f]{6}$/i.test(color)) sample.style.setProperty('--caption-color', color);
    if (/^#[0-9a-f]{6}$/i.test(accent)) sample.style.setProperty('--accent-color', accent);
    const size = Number(field('font_size').value);
    sample.style.setProperty('--caption-size', `${Math.max(11, Math.min(36, Number.isFinite(size) ? size / 2 : 22))}px`);
    find('[data-title-preview]').textContent = field('title').value;
    find('[data-watermark-preview]').textContent = field('watermark').value;
    const cta=find('[data-cta-preview]');if(cta)cta.textContent=field('cta_text')?.value||'';
    const val=name=>field(name)?.value||'',num=name=>Number(val(name))||0;
    const background=/^#[0-9a-f]{6}$/i.test(val('background_color'))?val('background_color'):'#151515';
    const outline=/^#[0-9a-f]{6}$/i.test(val('outline_color'))?val('outline_color'):background;
    const font=['Arial','Georgia','Verdana'].includes(val('font_family'))?val('font_family'):'Arial';
    const weight=automatic=>val('font_weight')==='auto'?(automatic?'700':'400'):(val('font_weight')==='bold'?'700':'400');
    for(const node of sample.querySelectorAll('span')){
      node.style.fontFamily=font;node.style.fontStyle=num('font_italic')?'italic':'normal';node.style.letterSpacing=num('letter_spacing')/2+'px';
      node.style.color=color;node.style.textTransform='none';node.style.webkitTextStroke=(num('outline_width')/2)+'px '+outline;
      node.style.paintOrder='stroke fill';node.style.textShadow=num('shadow_depth')?`${num('shadow_depth')/2}px ${num('shadow_depth')/2}px #000`:'none';
      const automaticBox=node===caption&&style.value==='podcast'||node===cta;
      const box=val('background_mode')==='auto'?automaticBox:val('background_mode')==='box';
      node.style.background=box?background:'transparent';
      node.style.fontWeight=weight(node===caption?['viral','highlight','karaoke'].includes(style.value):node!==find('[data-watermark-preview]'));
    }
    const offset=num('caption_offset_y')/2;
    caption.style.marginLeft=caption.style.marginRight=num('caption_margin_x')/2+'px';
    caption.style.transform=`${val('position')==='middle'?'translateY(-50%) ':''}translateY(${offset}px)`;
    preview.hidden = false;
    updateCaption();
  };
  const updateInterval = () => {
    const current = interval();
    const output = find('[data-editor-duration]');
    output.textContent = current ? `${Number(current.duration.toFixed(3)).toLocaleString('pt-BR')} s` : 'Revise o intervalo';
    const maximum = Number(form.dataset.renderMaxDuration) || 180;
    end.setCustomValidity(current && current.duration >= 1 && current.duration <= maximum ? '' : `Use um intervalo entre 1 e ${maximum} segundos.`);
    if (current) {
      timeline.min = String(current.first);
      timeline.max = String(current.last);
      timeline.value = String(Math.max(current.first, Math.min(current.last, video.currentTime || current.first)));
    }
    const changed = start.value !== initialInterval[0] || end.value !== initialInterval[1];
    find('[data-interval-warning]').hidden = !changed || srt.value.trim() === '';
    updateCaption();
  };
  const updatePosition = () => {
    if (busy()) return;
    const current = interval();
    if (!current || !video.getAttribute('src')) return;
    timeline.value = String(Math.max(current.first, Math.min(current.last, video.currentTime)));
    if (!video.paused && video.currentTime >= current.last) video.pause();
    updateCaption();
  };

  for (const name of ['position', 'color', 'accent_color', 'font_size', 'title', 'watermark']) field(name).addEventListener('input', updateStyle);
  for (const name of ['font_family','font_weight','font_italic','letter_spacing','caption_margin_x','caption_offset_y','outline_width','outline_color','background_color','background_mode','shadow_depth','cta_text']){
    field(name)?.addEventListener('input',updateStyle);field(name)?.addEventListener('change',updateStyle);
  }
  style.addEventListener('change', () => {
    style.value = captionStyleForExport(style.value);
    updateStyle();
  });
  mode.addEventListener('change', () => {
    if (mode.value === 'none') style.value = 'none';
    else if (style.value === 'none') style.value = 'minimal';
    updateStyle();
  });
  srt.addEventListener('input', () => {
    cues = readPreviewCues(srt.value);
    if (srt.value.trim()) {
      mode.value = 'manual';
      if (style.value === 'none') style.value = 'minimal';
    }
    updateStyle();
    updateInterval();
  });
  const changedInterval = () => {
    const confirmed = field('srt_interval_confirmed');
    if (confirmed) confirmed.checked = false;
    updateInterval();
  };
  start.addEventListener('input', changedInterval);
  end.addEventListener('input', changedInterval);
  video.addEventListener('loadedmetadata', () => {
    timeline.hidden = false;
    timeline.disabled = busy();
    find('[data-editor-timeline-label]').hidden = false;
    updateInterval();
    const current = interval();
    if (!busy() && current) video.currentTime = current.first;
  });
  video.addEventListener('emptied', () => { timeline.disabled = true; });
  video.addEventListener('timeupdate', updatePosition);
  video.addEventListener('play', () => {
    if (busy()) { video.pause(); return; }
    const current = interval();
    if (current && (video.currentTime < current.first || video.currentTime >= current.last)) video.currentTime = current.first;
  });
  timeline.addEventListener('input', () => {
    const current = interval();
    if (!busy() && current && video.getAttribute('src')) video.currentTime = Math.max(current.first, Math.min(current.last, Number(timeline.value)));
  });
  const disabledBefore = new Map();
  form.addEventListener('reframe:busy', () => {
    const locked = busy();
    for (const node of [start, end, srt, find('[data-editor-submit]')]) {
      if (locked && !disabledBefore.has(node)) { disabledBefore.set(node, node.disabled); node.disabled = true; }
      else if (!locked && disabledBefore.has(node)) { node.disabled = disabledBefore.get(node); disabledBefore.delete(node); }
    }
    timeline.disabled = locked || !video.getAttribute('src') || video.readyState < 1;
    video.controls = !locked;
    if (locked) video.pause();
  });
  Promise.all([import('./editor-timeline.js'), import('./editor-output-preview.js'), import('./editor-library.js')])
    .then(([timelineModule, outputModule, libraryModule]) => {
      timelineModule.enhanceCueTimeline(form, readPreviewCues);
      outputModule.enhanceOutputPreview(form, readPreviewCues);
      libraryModule.enhanceEditorLibrary(form);
    }).catch(() => {
      const status = find('[data-output-status]');
      if (status) status.textContent = 'Os recursos visuais não carregaram. Você pode revisar o SRT e exportar pelo formulário.';
    });
  updateStyle();
  updateInterval();
}

if (typeof document !== 'undefined') {
  for (const form of document.querySelectorAll('[data-clip-editor]')) enhanceClipEditor(form);
  document.querySelector('[data-editor-errors]')?.focus();
}
