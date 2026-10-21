const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const {chromium}=require('playwright');
const event=()=>{const listeners=new Set();return {addListener:f=>listeners.add(f),removeListener:f=>listeners.delete(f),emit:(...args)=>listeners.forEach(f=>f(...args))};};
const storage=()=>{const data={};return {get:async keys=>structuredClone(Object.fromEntries((Array.isArray(keys)?keys:[keys]).map(k=>[k,data[k]]))),set:async v=>Object.assign(data,structuredClone(v))};};
test('rendered gate -> ct opens once -> whole collector waits with zero further navigation/save -> same document recovery -> correct next item',async()=>{
 const browser=await chromium.launch({headless:true});
 const ctx=await browser.newContext();
 const pages=new Map(),navigations=[],saved=[];
 const messages=event(),updated=event(),removed=event();
 const session=storage(),local=storage();let id=0;
 const normal=keyword=>'<section data-shopping-block>정상 검색 결과</section><script>var newshopping={};newshopping._INITIAL_STATE='+JSON.stringify({initProps:{pagedSlot:[{slots:[{data:{channelProductId:keyword==='next'?'222':'111',rank:1,sourceType:'SAS'}}]}]}})+';</script>';
 const gate='<h2>검색 서비스 이용이 제한되었습니다.</h2><p>보안 절차를 통과하면 검색 서비스를 정상적으로 이용하실 수 있습니다.</p><button>제한 해제</button>';
 const detector=fs.readFileSync('extension/captcha-detector.js','utf8'),nav=fs.readFileSync('extension/content/shop-navigation.js','utf8');
 await ctx.route('**/*',route=>{const url=new URL(route.request().url());assert.equal(url.hostname,'m.search.naver.com');const keyword=url.searchParams.get('query');navigations.push(keyword+(url.searchParams.get('ct')==='1'?':ct=1':''));return route.fulfill({contentType:'text/html; charset=utf-8',body:keyword==='blocked'?gate:normal(keyword)});});
 await ctx.exposeBinding('rfSend',({page},msg)=>{
  if(msg.type==='__rfCollectionContext')return {owned:true};
  if(msg.type==='captchaRules')return {};
  const entry=[...pages].find(([,p])=>p===page);
  if(entry)messages.emit(msg,{tab:{id:entry[0]},url:page.url(),frameId:0},()=>{});
 });
 await ctx.addInitScript(()=>{window.rfListeners=[];window.chrome={runtime:{sendMessage:window.rfSend,onMessage:{addListener:f=>rfListeners.push(f)}}};});
 const load=async(page,url)=>{await page.goto(url);await page.addScriptTag({content:detector});await page.addScriptTag({content:nav});};
 const chrome={storage:{session,local},runtime:{onMessage:messages},windows:{update:async()=>{}},tabs:{onUpdated:updated,onRemoved:removed,
  create:async opts=>{const n=++id,p=await ctx.newPage();pages.set(n,p);await load(p,opts.url);return {id:n,url:p.url(),windowId:1};},
  get:async n=>{const p=pages.get(n);if(!p)throw Error('missing');return {id:n,url:p.url(),status:'complete',windowId:1};},
  update:async(n,opts)=>{const p=pages.get(n);if(opts.url)await load(p,opts.url);return {id:n,url:p.url(),windowId:1};},
  sendMessage:async(n,msg)=>pages.get(n).evaluate(msg=>new Promise(resolve=>{for(const listener of rfListeners)listener(msg,null,resolve);}),msg),
 }};
 const codeContext=vm.createContext({chrome,URL,Date,Intl,setTimeout,clearTimeout,setInterval,clearInterval});
 for(const name of ['shop-serp-tab','shop-serp-policy','shop-serp-pool'])vm.runInContext(fs.readFileSync('extension/'+name+'.js','utf8'),codeContext);
 let clock=Date.now();const cache=new Map();let pool;
 pool=new codeContext.ShopSerpPool({storage:local,tabFactory:slot=>new codeContext.ShopSerpTab({slot,browser:chrome,wait:async()=>{}}),policyFactory:slot=>new codeContext.ShopSerpPolicy({storage:pool.laneStorage(slot),now:()=>clock,wait:async ms=>clock+=ms,cache:{get:async key=>cache.get(key),put:async(key,value)=>cache.set(key,value)}})});
 const run=async keyword=>{
  const r=await pool.run(42,keyword,1,({tab,policy})=>policy.run(keyword,()=>tab.read(keyword),'browser',1000,null,()=>tab.peek(keyword)));
  if(r.ok)saved.push([keyword,r.html]);return r;
 };
 try {
  assert.equal((await run('saved')).ok,true);
  const stopped=await run('blocked');assert.equal(stopped.captcha,true,JSON.stringify({stopped,document:await pages.get(1).evaluate(()=>({url:location.href,text:document.body.innerText,detected:RfCaptcha.inspect()}))}));
  for(const keyword of ['next','saved','next'])assert.equal((await run(keyword)).captcha_wait,true);
  assert.deepEqual(navigations,['saved','blocked','blocked:ct=1']);assert.equal(saved.length,1);
  // A person completed the gate; only replace the fixture DOM, no challenge-solving request.
  const page=pages.get(1);await page.setContent(normal('blocked'));await page.evaluate(()=>{window.rfListeners=[]});
  await page.addScriptTag({content:detector});await page.addScriptTag({content:nav});
  const resumed=await run('next');assert.equal(resumed.ok,true);assert.ok(resumed.captcha_cleared_at);
  assert.deepEqual(navigations,['saved','blocked','blocked:ct=1','next']);assert.equal(saved.length,2);
  assert.equal(saved[1][0],'next');assert.match(saved[1][1],/222/);assert.doesNotMatch(saved[1][1],/111/);
  assert.equal(pages.size,1);
 } finally {await browser.close();}
});
