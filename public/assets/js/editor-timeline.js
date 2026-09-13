export function formatCues(cues) {
  const stamp = ms => {
    const value = Math.round(ms);
    return [Math.floor(value/3600000),Math.floor(value/60000)%60,Math.floor(value/1000)%60].map(n=>String(n).padStart(2,'0')).join(':')+','+String(value%1000).padStart(3,'0');
  };
  return cues.map((cue,i)=>String(i+1)+'\n'+stamp(cue.start)+' --> '+stamp(cue.end)+'\n'+cue.text).join('\n\n')+(cues.length?'\n':'');
}

export function enhanceCueTimeline(form, readCues) {
  const find = selector=>form.querySelector(selector);
  const field = name=>find('[name="'+name+'"]');
  const host=find('[data-cue-editor]'), list=find('[data-cue-list]'), status=find('[data-cue-status]');
  if (!host) return;
  host.hidden=false;
  const video=find('video'), srt=field('srt'), start=field('start_time'), end=field('end_time');
  const trim=find('[data-cue-trim]');
  let cues=[], pending=null;
  const busy=()=>form.dataset.reframeBusy==='1';
  const emit=node=>{node.dispatchEvent(new Event('input',{bubbles:true}));node.dispatchEvent(new Event('change',{bubbles:true}));};
  const announce=text=>{status.textContent=text;};
  const save=next=>{srt.value=formatCues(next);emit(srt);};
  const syncActive=()=>{
    const ms=(video.currentTime-Number(start.value))*1000;
    for(const row of list.children) {
      const cue=cues[Number(row.dataset.cueIndex)];
      row.setAttribute('aria-current',String(Boolean(cue && ms>=cue.start && ms<cue.end)));
    }
  };
  const syncDisabled=()=>{
    for(const control of host.querySelectorAll('button,input,textarea')) control.disabled=busy() || (control.dataset.cueSeek==='1' && (!video.getAttribute('src') || video.readyState<1));
  };
  const button=(label,fn)=>{
    const node=document.createElement('button'); node.type='button';node.className='editor-secondary';node.textContent=label;
    node.addEventListener('click',()=>{if(!busy()) fn();});return node;
  };
  const input=(parent,labelText,value,type='text')=>{
    const label=document.createElement('label'), node=document.createElement(type==='textarea'?'textarea':'input');
    label.textContent=labelText;node.setAttribute('aria-label',labelText);node.value=value;
    if(type!=='textarea'){node.type=type;node.step='0.001';node.min='0';}
    else {node.rows=2;node.maxLength=2000;}
    label.append(node);parent.append(label);return node;
  };
  function render() {
    cues=readCues(srt.value);pending=null;trim.hidden=true;list.replaceChildren();
    if(srt.value.trim() && !cues.length) {announce('SRT inválido: seu rascunho foi mantido. Corrija os blocos abaixo para reabrir a revisão por frase.');return;}
    announce(cues.length?cues.length+' legendas. Tempos relativos ao início do corte.':'Sem legendas para revisar.');
    cues.forEach((cue,index)=>{
      const row=document.createElement('article');row.className='editor-cue';row.dataset.cueIndex=String(index);
      const seek=button('Ir para legenda '+(index+1),()=>{video.pause();video.currentTime=Number(start.value)+cue.start/1000;syncActive();});
      seek.dataset.cueSeek='1';row.append(seek);
      const times=document.createElement('div');times.className='editor-two-fields';
      const first=input(times,'Início da legenda '+(index+1),String(cue.start/1000),'number');
      const last=input(times,'Fim da legenda '+(index+1),String(cue.end/1000),'number');row.append(times);
      const text=input(row,'Texto da legenda '+(index+1),cue.text,'textarea');
      const actions=document.createElement('div');actions.className='editor-cue-actions';
      actions.append(button('Atualizar legenda '+(index+1),()=>{
        const next=cues.map(c=>({...c})); next[index]={start:Math.round(Number(first.value)*1000),end:Math.round(Number(last.value)*1000),text:text.value.trim()};
        const encoded=formatCues(next);
        if(!first.value || !last.value || !next[index].text || !readCues(encoded).length || next[index].end>(Number(end.value)-Number(start.value))*1000) {
          announce('Revise início, fim e texto: não sobreponha legendas nem ultrapasse o corte. Seu rascunho permanece nos campos.');return;
        }
        save(next);announce('Legenda atualizada no SRT. Mudanças na estrutura ou nos tempos removem somente a sincronização por palavra desta frase.');
      }),button('Remover legenda '+(index+1),()=>{
        save(cues.filter((_,i)=>i!==index));announce('Legenda removida. O intervalo do vídeo e o áudio foram preservados.');
      }),button('Selecionar intervalo da legenda '+(index+1),()=>{
        if(cue.end-cue.start<1000) {announce('A frase tem menos de 1 segundo. Ajuste o intervalo manualmente para exportar.');return;}
        pending={first:Number(start.value)+cue.start/1000,last:Number(start.value)+cue.end/1000,cue};
        find('[data-cue-trim-description]').textContent='Cortar vídeo e áudio para '+pending.first.toFixed(3)+'–'+pending.last.toFixed(3)+' s? As outras legendas serão removidas e esta frase começará em zero. Revise o SRT antes de exportar.';
        trim.hidden=false;find('[data-cue-trim-confirm]').focus();
      }));
      row.append(actions);list.append(row);
      row.addEventListener('keydown',event=>{
        if(event.key==='Enter' && event.target.tagName==='INPUT') {
          event.preventDefault();
          actions.querySelector('button').click();
        }
      });
    });
    syncActive();syncDisabled();
  }
  find('[data-cue-trim-cancel]').addEventListener('click',()=>{pending=null;trim.hidden=true;});
  find('[data-cue-trim-confirm]').addEventListener('click',()=>{
    if(!pending || busy()) return;
    const chosen=pending;start.value=String(chosen.first);end.value=String(chosen.last);
    emit(start);emit(end);save([{start:0,end:chosen.cue.end-chosen.cue.start,text:chosen.cue.text}]);
    announce('Intervalo aplicado. Revise a legenda e confirme o SRT antes de exportar.');
  });
  srt.addEventListener('input',render);video.addEventListener('timeupdate',syncActive);
  for(const event of ['loadedmetadata','emptied'])video.addEventListener(event,syncDisabled);
  form.addEventListener('reframe:busy',syncDisabled);
  render();
}
