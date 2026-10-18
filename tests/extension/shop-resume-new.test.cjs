const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('extension/background.js','utf8');
function storage(data={}) {return {data,get:async keys=>keys===null?{...data}:Object.fromEntries((Array.isArray(keys)?keys:[keys]).map(k=>[k,data[k]])),set:async values=>Object.assign(data,values)};}
function event(){const listeners=new Set();return {addListener:f=>listeners.add(f),removeListener:f=>listeners.delete(f),emit:(...a)=>listeners.forEach(f=>f(...a))};}
for (const suspended of [false,true]) test('close → pause → resume opens fresh tabs for remaining keywords; SW suspended='+suspended,async()=>{
 const local=storage(),session=storage(),messages=event(),removed=event(),updates=event();
 let clock=100000,created=10;const live=new Map(),gets=[],requests=[],waits=[];
 const chrome={storage:{local,session},runtime:{onMessage:messages},tabs:{onRemoved:removed,onUpdated:updates,
  create:async options=>{const tab={id:++created,url:options.url};live.set(tab.id,tab);requests.push({time:clock,url:options.url});return tab;},
  get:async id=>{gets.push(id);if(!live.has(id))throw Error('closed');return live.get(id);},
  update:async(id,options)=>{assert.ok(id>10,'closed tabs must not be reused');const tab=live.get(id);Object.assign(tab,options);requests.push({time:clock,url:options.url});return tab;}}};
 const c=vm.createContext({console,chrome,URL,Intl,Date:class extends Date {static now(){return clock;}},setTimeout,clearTimeout,setInterval,clearInterval,
 getStore:async()=>({}),apiFetch:async()=>({ok:true})});
 for(const file of ['shop-serp-tab','shop-serp-policy','shop-serp-pool'])vm.runInContext(fs.readFileSync('extension/'+file+'.js','utf8'),c);
 vm.runInContext(source.slice(source.indexOf('let rfCloseWork'),source.indexOf('\nconst DEFAULT_API_BASE')),c);
 // All four lanes have durable stale recovery/policy state, including lanes not loaded in memory.
 for(let i=0;i<4;i++){
  const tail=i?':lane:'+i:'';
  await session.set({['rfSerpTabId'+tail]:7+i,['rfSerpAnalysisId'+tail]:42,['rfSerpTabKeyword'+tail]:'old',['rfSerpRecovery'+tail]:{keyword:'old',since:0}});
  await local.set({['rfSerpPolicy'+tail]:{until:999999,last:99999,blockedOnResume:true},['rfSerpPolicyByIp'+tail]:{browser:{until:999999,last:99999,blockedOnResume:true}}});
 }
 // Actual rf-close listener records each human close, then pauses on the final close.
 for(let id=7;id<11;id++) removed.emit(id);
 await vm.runInContext('rfCloseWork',c);
 assert.equal(local.data['rfSerpClosed:42'].length,4);
 await local.set({'rfSerpReduced:42':2,rfShopSettings:{parallel_tabs:2,interval_ms:1000,tab_open_interval_ms:2500,collection_mode:'tab'}});
 c.wait=async ms=>{waits.push(ms);await new Promise(setImmediate);clock+=ms;};
 vm.runInContext(`const shopSerpPool=new ShopSerpPool({wait,tabFactory:slot=>new ShopSerpTab({slot,wait}),policyFactory:slot=>new ShopSerpPolicy({storage:shopSerpPool.laneStorage(slot),wait,cache:{get:async()=>null,put:async()=>{}},now:()=>Date.now()})});`,c);
 if(!suspended) vm.runInContext(`shopSerpPool.lanes.set(0,{tab:Object.assign(new ShopSerpTab(),{tabId:7}),policy:shopSerpPool.policyFactory(0)});shopSerpPool.started.add('42:0');shopSerpPool.gates.set('42',2);`,c);
 const resumeStart=source.indexOf('  async resumeShopSerp(');
 vm.runInContext('const handlers={'+source.slice(resumeStart,source.indexOf('  async __rfCollectionContext',resumeStart))+'};',c);
 assert.equal((await vm.runInContext('handlers.resumeShopSerp({analysisId:42})',c)).ok,true);
 for(let i=0;i<4;i++){
  const tail=i?':lane:'+i:'';
  assert.equal(session.data['rfSerpTabId'+tail],null);assert.equal(session.data['rfSerpRecovery'+tail],null);
  assert.equal(local.data['rfSerpPolicy'+tail].until,0);assert.equal(local.data['rfSerpPolicyByIp'+tail].browser.blockedOnResume,false);
 }
 assert.equal(local.data['rfSerpReduced:42'],null);
 // Two remaining keywords must request new documents, with configured lane opening spacing.
 const run=vm.runInContext(`Promise.all(['remaining-one','remaining-two'].map(kw=>shopSerpPool.run(42,kw,1,async({tab,policy})=>{tab.openIntervalMs=2500;return policy.run(kw,()=>tab.read(kw), 'browser',1000,null,()=>tab.peek(kw));},2500)))`,c);
 for(let i=0;i<200;i++) {await new Promise(setImmediate);for(const tab of live.values())messages.emit({type:'__rfSerpRead',result:{ok:true,products:[]}},{tab:{id:tab.id}});}
 assert.equal((await run).every(r=>r.ok),true);
 assert.deepEqual(requests.map(r=>decodeURIComponent(r.url).split('query=')[1]),['remaining-one','remaining-two']);
 assert.ok(requests[0].time>=100999);assert.ok(requests[1].time-requests[0].time>=1000);
 assert.equal(gets.some(id=>id<=10),false);
});
test('console bridge forwards analysis ID on resume',()=>{
 let listener,sent;
 const window={addEventListener:(_,f)=>listener=f,postMessage(){}};
 vm.runInNewContext(fs.readFileSync('extension/content/console-bridge.js','utf8'),{window,document:{documentElement:{setAttribute(){}}},chrome:{runtime:{sendMessage:m=>sent=m}}});
 listener({source:window,data:{source:'rankfree-console',type:'resumeShopSerp',analysisId:42}});
 assert.equal(sent.payload.analysisId,42);
 listener({source:window,data:{source:'rankfree-console',type:'setShopSettings',analysisId:42,manual:true,settings:{parallel_tabs:2}}});
 assert.equal(sent.payload.manual,true);assert.equal(sent.payload.analysisId,42);
 listener({source:window,data:{source:'rankfree-console',type:'fetchShopSerp',analysisId:42,parallel_revision:'restart-2'}});
 assert.equal(sent.payload.parallel_revision,'restart-2');
});
test('boosting restart resets once across simultaneous requests',async()=>{
 const local=storage({'rfSerpClosed:42':[0,1,2,3]});let resets=0;
 const c=vm.createContext({rfCloseWork:Promise.resolve(),shopSerpPool:{closedSlots:async()=>local.data['rfSerpClosed:42'],restart:async()=>{resets++;await local.set({'rfSerpClosed:42':[]});return {ok:true};}}});
 vm.runInContext(source.slice(source.indexOf('let rfRestartWork'),source.indexOf('\nconst DEFAULT_API_BASE')),c);
 await vm.runInContext('Promise.all([restartShopSerp(42,"browser",true),restartShopSerp(42,"browser",true)])',c);
 assert.equal(resets,1);
});

test('resume keeps live lane IDs, recovery and stagger timestamps, including unloaded lanes',async()=>{
 const local=storage({rfSerpLastLaneStarted:99999}),session=storage({rfSerpTabId:7,'rfSerpTabId:lane:3':10,rfSerpRecovery:{keyword:'A'},rfSerpLastOpened:99998});
 const c=vm.createContext({chrome:{storage:{session}},console});
 vm.runInContext(fs.readFileSync('extension/shop-serp-pool.js','utf8'),c);
 c.local=local;
 await vm.runInContext(`const p=new ShopSerpPool({storage:local,policyFactory:()=>({resume:async()=>{}})});p.lanes.set(0,{tab:{tabId:7},policy:{resume:async()=>{}}});p.restart(85)`,c);
 assert.equal(session.data.rfSerpTabId,7);assert.equal(session.data['rfSerpTabId:lane:3'],10);
 assert.equal(session.data.rfSerpRecovery.keyword,'A');assert.equal(session.data.rfSerpLastOpened,99998);
 assert.equal(local.data.rfSerpLastLaneStarted,99999);assert.equal(vm.runInContext('p.lanes.get(0).tab.tabId',c),7);
});
