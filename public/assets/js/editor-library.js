export function normalizeTemplate(entry, defaults, field) {
  if(!entry || !entry.options || typeof entry.options!=='object' || Array.isArray(entry.options) || !['original','9:16','1:1','16:9','4:5'].includes(entry.aspect_ratio))throw new Error('Template inválido');
  const keys=Object.keys(defaults);
  if(Object.keys(entry.options).some(key=>!Object.hasOwn(defaults,key)))throw new Error('Campo desconhecido');
  const options={...defaults,...entry.options};
  for(const key of keys){
    const node=field(key),value=options[key];
    if(!node)throw new Error('Controle indisponível');
    if(typeof defaults[key]==='number'){
      if(!Number.isSafeInteger(value) || (node.min!==''&&Number.isFinite(Number(node.min))&&value<Number(node.min)) || (node.max!==''&&Number.isFinite(Number(node.max))&&value>Number(node.max)))throw new Error('Número inválido');
    }else if(typeof value!=='string' || /[\u0000-\u001f\u007f]/.test(value) || (value!==''&&value.trim()==='') || (node.maxLength>0&&Array.from(value).length>node.maxLength))throw new Error('Texto inválido');
    if(['color','accent_color','background_color','outline_color'].includes(key) && !(key==='outline_color'&&value==='auto') && !/^#[0-9a-f]{6}$/i.test(value))throw new Error('Cor inválida');
    if(node.tagName==='SELECT' && !(key==='style'&&value==='none') && !Array.from(node.options).some(option=>option.value===String(value)&&!option.disabled))throw new Error('Opção indisponível');
  }
  if(options.motion!=='none'&&options.zoom_percent<=100)throw new Error('Movimento exige zoom');
  const legacy=options.style==='none';if(legacy)options.style='minimal';
  return {options,aspect_ratio:entry.aspect_ratio,legacy};
}
export function enhanceEditorLibrary(form) {
  const find=s=>form.querySelector(s), field=n=>find('[name="'+n+'"]');
  const host=find('[data-editor-library]'); if(!host)return;host.hidden=false;
  const status=find('[data-library-status]'), select=find('[data-library-select]');
  const defaults=JSON.parse(find('[data-editor-defaults]').textContent),fields=Object.keys(defaults);
  let catalog=null, choices=new Map(), loading=false;
  const busy=()=>loading || form.dataset.reframeBusy==='1';
  const buttons=()=>{for(const b of host.querySelectorAll('button')) b.disabled=busy();};
  const emit=n=>{n.dispatchEvent(new Event('input',{bubbles:true}));n.dispatchEvent(new Event('change',{bubbles:true}));};
  async function load() {
    const response=await fetch('/api/editor-library',{credentials:'same-origin',headers:{Accept:'application/json'},cache:'no-store'});
    if(!response.ok || !response.headers.get('content-type')?.includes('application/json')) throw new Error();
    catalog=await response.json();
    if(!Array.isArray(catalog.templates)||!Array.isArray(catalog.presets)||!Array.isArray(catalog.logos))throw new Error();
    choices=new Map();select.replaceChildren();
    const add=(key,label,entry)=>{const option=document.createElement('option');option.value=key;option.textContent=label;select.append(option);choices.set(key,entry);};
    for(const entry of catalog.presets)add('preset:'+entry.id,entry.name,entry);
    for(const entry of catalog.templates)add('template:'+entry.id,(catalog.kit?.favorites?.includes(entry.id)?'★ ':'')+entry.name,entry);
    if(catalog.kit?.options)add('kit','Minha marca',catalog.kit);
    const logo=field('logo_asset_id'), selected=logo.value;
    for(const asset of catalog.logos)if(Number.isSafeInteger(asset.id)&&asset.id>0 && !Array.from(logo.options).some(o=>o.value===String(asset.id))) {
      const option=document.createElement('option');option.value=String(asset.id);option.textContent='Logo #'+asset.id;logo.append(option);
    }
    logo.value=selected;find('[data-library-controls]').hidden=false;
  }
  async function action(fn) {
    if(busy())return;loading=true;buttons();status.textContent='Carregando…';
    try{await fn();}catch{status.textContent='Não foi possível acessar a biblioteca. Confira sua sessão e tente novamente. Seus ajustes continuam no formulário.';}
    finally{loading=false;buttons();}
  }
  find('[data-library-load]').addEventListener('click',()=>action(async()=>{await load();status.textContent='Biblioteca carregada. Escolha um template ou os padrões da marca.';}));
  find('[data-library-apply]').addEventListener('click',()=>{
    if(busy())return;
    const entry=choices.get(select.value);if(!entry?.options)return;
    let normalized;
    try{normalized=normalizeTemplate(entry,defaults,field);}catch{status.textContent='Template inválido ou com recurso indisponível. Nenhum ajuste foi aplicado.';return;}
    // Commit the complete validated snapshot before observers see any changes.
    for(const name of fields)field(name).value=String(normalized.options[name]);
    field('aspect_ratio').value=normalized.aspect_ratio;
    emit(field('aspect_ratio'));for(const name of fields)emit(field(name));
    status.textContent=normalized.legacy?'Template legado atualizado para o estilo Minimalista: novas exportações precisam de legendas visíveis. Revise a prévia e exporte quando estiver pronto.':'Template aplicado ao formulário. Revise a prévia e exporte quando estiver pronto.';
  });
  find('[data-library-save]').addEventListener('click',()=>action(async()=>{
    const name=find('[data-template-name]').value.trim();
    if(!name){status.textContent='Dê um nome ao template antes de salvar.';find('[data-template-name]').focus();return;}
    const body=new URLSearchParams({_token:field('_token').value,action:'save',name,category:'custom',aspect_ratio:field('aspect_ratio').value});
    const options={};for(const key of fields){const raw=field(key).value;options[key]=typeof defaults[key]==='number'&&/^[+-]?[0-9]+$/.test(raw)?Number(raw):raw;}
    let normalized;try{normalized=normalizeTemplate({options,aspect_ratio:field('aspect_ratio').value},defaults,field);}catch{status.textContent='Revise os campos de aparência antes de salvar o template.';return;}
    for(const key of fields)body.set('options['+key+']',String(normalized.options[key]));
    const response=await fetch('/templates',{method:'POST',credentials:'same-origin',body});
    if(!response.ok || !response.redirected || new URL(response.url).pathname!=='/templates')throw new Error();
    await load();status.textContent='Template salvo na biblioteca. Este corte continua aberto para revisão.';
  }));
  form.addEventListener('reframe:busy',buttons);
  find('[data-template-name]').addEventListener('keydown',event=>{
    if(event.key==='Enter'){event.preventDefault();find('[data-library-save]').click();}
  });
}
