const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const {chromium}=require('playwright');
const view=fs.readFileSync(process.env.RF_CAPTCHA_VIEW || 'resources/views/admin/shop-keyword/show.blade.php','utf8');
const controller=view.slice(view.indexOf('    // Captcha modal controller:'),view.indexOf('    // End captcha modal controller.'));
test('browser modal transitions: dismiss, repeated wait, recovery, next captcha, parallel lanes and fallback stop',async()=>{
 const browser=await chromium.launch({headless:true});
 try {
  const page=await browser.newPage();
  await page.setContent(view.slice(view.indexOf('<dialog id="sk-captcha-modal"'),view.indexOf('<style>\n.sk-del')));
  await page.evaluate(code=>{
   window.calls=[];window.focusOk=false;
   const cfg={core:'기본',analysisId:42,urls:{pause:'/pause'}};
   const extCall=async(type,payload)=>{calls.push({type,...payload});return {ok:payload.action==='close'||focusOk};};
   const post=async(url,body)=>{calls.push({url,...body});return {ok:true};};
   const halt=()=>{};
   eval(code+';window.state=captchaState;');
  },controller);
  const state=(key,kw,wait,result)=>page.evaluate(([a,b,c,d])=>window.state(a,b,c,d),[key,kw,wait,result]);
  const open=()=>page.locator('dialog').evaluate(el=>el.open);
  await state(1,'<img src=x onerror=alert(1)>',true);
  assert.equal(await open(),true);assert.equal(await page.locator('#sk-captcha-keyword img').count(),0);
  const centered=await page.locator('dialog').evaluate(el=>{const r=el.getBoundingClientRect();return Math.abs(r.x+r.width/2-innerWidth/2)<2 && Math.abs(r.y+r.height/2-innerHeight/2)<2;});
  assert.equal(centered,true);
  await page.getByRole('button',{name:'닫기',exact:true}).click();
  await state(1,'다시',true);assert.equal(await open(),false);
  await state(1,'',false);await state(2,'새 보안문자',true);assert.equal(await open(),true);
  await state(3,'다른 탭',true);await state(2,'',false);assert.equal(await open(),true);
  await state(3,'',false);assert.equal(await open(),false);
  await state(4,'복구',true);await page.getByRole('button',{name:'수집 탭 열기',exact:true}).click();
  await page.getByRole('button',{name:'탭 닫고 멈추기',exact:true}).waitFor({state:'visible'});
  await page.getByRole('button',{name:'탭 닫고 멈추기',exact:true}).click();
  assert.equal(await open(),false);
  assert.deepEqual(await page.evaluate(()=>calls),[{type:'controlShopSerpTab',analysisId:42,action:'focus'},{url:'/pause',paused:true},{type:'controlShopSerpTab',analysisId:42,action:'close'}]);
  await state('server','제한',true);assert.equal(await open(),true);
  await page.keyboard.press('Escape');await state('server','제한',true);assert.equal(await open(),false);
  await state('server','',false);await state(5,'정상 후 재발',true);
  await page.evaluate(()=>focusOk=true);await page.getByRole('button',{name:'수집 탭 열기',exact:true}).click();
  assert.equal(await page.locator('#sk-captcha-stop').isVisible(),false);
  await state(5,'',false);assert.equal(await open(),false);
  // Cached/parallel successes cannot prove the blocked documents recovered.
  await state(10,'old lane',true,{observed_at:100});
  await state(11,'other lane',true,{observed_at:110});
  await state('server','aggregate',true);
  await state(11,'',false,{ok:true,cached:true,observed_at:200});assert.equal(await open(),true);
  await state(11,'',false,{ok:true,observed_at:200});assert.equal(await open(),true);
  await state(11,'',false,{ok:true,observed_at:200,captcha_cleared_at:200});assert.equal(await open(),false);
  await state(10,'late old result',true,{observed_at:100});assert.equal(await open(),false);
  await state(10,'policy only',true,{cooldown:true,observed_at:100});assert.equal(await open(),false);
  await state(12,'new challenge',true,{observed_at:300});assert.equal(await open(),true);
  await page.getByRole('button',{name:'닫기',exact:true}).click();
  await state(12,'same challenge',true,{observed_at:300});assert.equal(await open(),false);
  await state(12,'policy wait',true,{cooldown:true,observed_at:300});assert.equal(await open(),false);
  await state(13,'newer challenge',true,{observed_at:400});assert.equal(await open(),true);
  await state(13,'',false,{ok:true,observed_at:350,captcha_cleared_at:350});assert.equal(await open(),true);
  await state(13,'',false,{ok:true,observed_at:450,captcha_cleared_at:450});assert.equal(await open(),false);
  await state(14,'blocked in another analysis',true,{captcha_wait:true,cooldown:true,observed_at:500});assert.equal(await open(),true);

 } finally {await browser.close();}
});
test('extension focuses/closes only owned analysis tabs and stops missing-tab analysis',async()=>{
 const src=fs.readFileSync('extension/background.js','utf8');
 const code=src.slice(src.indexOf('  async controlShopSerpTab('),src.indexOf('  async fetchShopSerp({'));
 const events=[],data={};
 const ctx=vm.createContext({rfCloseWork:Promise.resolve(),getStore:async()=>({token:'t'}),apiFetch:async()=>({ok:true}),removeTab:async id=>events.push(['remove',id]),chrome:{storage:{session:{get:async()=>({rfSerpAnalysisId:42,rfSerpTabId:7,'rfSerpAnalysisId:lane:1':99,'rfSerpTabId:lane:1':8})},local:{get:async()=>({}),set:async v=>Object.assign(data,v)}},tabs:{get:async id=>({id,windowId:3}),update:async id=>events.push(['focus',id])},windows:{update:async id=>events.push(['window',id])}}});
 vm.runInContext('globalThis.handler={'+code+'}',ctx);
 await ctx.handler.controlShopSerpTab({analysisId:42,action:'focus'});
 await ctx.handler.controlShopSerpTab({analysisId:42,action:'close'});
 assert.deepEqual(events,[['focus',7],['window',3],['remove',7]]);
 assert.equal(data['rfSerpCloseAck:42'],true);assert.equal(data['rfSerpClosed:99'],undefined);
 assert.equal((await ctx.handler.controlShopSerpTab({analysisId:100,action:'focus'})).ok,false);
 await ctx.handler.controlShopSerpTab({analysisId:100,action:'close'});
 assert.deepEqual(Array.from(data['rfSerpClosed:100']),[0,1,2,3]);
});

test('console bridge forwards tab control with analysis identity and reply correlation',()=>{
 let listener,sent,reply;
 const window={addEventListener:(type,fn)=>listener=fn,postMessage:m=>reply=m};
 vm.runInNewContext(fs.readFileSync('extension/content/console-bridge.js','utf8'),{window,document:{documentElement:{setAttribute(){}}},chrome:{runtime:{sendMessage:(m,cb)=>{sent=m;cb({ok:true});}}}});
 listener({source:window,data:{source:'rankfree-console',type:'controlShopSerpTab',analysisId:'42',action:'close',requestId:'modal-1'}});
 assert.equal(sent.payload.analysisId,42);assert.equal(sent.payload.action,'close');
 assert.equal(reply.requestId,'modal-1');assert.equal(reply.type,'controlShopSerpTabResult');
});
