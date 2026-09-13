import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
const source=await readFile(new URL('../../public/assets/js/editor-output-preview.js',import.meta.url),'utf8');
const {cropRectangle,interpolatedFocus,logoDimensions,overlayLayout,textInsetsForLogo}=await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64'));
assert.equal(typeof overlayLayout,'function');
assert.equal(typeof textInsetsForLogo,'function');
const assertLayoutClose=(actual,expected)=>{
  for(const group of ['text','logo','guide'])for(const [name,value] of Object.entries(expected[group]))
    assert.ok(Math.abs(actual[group][name]-value)<.01,`${group}.${name}: ${actual[group][name]} != ${value}`);
};
const assertInsetsClose=(actual,expected)=>{
  for(const [name,value] of Object.entries(expected))
    assert.ok(Math.abs(actual[name]-value)<.01,`${name}: ${actual[name]} != ${value}`);
};
assertLayoutClose(overlayLayout(360,640),{
  text:{left:21.6,right:21.6,top:64,bottom:115.2},logo:{left:21.6,right:21.6,top:64,bottom:115.2},
  guide:{x:21.6,y:64,width:316.8,height:460.8},
});
assertLayoutClose(overlayLayout(512,640),{
  text:{left:30.72,right:30.72,top:64,bottom:115.2},logo:{left:30.72,right:30.72,top:64,bottom:115.2},
  guide:{x:30.72,y:64,width:450.56,height:460.8},
});
assertLayoutClose(overlayLayout(640,640),{
  text:{left:38.4,right:38.4,top:38.4,bottom:38.4},logo:{left:19.2,right:19.2,top:19.2,bottom:19.2},
  guide:{x:38.4,y:38.4,width:563.2,height:563.2},
});
assertLayoutClose(overlayLayout(640,360),{
  text:{left:21.6,right:21.6,top:21.6,bottom:21.6},logo:{left:10.8,right:10.8,top:10.8,bottom:10.8},
  guide:{x:21.6,y:21.6,width:596.8,height:316.8},
});
assertInsetsClose(textInsetsForLogo(360,640,1,'top_right',10,'top'),{left:21.6,right:64.8,top:64,bottom:115.2});
assertInsetsClose(textInsetsForLogo(360,640,1,'top_left',10,'top'),{left:64.8,right:21.6,top:64,bottom:115.2});
assertInsetsClose(textInsetsForLogo(360,640,1,'top_right',25,'top'),{left:21.6,right:118.8,top:64,bottom:115.2});
assertInsetsClose(textInsetsForLogo(512,640,1,'top_right',10,'top'),{left:30.72,right:91.96,top:64,bottom:115.2});
assertInsetsClose(textInsetsForLogo(360,640,1,'bottom_right',10,'bottom'),{left:21.6,right:64.8,top:64,bottom:115.2});
assertInsetsClose(textInsetsForLogo(360,640,1,'bottom_right',10,'top'),overlayLayout(360,640).text);
assertInsetsClose(textInsetsForLogo(360,640,1,'top_right',25,'middle'),overlayLayout(360,640).text);
assertInsetsClose(textInsetsForLogo(360,640,0,'top_right',25,'top'),overlayLayout(360,640).text);
assertInsetsClose(textInsetsForLogo(640,640,1,'top_right',25,'top'),overlayLayout(640,640).text);
assertInsetsClose(textInsetsForLogo(640,360,1,'bottom_left',25,'bottom'),overlayLayout(640,360).text);
assert.deepEqual(logoDimensions(1,2048,1920,1080,25),{width:1,height:270});
assert.deepEqual(logoDimensions(2048,1,1920,1080,25),{width:480,height:1});
assert.deepEqual(logoDimensions(64,64,1920,1080,10),{width:192,height:192});
assert.deepEqual(cropRectangle(1920,1080,9/16),{x:656.25,y:0,width:607.5,height:1080});
assert.deepEqual(cropRectangle(1920,1080,9/16,0,.5),{x:0,y:0,width:607.5,height:1080});
assert.deepEqual(cropRectangle(1920,1080,9/16,1,.5),{x:1312.5,y:0,width:607.5,height:1080});
assert.deepEqual(cropRectangle(1080,1920,16/9,.5,1),{x:0,y:1312.5,width:1080,height:607.5});
const points=[{at_ms:0,center_x:.2,center_y:.4},{at_ms:1000,center_x:.8,center_y:.6},{at_ms:2000,center_x:.4,center_y:.8}];
assert.deepEqual(interpolatedFocus(points,500),{x:.5,y:.5});
assert.ok(Math.abs(interpolatedFocus(points,1500).x-.6)<1e-12);
assert.deepEqual(interpolatedFocus(points,3000),{x:.4,y:.8});
assert.deepEqual(interpolatedFocus([],500),{x:.5,y:.5});
console.log('PASS composed-preview manual crop bounds, aspect ratios and automatic interpolation');
