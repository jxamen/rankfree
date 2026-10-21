const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const {chromium}=require('playwright');
const code=name=>fs.readFileSync('extension/'+name+'.js','utf8');
const normal={captcha:false,evidence:{version:'visible-v1',signal:'normal-results',visible:false}};
const gate={ok:false,captcha:true,evidence:{signal:'visible-challenge',visible:true}};
function setup(reply=normal, tab={url:'https://m.search.naver.com/search.naver?query=original',status:'complete'}) {
 const data={rfSerpTabId:1,rfSerpTabKeyword:'original',rfSerpRecovery:{keyword:'original',current:'original'}};
 const storage={get:async keys=>Object.fromEntries((Array.isArray(keys)?keys:[keys]).map(k=>[k,data[k]])),set:async d=>Object.assign(data,d)};
 const chrome={storage:{session:storage},tabs:{get:async()=>tab,sendMessage:async()=>reply}};
 const ctx=vm.createContext({chrome,URL,Date});vm.runInContext(code('shop-serp-tab'),ctx);vm.runInContext(code('shop-serp-pool'),ctx);
 const reader=new ctx.ShopSerpTab();
 const pool=new ctx.ShopSerpPool({storage,tabFactory:()=>reader,policyFactory:()=>({})});
 return {reader,pool,data};
}
test('normal result below viewport is affirmative recovery evidence, hidden results are not',async()=>{
 const browser=await chromium.launch({headless:true});
 try {
  const page=await browser.newPage();await page.route('**/*',r=>r.fulfill({contentType:'text/html',body:'<body></body>'}));
  await page.goto('https://m.search.naver.com/search.naver?query=original');
  for(const [html,expected] of [
   ['<div style="height:2300px">일반 검색 결과</div><section data-slog-container="shp_lis">네이버 쇼핑 인기상품</section>',true],
   ['<section hidden data-slog-container="shp_lis">상품</section>',false],
   ['<div style="opacity:0"><section data-slog-container="shp_lis">상품</section></div>',false],
   ['<p>검색 결과가 없습니다</p>',false],
   ['<section data-shopping-block>상품</section><h2>검색 서비스 이용이 제한되었습니다.</h2><button>제한 해제</button>',false],
  ]) {
   await page.setContent(html);await page.addScriptTag({content:code('captcha-detector')});
   const r=await page.evaluate(()=>RfCaptcha.inspect());assert.equal(r.evidence.signal==='normal-results'&&!r.captcha,expected);
  }
 } finally {await browser.close();}
});
test('normal loaded search without readable products clears stale hold, never manufactures product success',async()=>{
 const {reader,pool,data}=setup({...normal,ok:false,products:[],result_source:'integrated'});
 await pool.holdCaptcha(0,42,'original',gate);
 const r=await reader.peek('original');assert.equal(r.ok,false);assert.equal(r.retryable,true);assert.equal(r.normal_page,true);assert.equal(r.html,undefined);
 assert.equal(await pool.captchaGuard(),null);assert.deepEqual(Object.keys(data.rfSerpCaptchaWaits),[]);assert.ok(data.rfSerpCaptchaClearedAt);
});
for(const [label,reply,tab] of [
 ['unknown',{ok:false,captcha:false,evidence:{signal:'none',visible:false}}],
 ['real gate',gate],
 ['contradictory gate',{...normal,captcha:true}],
 ['cached',{...normal,cached:true}],
 ['loading',normal,{url:'https://m.search.naver.com/search.naver?query=original',status:'loading'}],
 ['wrong keyword',normal,{url:'https://m.search.naver.com/search.naver?query=another',status:'complete'}],
 ['login',normal,{url:'https://nid.naver.com/nidlogin.login',status:'complete'}],
 ['external',normal,{url:'https://example.com/?query=original',status:'complete'}],
])test(label+' cannot prove normal-page recovery',async()=>{
 const {pool}=setup(reply,tab);await pool.holdCaptcha(0,42,'original',gate);
 assert.equal((await pool.captchaGuard()).captcha_wait,true);
});
test('normal page recovery closes old modal even when product read failed; newer gate remains',()=>{
 const view=fs.readFileSync('resources/views/admin/shop-keyword/show.blade.php','utf8');
 const start=view.indexOf('    function captchaState('),end=view.indexOf("    document.getElementById('sk-captcha-dismiss')",start);
 const waits=new Map([['old','original']]),observed=new Map([['old',10]]),modal={open:true,close(){this.open=false},showModal(){this.open=true}};
 const ctx=vm.createContext({captchaWaits:waits,captchaObserved:observed,captchaRecoveredAt:0,captchaDismissed:false,captchaModal:modal,captchaStop:{},captchaFeedback:{},cfg:{core:'original'},document:{getElementById:()=>({})}});
 vm.runInContext(view.slice(start,end),ctx);
 ctx.captchaState('current','original',false,{ok:false,captcha:false,retryable:true,captcha_cleared_at:20});
 assert.equal(modal.open,false);assert.equal(waits.size,0);
 ctx.captchaState('old','original',true,{captcha:true,captcha_wait:true,observed_at:25});
 ctx.captchaState('current','original',false,{ok:false,cooldown:true,captcha:false,captcha_cleared_at:26});
 assert.equal(modal.open,false);assert.equal(waits.size,0);
 ctx.captchaState('new','new',true,{captcha:true,captcha_wait:true,observed_at:30});
 ctx.captchaState('current','original',false,{ok:false,captcha:false,captcha_cleared_at:20});
 assert.equal(modal.open,true);assert.equal(waits.get('new'),'new');
});
test('normal recovery preserves another live gate, then respects a closed-tab pause',async()=>{
 const {pool,data}=setup();
 const readers=new Map([[0,{...normal,ok:false,normal_page:true}],[1,gate]]),live=new Map();
 pool.tabFactory=slot=>({peek:async()=>readers.get(slot),hasTab:async()=>live.get(slot)!==false});
 await pool.holdCaptcha(0,42,'normal',gate);await pool.holdCaptcha(1,42,'still blocked',gate);
 let r=await pool.captchaGuard();assert.equal(r.captcha_wait,true);assert.equal(r.blocked_keyword,'still blocked');
 assert.deepEqual(Object.keys(data.rfSerpCaptchaWaits),['1']);assert.equal(data.rfSerpCaptchaClearedAt,undefined);
 live.set(1,false);r=await pool.captchaGuard();assert.equal(r.paused,true);assert.equal(r.captcha,undefined);
 assert.equal(data.rfSerpCaptchaClearedAt,undefined);
});
