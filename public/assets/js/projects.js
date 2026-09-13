(() => {
  const form = document.querySelector('[data-project-form]');
  if (!form) return;

  const sourceChoice = form.querySelector('[data-source-choice]');
  const sourceChoices = Array.from(form.querySelectorAll('[data-source-choice-input]'));
  const tabList = form.querySelector('[data-source-tabs]');
  const tabs = Array.from(form.querySelectorAll('[data-source-tab]'));
  const panels = Array.from(form.querySelectorAll('[data-source-panel]'));
  const fileInput = form.querySelector('[data-video-file]');
  const urlInput = form.querySelector('[data-source-url]');
  const fileName = form.querySelector('[data-file-name]');
  const feedback = form.querySelector('[data-project-feedback]');
  const projectName = form.querySelector('[name="name"]');
  const submit = form.querySelector('button[type="submit"]');
  const maxBytes = Number(form.dataset.maxUploadBytes);
  const youtubeConsent = form.querySelector('[data-youtube-consent]');
  const youtubeRights = form.querySelector('[name="youtube_rights_confirmed"]');
  const isYoutube = () => {
    try { return ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be'].includes(new URL(urlInput.value.trim()).hostname.toLowerCase()); }
    catch { return false; }
  };
  const syncYoutube = () => {
    if (!youtubeConsent || !youtubeRights) return;
    const visible = !urlInput.disabled && isYoutube();
    youtubeConsent.hidden = !visible;
    youtubeRights.disabled = !visible;
  };
  let submitting = false;
  if (!sourceChoice || !tabList || !fileInput || !urlInput || !fileName) return;

  tabList.hidden = false;
  tabList.setAttribute('role', 'tablist');
  tabs.forEach((tab) => {
    tab.setAttribute('role', 'tab');
    tab.setAttribute('aria-controls', tab.dataset.sourceTab === 'upload' ? 'painel-upload' : 'painel-url');
  });
  panels.forEach((panel) => {
    panel.setAttribute('role', 'tabpanel');
    panel.setAttribute('aria-labelledby', panel.dataset.sourcePanel === 'upload' ? 'tab-upload' : 'tab-url');
  });

  const selectMode = (mode, focus = false) => {
    sourceChoices.forEach((choice) => { choice.checked = choice.value === mode; });
    tabs.forEach((tab) => {
      const selected = tab.dataset.sourceTab === mode;
      tab.setAttribute('aria-selected', String(selected));
      tab.tabIndex = selected ? 0 : -1;
      if (selected && focus) tab.focus();
    });
    panels.forEach((panel) => { panel.hidden = panel.dataset.sourcePanel !== mode; });
    fileInput.required = mode === 'upload';
    urlInput.required = mode === 'direct_url';
    fileInput.disabled = mode !== 'upload';
    urlInput.disabled = mode !== 'direct_url';
    syncYoutube();
  };

  const selectedChoice = sourceChoices.find((choice) => choice.checked);
  selectMode(selectedChoice ? selectedChoice.value : 'upload');
  sourceChoice.hidden = true;
  urlInput.addEventListener('input', syncYoutube);

  tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => selectMode(tab.dataset.sourceTab, true));
    tab.addEventListener('keydown', (event) => {
      if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault();
      const targetIndex = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length;
      selectMode(tabs[targetIndex].dataset.sourceTab, true);
    });
  });

  fileInput.addEventListener('change', () => {
    fileName.textContent = fileInput.files && fileInput.files[0] ? `Arquivo selecionado: ${fileInput.files[0].name}` : '';
  });

  if (!feedback || !projectName || !submit) return;
  const originalSubmit = submit.innerHTML;
  const message = (text, error = false, field = null) => {
    feedback.setAttribute('role', error ? 'alert' : 'status');
    feedback.setAttribute('aria-live', error ? 'assertive' : 'polite');
    feedback.hidden = false;
    feedback.textContent = text;
    feedback.classList.toggle('form-error', error);
    feedback.classList.toggle('form-hint', !error);
    if (field) {
      field.setAttribute('aria-invalid', 'true');
      field.setAttribute('aria-errormessage', feedback.id);
      field.focus();
    } else {
      feedback.focus();
    }
  };
  if (feedback.textContent.trim()) feedback.focus();

  form.addEventListener('submit', (event) => {
    if (submitting) { event.preventDefault(); return; }
    form.querySelectorAll('[aria-invalid]').forEach(field => {
      field.removeAttribute('aria-invalid');
      field.removeAttribute('aria-errormessage');
    });
    const fail = (text, field) => { event.preventDefault(); message(text, true, field); };
    if (!projectName.value.trim()) return fail('Informe um nome para o projeto.', projectName);
    if (!fileInput.disabled) {
      const file = fileInput.files && fileInput.files[0];
      if (!file) return fail('Selecione um arquivo de vídeo para enviar.', fileInput);
      if (!/\.(mp4|mov|webm)$/i.test(file.name)) return fail('Envie um arquivo MP4, MOV ou WEBM.', fileInput);
      if (Number.isFinite(maxBytes) && maxBytes > 0 && file.size > maxBytes) {
        return fail('O arquivo ultrapassa o limite mostrado acima. Escolha um vídeo menor.', fileInput);
      }
    } else {
      try {
        const url = new URL(urlInput.value.trim());
        if (url.protocol !== 'https:' || url.username || url.password) throw new Error('invalid');
      } catch {
        return fail('Informe uma URL HTTPS válida, sem login ou senha.', urlInput);
      }
      if (isYoutube() && youtubeRights && !youtubeRights.checked) {
        return fail('Confirme que você tem autorização para importar este vídeo do YouTube.', youtubeRights);
      }
    }
    submitting = true;
    form.setAttribute('aria-busy', 'true');
    submit.disabled = true;
    tabs.forEach(tab => { tab.disabled = true; });
    submit.textContent = fileInput.disabled ? 'Criando projeto…' : 'Enviando vídeo…';
    message(fileInput.disabled
      ? 'Criando projeto e preparando a importação. Aguarde…'
      : 'Enviando vídeo. Aguarde o recebimento antes de sair desta página.');
  });
  window.addEventListener('pageshow', event => {
    if (!event.persisted) return;
    submitting = false;
    form.removeAttribute('aria-busy');
    submit.disabled = false;
    submit.innerHTML = originalSubmit;
    tabs.forEach(tab => { tab.disabled = false; });
    feedback.hidden = true;
  });
})();
