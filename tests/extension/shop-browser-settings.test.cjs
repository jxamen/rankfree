const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const {chromium}=require('playwright');
const view=fs.readFileSync('resources/views/admin/shop-keyword/show.blade.php','utf8');
const start=view.indexOf('    // Browser collection preferences:');
const code=view.slice(start,view.indexOf('    @if',start)).replace(/\{\{.*?\}\}/g,m=>m.includes("['parallel_tabs']")?'1':m.includes("['tab_open_interval_ms']")?'3000':"'tab'");
for(const extension of [false,true]) test(`two browser stores keep preferences after reload and reduction (extension=${extension})`,async()=>{
 const browser=await chromium.launch({headless:true});
 try{
  const contexts=await Promise.all([browser.newContext(),browser.newContext()]);
  const pages=await Promise.all(contexts.map(async ctx=>{
   await ctx.route('http://settings.test/**',route=>route.fulfill({contentType:'text/html',body:'<input id="sk-interval"><input id="sk-parallel-tabs"><input id="sk-tab-open-seconds"><select id="sk-collection-mode"><option>tab</option><option>fetch</option></select><span id="sk-parallel-notice"></span>'}));
   const page=await ctx.newPage();await page.goto('http://settings.test');return page;
  }));
  const init=page=>page.evaluate(async({code,extension})=>{
   let currentIntervalMs=4000,parallelTabs=4;const extMode=extension; const cfg={analysisId:42};
   const extCall=async(type,payload)=>{
    if(type==='getShopSettings')return {ok:true,settings:JSON.parse(localStorage.getItem('mockChromeStorage')||'null')};
    localStorage.setItem('mockChromeStorage',JSON.stringify(payload.settings));return {ok:true};
   };
   eval(code+';window.settingsTest={load:loadBrowserSettings,save:persistBrowserSettings,apply:applyBrowserSettings};');
   await window.settingsTest.load();
  },{code,extension});
  await Promise.all(pages.map(init));
  const values=[{interval_ms:2500,parallel_tabs:2,tab_open_interval_ms:700,collection_mode:'tab'},{interval_ms:8500,parallel_tabs:4,tab_open_interval_ms:1200,collection_mode:'fetch'}];
  for(let i=0;i<2;i++)await pages[i].evaluate(v=>settingsTest.save(v),values[i]);
  for(let i=0;i<2;i++){
   await pages[i].reload();await init(pages[i]);
   assert.equal(await pages[i].locator('#sk-parallel-tabs').inputValue(),String(values[i].parallel_tabs));
   assert.deepEqual(await pages[i].evaluate(()=>settingsTest.apply({parallel_reduced:false})),{...values[i],parallel_reduced:false,interval_revision:'browser:'+values[i].interval_ms});
   assert.equal((await pages[i].evaluate(()=>settingsTest.apply({parallel_reduced:true}))).parallel_tabs,1);
   assert.equal(await pages[i].locator('#sk-parallel-tabs').inputValue(),String(values[i].parallel_tabs));
   assert.equal(await pages[i].locator('#sk-parallel-notice').textContent(),'이 분석은 차단으로 1개로 진행 중');
   await pages[i].reload();await init(pages[i]);
   assert.equal((await pages[i].evaluate(()=>settingsTest.apply({}))).parallel_tabs,values[i].parallel_tabs);
  }
 }finally{await browser.close();}
});

test('extension preference handlers persist in chrome local and worker prefers them over server policy',async()=>{
 const vm=require('node:vm');
 const src=fs.readFileSync('extension/background.js','utf8');
 const settingsCode=src.slice(src.indexOf('  async getShopSettings('),src.indexOf('  // Only the requested analysis owns'));
 const fetchStart=src.indexOf('  async fetchShopSerp({');
 const fetchCode=src.slice(fetchStart,src.indexOf('\n  /**',fetchStart));
 const stores=[{},{}],contexts=[];
 const prefs=[{interval_ms:2500,parallel_tabs:2,tab_open_interval_ms:700,collection_mode:'tab'},{interval_ms:8500,parallel_tabs:4,tab_open_interval_ms:1200,collection_mode:'fetch'}];
 for(let i=0;i<2;i++){
  const observed={};
  const ctx=vm.createContext({rfCloseWork:Promise.resolve(),lastScreenSerpAt:0,getStore:async()=>({}),chrome:{storage:{local:{get:async()=>stores[i],set:async v=>Object.assign(stores[i],v)}}},
   apiFetch:async()=>({ok:true,json:{data:{interval_ms:30000,collection_mode:'fetch',parallel_tabs:1,tab_open_interval_ms:9000}}}),
   shopSerpPool:{run:async(a,k,n,execute)=>{observed.tabs=n;return execute({tab:{},policy:{run:async(k,f,ip,gap,revision,probe,scope)=>{Object.assign(observed,{gap,revision,scope});return {ok:true,cached:true};}}});}}
  });
  vm.runInContext('globalThis.handlers={'+settingsCode+fetchCode+'}',ctx);contexts.push(ctx);
  assert.equal((await ctx.handlers.setShopSettings({settings:prefs[i]})).ok,true);
  await ctx.handlers.fetchShopSerp({keyword:'test'});
  assert.equal(observed.tabs,prefs[i].parallel_tabs);assert.equal(observed.gap,prefs[i].interval_ms);
  assert.equal(observed.scope.startsWith(prefs[i].collection_mode),true);
 }
 assert.equal((await contexts[0].handlers.getShopSettings()).settings.parallel_tabs,2);
 assert.equal((await contexts[1].handlers.getShopSettings()).settings.parallel_tabs,4);
 assert.equal((await contexts[0].handlers.setShopSettings({settings:{...prefs[0],parallel_tabs:0}})).ok,false);
 assert.equal(stores[0].rfShopSettings.parallel_tabs,2);
});

test('manual save clears server and extension reduction before persisting new browser values',async()=>{
 const vm=require('node:vm'), src=fs.readFileSync('extension/background.js','utf8');
 const code=src.slice(src.indexOf('  async getShopSettings('),src.indexOf('  // Only the requested analysis owns'));
 const calls=[],data={};
 const ctx=vm.createContext({getStore:async()=>({token:'test'}),apiFetch:async(path,args)=>{calls.push([path,args.body.kind]);return {ok:true};},shopSerpPool:{clearReduction:async id=>calls.push(['extension',id])},chrome:{storage:{local:{get:async()=>data,set:async v=>Object.assign(data,v)}}}});
 vm.runInContext('globalThis.handlers={'+code+'}',ctx);
 const settings={parallel_tabs:2,interval_ms:2500,tab_open_interval_ms:700,collection_mode:'tab'};
 assert.equal((await ctx.handlers.setShopSettings({analysisId:42,manual:true,settings})).ok,true);
 assert.deepEqual(calls,[['/api/ext/shop-keyword/42/collector-event','parallel_reset'],['extension',42]]);
 assert.deepEqual(data.rfShopSettings,settings);
});
