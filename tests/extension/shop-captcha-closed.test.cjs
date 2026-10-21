const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const {chromium}=require('playwright');
function setup(data={}) {
 const storage={data,get:async keys=>structuredClone(Object.fromEntries((Array.isArray(keys)?keys:[keys]).map(k=>[k,data[k]]))),set:async v=>Object.assign(data,structuredClone(v))};
 const ctx=vm.createContext({Date,chrome:{storage:{session:{set:async()=>{}}}}});
 vm.runInContext(fs.readFileSync('extension/shop-serp-pool.js','utf8'),ctx);
 const live=new Map(),readers=new Map();let searches=0;
 const p=new ctx.ShopSerpPool({storage,wait:async()=>{},tabFactory:slot=>({hasTab:async()=>live.get(slot)!==false,peek:async()=>readers.get(slot)||{captcha:true}}),policyFactory:()=>({resume:async()=>{}})});
 return {p,storage,live,readers,run:()=>p.run(42,'next',1,async()=>{searches++;return {ok:true};}),searches:()=>searches};
}
const hold={analysisId:42,keyword:'blocked',observed_at:100,evidence:{visible:true}};
test('closed blocked tab returns pause, survives worker restart and opens nothing until explicit continue',async()=>{
 const f=setup({rfSerpCaptchaWaits:{0:hold}});f.live.set(0,false);
 let r=await f.run();assert.equal(r.paused,true);assert.equal(r.tab_closed,true);assert.equal(r.captcha,undefined);assert.equal(f.searches(),0);
 assert.equal((await f.p.captchaGuard(false)).tab_closed,true);
 const next=setup(f.storage.data);next.live.set(0,false);
 r=await next.run();assert.equal(r.paused,true);assert.equal(next.searches(),0);
 await next.p.restart(42);assert.equal((await next.run()).ok,true);assert.equal(next.searches(),1);
});
test('closed lane never hides another live challenge and no new searches run',async()=>{
 const f=setup({rfSerpCaptchaWaits:{0:hold,1:{...hold,keyword:'live'}}});f.live.set(0,false);
 let r=await f.run();assert.equal(r.captcha_wait,true);assert.equal(r.blocked_keyword,'live');assert.equal(f.searches(),0);
 f.live.set(1,false);r=await f.run();assert.equal(r.paused,true);assert.equal(r.captcha,undefined);assert.equal(f.searches(),0);
});
test('close during peek is treated as pause; temporary inspection failure retains live hold',async()=>{
 const f=setup({rfSerpCaptchaWaits:{0:hold}});
 f.p.lane(0).tab.peek=async()=>{throw Error('temporary message failure');};
 assert.equal((await f.run()).captcha_wait,true);
 f.p.lane(0).tab.peek=async()=>({tab_closed:true});
 assert.equal((await f.run()).paused,true);assert.equal(f.searches(),0);
});
test('closing all tabs clears all parallel and server modal entries in the actual retry branch',async()=>{
 const view=fs.readFileSync('resources/views/admin/shop-keyword/show.blade.php','utf8');
 const controller=view.slice(view.indexOf('    // Captcha modal controller:'),view.indexOf('    // End captcha modal controller.'));
 const start=view.indexOf('                    if (res?.tab_closed)');
 const closeBranch=view.slice(start,view.indexOf('                    if (res?.parallel_reduced)',start));
 const browser=await chromium.launch({headless:true});
 try {
  const page=await browser.newPage();
  await page.setContent('<meta charset="utf-8"><style>body{font:18px sans-serif;background:#f6f8fa;padding:48px;color:#172033}main{background:white;border:1px solid #ddd;border-radius:12px;padding:32px}button{font:inherit;padding:10px 20px}p{line-height:1.7}</style><main><p>수집 화면 동작 시험 · 실제 고객 주문 아님</p><h1>수집 탭을 모두 닫았을 때</h1><p id="test-label">보안문자 대기 — 수집 탭에서 풀면 바로 이어서 해요</p><button id="test-stop">중단</button></main>'+view.slice(view.indexOf('<dialog id="sk-captcha-modal"'),view.indexOf('<style>\n.sk-del')));
  await page.evaluate(({controller,closeBranch})=>{
   const cfg={core:'시험',urls:{collectorEvent:'/test'}},label=document.getElementById('test-label'),stopBtn=document.getElementById('test-stop'),it={id:1};let stopped=false;
   const events=[];const post=async(url,body)=>events.push(body.kind),extCall=async()=>({ok:true}),halt=()=>{};
   eval(controller+`;window.state=captchaState;window.closeResult=async res=>{${closeBranch}};window.result=()=>({stopped,label:label.textContent,button:stopBtn.textContent,events});`);
  },{controller,closeBranch});
  await page.evaluate(()=>{state(1,'첫째',true);state(2,'둘째',true);state('server','대기',true);});
  assert.equal(await page.locator('dialog').evaluate(d=>d.open),true);
  await page.evaluate(()=>closeResult({tab_closed:true,paused:true}));
  assert.equal(await page.locator('dialog').evaluate(d=>d.open),false);
  const state=await page.evaluate(()=>result());assert.equal(state.stopped,true);assert.equal(state.button,'이어서');assert.match(state.label,/수집 탭을 닫아/);assert.deepEqual(state.events,['tab_closed']);
  await page.screenshot({path:process.env.RF_CLOSED_SCREENSHOT || 'captcha-closed-test.png'});
 }finally{await browser.close();}
});
