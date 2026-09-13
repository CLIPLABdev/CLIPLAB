const clamp=(n,min,max)=>Math.max(min,Math.min(max,n));
export function videoEffectState(options,ms,duration) {
  const progress=clamp(ms/Math.max(1,duration),0,1),maximum=(Number(options.zoom_percent)||100)/100;
  const motion=options.motion||'none';
  const zoom=motion==='zoom_in'?1+(maximum-1)*progress:motion==='zoom_out'?maximum-(maximum-1)*progress:maximum;
  const fadeIn=Math.min(Number(options.video_fade_in_ms)||0,duration/2),fadeOut=Math.min(Number(options.video_fade_out_ms)||0,duration/2);
  return {zoom,x:motion==='pan_left'?1-progress:motion==='pan_right'?progress:.5,
    alpha:clamp(Math.min(fadeIn?ms/fadeIn:1,fadeOut?(duration-ms)/fadeOut:1),0,1)};
}
export function matchingWords(cue,snapshot) {
  if(!cue||!Array.isArray(snapshot?.cues))return [];
  const original=snapshot.cues.find(item=>item.start_ms===cue.start&&item.end_ms===cue.end&&item.text===cue.text);
  return Array.isArray(original?.words)?original.words:[];
}
export function textAnimationState(options,ms,duration) {
  const enter=Math.min(Number(options.animation_duration_ms)||0,duration),exit=Math.min(Number(options.animation_out_duration_ms)||0,duration);
  const fadesOut=options.animation_out==='fade'||(options.animation_out==='auto'&&options.animation==='fade');
  const inAlpha=options.animation==='fade'&&enter?ms/enter:1,outAlpha=fadesOut&&exit?(duration-ms)/exit:1;
  return {alpha:clamp(Math.min(inAlpha,outAlpha),0,1),scale:options.animation==='pop'&&enter?.85+.15*clamp(ms/enter,0,1):1};
}
export function cropRectangle(width,height,ratio,x=0.5,y=0.5) {
  const cropWidth=Math.min(width,height*ratio),cropHeight=Math.min(height,width/ratio);
  return {x:Math.max(0,Math.min(width-cropWidth,x*width-cropWidth/2)),y:Math.max(0,Math.min(height-cropHeight,y*height-cropHeight/2)),width:cropWidth,height:cropHeight};
}
export function interpolatedFocus(points,ms) {
  if(!Array.isArray(points)||!points.length || points.some(p=>!Number.isFinite(p.at_ms)||!Number.isFinite(p.center_x)||!Number.isFinite(p.center_y))) return {x:.5,y:.5};
  let left=points[0],right=points.at(-1);
  if(ms<=left.at_ms)return {x:left.center_x,y:left.center_y};
  for(let i=1;i<points.length;i++)if(points[i].at_ms>=ms){left=points[i-1];right=points[i];break;}
  const part=Math.max(0,Math.min(1,(ms-left.at_ms)/(right.at_ms-left.at_ms||1)));
  return {x:left.center_x+(right.center_x-left.center_x)*part,y:left.center_y+(right.center_y-left.center_y)*part};
}
export function logoDimensions(imageWidth,imageHeight,canvasWidth,canvasHeight,widthPercent) {
  const maxWidth=Math.max(1,Math.floor(canvasWidth*Math.max(5,Math.min(25,widthPercent))/100));
  const maxHeight=Math.max(1,Math.floor(canvasHeight/4));
  const scale=Math.min(maxWidth/imageWidth,maxHeight/imageHeight);
  return {width:Math.max(1,Math.min(maxWidth,Math.round(imageWidth*scale))),height:Math.max(1,Math.min(maxHeight,Math.round(imageHeight*scale)))};
}
export function overlayLayout(width,height) {
  const uniform=value=>({left:value,right:value,top:value,bottom:value});
  let text,logo;
  if(height>width){
    const side=Math.max(1,width*.06);
    text={left:side,right:side,top:Math.max(1,height*.10),bottom:Math.max(1,height*.18)};
    logo={...text};
  }else{
    const short=Math.min(width,height);
    text=uniform(Math.max(1,short*.06));
    logo=uniform(Math.max(1,short*.03));
  }
  return {text,logo,guide:{x:text.left,y:text.top,width:Math.max(0,width-text.left-text.right),height:Math.max(0,height-text.top-text.bottom)}};
}
export function textInsetsForLogo(width,height,logoAssetId,logoPosition,logoScale,band) {
  const layout=overlayLayout(width,height),insets={...layout.text};
  if(!(height>width)||!(Number(logoAssetId)>0)||band==='middle'||!String(logoPosition).startsWith(band+'_'))return insets;
  const side=String(logoPosition).endsWith('left')?'left':'right';
  const scale=Math.max(5,Math.min(25,Number(logoScale)||10));
  const maximumLogoWidth=Math.max(1,Math.floor(width*scale/100));
  const gap=Math.max(1,Math.min(width,height)*.02);
  insets[side]=Math.max(insets[side],layout.logo[side]+maximumLogoWidth+gap);
  return insets;
}
export function enhanceOutputPreview(form,readCues) {
  const find=s=>form.querySelector(s), value=n=>find('[name="'+n+'"]')?.value ?? '';
  const canvas=find('[data-output-preview]'),video=find('video'),sample=find('[data-overlay-sample]'),status=find('[data-output-status]');
  if(!canvas)return;const context=canvas.getContext('2d');if(!context){status.textContent='Prévia composta indisponível neste navegador. Você pode revisar o original e exportar.';return;}
  const supportsFilter='filter' in context;
  let logo=null,logoId='',logoFailed=false,frame=null;
  let snapshot=null;try{snapshot=JSON.parse(find('[data-preview-transcript]')?.textContent||'null');}catch{}
  const originalInterval=[value('start_time'),value('end_time')];
  const numeric=name=>{
    const node=find('[name="'+name+'"]');let number=Number(value(name));
    if(!Number.isFinite(number))number=Number(node?.defaultValue)||0;
    if(node?.min!==undefined&&node.min!=='')number=Math.max(Number(node.min),number);
    if(node?.max!==undefined&&node.max!=='')number=Math.min(Number(node.max),number);
    return number;
  };
  const safe=find('[data-safe-zones]');
  const requestDraw=()=>{if(frame===null)frame=requestAnimationFrame(()=>{frame=null;draw();});};
  function updateLogo() {
    const id=value('logo_asset_id');
    if(logoId===id)return;
    logoId=id;logo=null;logoFailed=false;
    if(!/^[1-9]\d*$/.test(id))return;
    const image=new Image();
    image.onload=()=>{if(logoId===id){logo=image;requestDraw();}};
    image.onerror=()=>{if(logoId===id){logoFailed=true;requestDraw();}};
    image.src='/marca/logos/'+encodeURIComponent(id);
  }
  function draw() {
    if(form.dataset.reframeBusy==='1')return;
    if(!video.getAttribute('src') || video.readyState<2 || !video.videoWidth) {
      canvas.hidden=true;sample.hidden=false;canvas.dataset.drawn='false';status.textContent='Prévia de estilo: abra o vídeo para visualizar movimento, fades e efeitos de imagem. Logo e tempos por palavra aparecem com o vídeo carregado.';return;
    }
    updateLogo();
    const parts=value('aspect_ratio').split(':').map(Number);
    const ratio=parts.length===2 && parts[0]>0 && parts[1]>0?parts[0]/parts[1]:video.videoWidth/video.videoHeight;
    const w=Math.round(Math.min(640,640*ratio)),h=Math.round(w/ratio);
    const outputWidth=value('aspect_ratio')==='original'?video.videoWidth:value('aspect_ratio')==='16:9'?1920:1080;
    const renderScale=w/outputWidth;
    if(canvas.width!==w||canvas.height!==h){canvas.width=w;canvas.height=h;}
    const ms=(video.currentTime-Number(value('start_time')))*1000;
    const duration=(Number(value('end_time'))-Number(value('start_time')))*1000;
    let focus={x:.5,y:.5};
    if(value('reframe_mode')==='manual') {
      const normalized=n=>value(n)===''?.5:Math.max(0,Math.min(1,Number(value(n))||0));
      focus={x:normalized('focus_x'),y:normalized('focus_y')};
    }
    if(value('reframe_mode')==='auto')try{focus=interpolatedFocus(JSON.parse(value('reframe_keyframes')),ms);}catch{}
    const crop=cropRectangle(video.videoWidth,video.videoHeight,ratio,focus.x,focus.y);
    const effects=videoEffectState({zoom_percent:numeric('zoom_percent'),motion:value('motion'),video_fade_in_ms:numeric('video_fade_in_ms'),video_fade_out_ms:numeric('video_fade_out_ms')},ms,duration);
    const zoomWidth=crop.width/effects.zoom,zoomHeight=crop.height/effects.zoom;
    crop.x+=(crop.width-zoomWidth)*effects.x;crop.y+=(crop.height-zoomHeight)/2;crop.width=zoomWidth;crop.height=zoomHeight;
    context.clearRect(0,0,w,h);
    let pixelEffectsUnavailable=false;
    try{
      context.save();if(supportsFilter)context.filter=numeric('blur')?`blur(${numeric('blur')*renderScale}px)`:'none';
      context.drawImage(video,crop.x,crop.y,crop.width,crop.height,0,0,w,h);context.restore();
      if(numeric('brightness')||numeric('contrast')!==100||numeric('saturation')!==100||numeric('noise')){
        try{
          const pixels=context.getImageData(0,0,w,h),data=pixels.data;
          const brightness=numeric('brightness')*2.55,contrast=numeric('contrast')/100,saturation=numeric('saturation')/100,noise=numeric('noise')*2.55;
          let seed=Math.floor(ms/33.333)+1;
          for(let i=0;i<data.length;i+=4){
            const luma=.2126*data[i]+.7152*data[i+1]+.0722*data[i+2];
            seed=(Math.imul(seed,1664525)+1013904223)>>>0;const grain=noise*(seed/4294967295-.5);
            for(let c=0;c<3;c++)data[i+c]=clamp((luma+(data[i+c]-luma)*saturation-127.5)*contrast+127.5+brightness+grain,0,255);
          }
          context.putImageData(pixels,0,0);
        }catch{pixelEffectsUnavailable=true;}
      }
      if(numeric('vignette')){
        const shade=context.createRadialGradient(w/2,h/2,Math.min(w,h)*.1,w/2,h/2,Math.hypot(w,h)/2);
        shade.addColorStop(0,'transparent');shade.addColorStop(1,`rgba(0,0,0,${numeric('vignette')/100*.8})`);
        context.fillStyle=shade;context.fillRect(0,0,w,h);
      }
      if(effects.alpha<1){context.fillStyle=`rgba(0,0,0,${1-effects.alpha})`;context.fillRect(0,0,w,h);}
    }catch{context.restore();return;}
    canvas.hidden=false;sample.hidden=true;canvas.dataset.drawn='true';
    const short=Math.min(w,h),size=Number(value('font_size'))*short/720,layout=overlayLayout(w,h),margin=layout.text.left;
    const color=/^#[0-9a-f]{6}$/i.test(value('color'))?value('color'):'#FFFFFF';
    const background=/^#[0-9a-f]{6}$/i.test(value('background_color'))?value('background_color'):'#151515';
    const outline=/^#[0-9a-f]{6}$/i.test(value('outline_color'))?value('outline_color'):background;
    const accent=/^#[0-9a-f]{6}$/i.test(value('accent_color'))?value('accent_color'):'#FACC15';
    const font=['Arial','Georgia','Verdana'].includes(value('font_family'))?value('font_family'):'Arial';
    const bold=value('font_weight')==='bold'||(value('font_weight')==='auto'&&['viral','highlight','karaoke'].includes(value('style')));
    const roleWeight=automatic=>value('font_weight')==='auto'?automatic:value('font_weight')==='bold';
    const hasBox=automatic=>value('background_mode')==='auto'?automatic:value('background_mode')==='box';
    function textBlock(text,y,{weight=bold,fontSize=size,align='center',box=false,anchor='middle',animated=null,insets=layout.text,words=[]}={}) {
      if(!text)return;context.save();
      context.font=(numeric('font_italic')?'italic ':'')+(weight?'bold ':'')+fontSize+'px '+font;context.textAlign=align;context.textBaseline='middle';
      if('letterSpacing' in context)context.letterSpacing=numeric('letter_spacing')*renderScale+'px';
      const lines=[];const maxWidth=w-insets.left-insets.right;
      for(const paragraph of text.split('\n')){
        let line='';
        for(const word of paragraph.split(/\s+/)){
          const candidate=line?line+' '+word:word;
          if(line&&context.measureText(candidate).width>maxWidth){lines.push(line);line=word;}else line=candidate;
        }
        if(line)lines.push(line);
      }
      const lineHeight=fontSize*1.2,blockHeight=lines.length*lineHeight;
      const first=y+(anchor==='top'?blockHeight/2:anchor==='bottom'?-blockHeight/2:0)-(lines.length-1)*lineHeight/2;
      const animation=animated?textAnimationState({animation:value('animation'),animation_duration_ms:numeric('animation_duration_ms'),animation_out:value('animation_out'),animation_out_duration_ms:numeric('animation_out_duration_ms')},ms-animated.start,animated.end-animated.start):{alpha:1,scale:1};
      context.globalAlpha=animation.alpha;
      const x=align==='right'?w-insets.right:align==='left'?insets.left:insets.left+maxWidth/2;
      if(animation.scale!==1){
        context.translate(x,y);context.scale(animation.scale,animation.scale);context.translate(-x,-y);
      }
      let wordIndex=0;
      for(const [i,line] of lines.entries()){
        const yy=first+i*lineHeight,measure=Math.min(maxWidth,context.measureText(line).width);
        if(hasBox(box)){context.fillStyle=background;context.fillRect((align==='right'?x-measure:align==='left'?x:x-measure/2)-4,yy-lineHeight/2,measure+8,lineHeight);}
        context.lineWidth=numeric('outline_width')*2*renderScale;context.strokeStyle=outline;context.lineJoin='round';
        context.shadowColor='#000';context.shadowOffsetX=context.shadowOffsetY=numeric('shadow_depth')*renderScale;
        if(words.length){
          let xx=x-measure/2;context.textAlign='left';
          for(const token of line.split(/\s+/)){
            const word=words[wordIndex++],width=context.measureText(token).width;
            context.fillStyle=accent;if(context.lineWidth>0)context.strokeText(token,xx,yy);context.fillText(token,xx,yy);
            const part=word?(value('style')==='karaoke'?clamp((ms-word.start_ms)/Math.max(1,word.end_ms-word.start_ms),0,1):(ms>=word.start_ms?1:0)):0;
            if(part>0){context.save();context.beginPath();context.rect(xx-context.lineWidth,yy-lineHeight/2,width*part+context.lineWidth,lineHeight);context.clip();context.fillStyle=color;context.fillText(token,xx,yy);context.restore();}
            xx+=width+context.measureText(' ').width;
          }
          context.textAlign=align;
        }else{context.fillStyle=color;if(context.lineWidth>0)context.strokeText(line,x,yy,maxWidth);context.fillText(line,x,yy,maxWidth);}
      }
      context.restore();
    }
    canvas.dataset.wordAlignment='segment';
    if(ms>=0&&ms<duration){
      const logoAssetId=Number(value('logo_asset_id'))||0,logoPosition=value('logo_position'),logoScale=Number(value('logo_scale'))||10;
      const topInsets=textInsetsForLogo(w,h,logoAssetId,logoPosition,logoScale,'top');
      const bottomInsets=textInsetsForLogo(w,h,logoAssetId,logoPosition,logoScale,'bottom');
      const cue=readCues(value('srt')).find(c=>ms>=c.start&&ms<c.end);
      if(cue&&value('style')!=='none'&&value('transcript_mode')!=='none'){
        const position=value('position');
        const captionInsets={...(position==='top'?topInsets:position==='bottom'?bottomInsets:layout.text)};
        const extra=Math.min(numeric('caption_margin_x')*short/720,Math.max(0,(w-captionInsets.left-captionInsets.right-2*size)/2));captionInsets.left+=extra;captionInsets.right+=extra;
        const y=clamp((position==='top'?captionInsets.top:position==='middle'?h/2:h-captionInsets.bottom)+numeric('caption_offset_y')*short/720,position==='bottom'?size:0,position==='top'?h-size:h);
        const aligned=originalInterval[0]===value('start_time')&&originalInterval[1]===value('end_time')&&['highlight','karaoke'].includes(value('style'))?matchingWords(cue,snapshot):[];
        canvas.dataset.wordAlignment=aligned.length?'aligned':'segment';
        textBlock(aligned.length?aligned.map(word=>word.text).join(' '):cue.text,y,{box:value('style')==='podcast',anchor:position==='top'?'top':position==='bottom'?'bottom':'middle',animated:cue,insets:captionInsets,words:aligned});
      }
      textBlock(value('title'),topInsets.top,{weight:roleWeight(true),anchor:'top',insets:topInsets});
      textBlock(value('watermark'),h-bottomInsets.bottom,{weight:roleWeight(false),fontSize:size*.6,align:'right',anchor:'bottom',insets:bottomInsets});
      if(ms>=Math.max(0,duration-5000))textBlock(value('cta_text'),h/2,{weight:roleWeight(true),box:true,animated:{start:Math.max(0,duration-5000),end:duration}});
      if(logo){
        const {width:lw,height:lh}=logoDimensions(logo.width,logo.height,w,h,Number(value('logo_scale')));
        const pos=value('logo_position'),insets=layout.logo;
        context.drawImage(logo,pos.endsWith('left')?insets.left:w-insets.right-lw,pos.startsWith('top')?insets.top:h-insets.bottom-lh,lw,lh);
      }
    }
    if(safe.checked){context.save();context.strokeStyle='rgba(255,255,255,.6)';context.lineWidth=1;context.setLineDash([4,4]);context.strokeRect(layout.guide.x,layout.guide.y,layout.guide.width,layout.guide.height);context.restore();}
    const notices=['Prévia aproximada do vídeo. As guias não fazem parte da exportação.'];
    if(logoFailed)notices.push('Logo indisponível: confira sua sessão ou selecione outro logo.');
    if(pixelEffectsUnavailable)notices.push('Este navegador não permitiu prévia dos ajustes de cor e ruído; eles serão aplicados na exportação.');
    else if(numeric('brightness')||numeric('contrast')!==100||numeric('saturation')!==100||numeric('noise')||numeric('blur')||numeric('vignette'))notices.push('Cor, desfoque, ruído e vinheta são aproximações do render.');
    if(numeric('letter_spacing')&&!('letterSpacing' in context))notices.push('Espaçamento entre letras disponível somente na exportação neste navegador.');
    if(numeric('blur')&&!supportsFilter)notices.push('Desfoque disponível somente na exportação neste navegador.');
    if(['highlight','karaoke'].includes(value('style'))&&canvas.dataset.wordAlignment!=='aligned')notices.push('Sem alinhamento correspondente: legenda exibida por segmento, sem inventar tempos por palavra.');
    status.textContent=notices.join(' ');
  }
  for(const event of ['loadeddata','seeked','timeupdate','emptied','error'])video.addEventListener(event,requestDraw);
  const playback=()=>{draw();if(!video.paused&&!video.ended)frame=requestAnimationFrame(()=>{frame=null;playback();});};
  video.addEventListener('play',()=>{if(frame!==null)cancelAnimationFrame(frame);frame=null;playback();});
  form.addEventListener('input',requestDraw);form.addEventListener('change',requestDraw);
  form.addEventListener('reframe:busy',requestDraw);safe.addEventListener('change',requestDraw);
  draw();
}
