import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
const source=await readFile(new URL('../../public/assets/js/editor-output-preview.js',import.meta.url),'utf8');
const preview=await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64'));
assert.equal(typeof preview.videoEffectState,'function','video effects must affect the preview');
assert.deepEqual(preview.videoEffectState({zoom_percent:125,motion:'zoom_in',video_fade_in_ms:1000,video_fade_out_ms:1000},0,4000),{zoom:1,x:.5,alpha:0});
assert.deepEqual(preview.videoEffectState({zoom_percent:125,motion:'zoom_in',video_fade_in_ms:1000,video_fade_out_ms:1000},2000,4000),{zoom:1.125,x:.5,alpha:1});
assert.deepEqual(preview.videoEffectState({zoom_percent:125,motion:'pan_left'},4000,4000),{zoom:1.25,x:0,alpha:1});
assert.equal(preview.videoEffectState({video_fade_in_ms:3000,video_fade_out_ms:3000},500,1000).alpha,1);
const words=[{start_ms:0,end_ms:500,text:'Olá'},{start_ms:500,end_ms:1000,text:'mundo'}];
const snapshot={cues:[{start_ms:0,end_ms:1000,text:'Olá mundo',words}]};
assert.deepEqual(preview.matchingWords({start:0,end:1000,text:'Olá mundo'},snapshot),words);
assert.deepEqual(preview.matchingWords({start:0,end:1000,text:'Outro texto'},snapshot),[]);
assert.deepEqual(preview.matchingWords({start:10,end:1000,text:'Olá mundo'},snapshot),[]);
assert.deepEqual(preview.textAnimationState({animation:'fade',animation_duration_ms:400,animation_out:'fade',animation_out_duration_ms:200},200,1000),{alpha:.5,scale:1});
const pop=preview.textAnimationState({animation:'pop',animation_duration_ms:400,animation_out:'none'},200,1000);
assert.equal(pop.alpha,1);assert.ok(Math.abs(pop.scale-.925)<1e-12);
console.log('PASS effect geometry, fade clamping and exact aligned cue matching');
const library=await readFile(new URL('../../public/assets/js/editor-library.js',import.meta.url),'utf8');
const {normalizeTemplate}=await import('data:text/javascript;base64,'+Buffer.from(library).toString('base64'));
const defaults={title:'',motion:'none',zoom_percent:100};
const input=()=>({tagName:'INPUT',min:'',max:'',maxLength:120});
for(const title of ['a\nb','a\tb','   '])assert.throws(()=>normalizeTemplate({aspect_ratio:'original',options:{title}},defaults,input),'overlay text must meet server constraints');

// Exercise the actual draw path with a minimal Canvas adapter, including a browser
// that has no filter property. Missing API must not be masked by an expando.
for(const supported of [false,true]){
  const applied=[],draws=[];
  const context={clearRect(){},save(){},restore(){},drawImage(){draws.push(true);}};
  if(supported)Object.defineProperty(context,'filter',{get:()=> 'none',set:value=>applied.push(value)});
  const canvas={dataset:{},getContext:()=>context},status={textContent:''};
  const video={getAttribute:()=> 'fixture',readyState:2,videoWidth:320,videoHeight:180,currentTime:.5,addEventListener(){}};
  const values={start_time:'0',end_time:'1',aspect_ratio:'original',reframe_mode:'original',zoom_percent:'100',motion:'none',contrast:'100',saturation:'100',blur:'2',font_size:'44',style:'minimal'};
  const controls={'[data-output-preview]':canvas,video,'[data-overlay-sample]':{},'[data-output-status]':status,'[data-safe-zones]':{checked:false,addEventListener(){}},'[data-preview-transcript]':{textContent:'null'}};
  const form={dataset:{},addEventListener(){},querySelector(selector){
    if(selector.startsWith('[name='))return {value:values[selector.slice(7,-2)]||'',min:'',max:''};
    return controls[selector];
  }};
  preview.enhanceOutputPreview(form,()=>[]);
  assert.equal(draws.length,1,'video preview remains available');
  if(supported){assert.deepEqual(applied,['blur(4px)']);assert.doesNotMatch(status.textContent,/Desfoque disponível somente/);}
  else{assert.match(status.textContent,/Desfoque disponível somente na exportação/);assert.equal('filter' in context,false);}
}
console.log('PASS supported canvas blur and honest unsupported-filter notice');
