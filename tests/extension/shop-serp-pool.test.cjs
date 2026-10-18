const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
require('../../extension/shop-serp-pool.js');
require('../../extension/shop-serp-tab.js');
const pause=ms=>new Promise(r=>setTimeout(r,ms));
function storage(){const data={};return {data,get:async keys=>Object.fromEntries((Array.isArray(keys)?keys:[keys]).map(k=>[k,data[k]])),set:async values=>Object.assign(data,values)};}
function pool(store){return new ShopSerpPool({storage:store,tabFactory:slot=>({slot}),policyFactory:slot=>({slot})});}
test('four distinct lanes actually overlap, a gate reduces later work to one, next analysis restores four',async()=>{
 const store=storage(), p=pool(store);let active=0,max=0;const slots=new Set();
 const execute=async ({tab})=>{slots.add(tab.slot);max=Math.max(max,++active);await pause(10);active--;return {ok:true};};
 await Promise.all(Array.from({length:8},(_,i)=>p.run(10,'kw'+i,4,execute)));
 assert.equal(max,4);assert.equal(slots.size,4);
 const blocked=await p.run(10,'gate',4,async()=>({ok:false,captcha:true}));
 assert.equal(blocked.parallel_tabs,1);
 active=0;max=0;
 await Promise.all(Array.from({length:4},(_,i)=>p.run(10,'after'+i,4,execute)));
 assert.equal(max,1);
 const restarted=pool(store);max=0;
 await Promise.all(Array.from({length:4},(_,i)=>restarted.run(10,'restart'+i,4,execute)));
 assert.equal(max,1);
 max=0;
 await Promise.all(Array.from({length:4},(_,i)=>restarted.run(11,'next'+i,4,execute)));
 assert.equal(max,4);
});
test('each lane stores pacing independently and all new tabs obey the shared opening gap',async()=>{
 const store=storage(),p=pool(store);
 await p.laneStorage(0).set({rfSerpPolicy:{last:1}});
 await p.laneStorage(1).set({rfSerpPolicy:{last:2}});
 assert.equal((await p.laneStorage(0).get('rfSerpPolicy')).rfSerpPolicy.last,1);
 assert.equal((await p.laneStorage(1).get('rfSerpPolicy')).rfSerpPolicy.last,2);
 let now=10000;const opened=[], waits=[];
 const c={storage:{session:store},tabs:{create:async()=>{opened.push(now);return {id:opened.length};}}};
 const clock=Date.now;Date.now=()=>now;
 try {
  ShopSerpTab.openLane=Promise.resolve();
  const tabs=Array.from({length:4},(_,slot)=>new ShopSerpTab({slot,browser:c,wait:async ms=>{waits.push(ms);now+=ms;}}));
  for(const tab of tabs) tab.openIntervalMs=3000;
  await Promise.all(tabs.map(tab=>tab.create({})));
  await tabs[0].create({}); // recreation also respects the gap
  assert.deepEqual(opened,[10000,13000,16000,19000,22000]);
  assert.deepEqual(waits,[3000,3000,3000,3000]);
 } finally {Date.now=clock;}
});
test('background batch starts N keywords together and saves only each assigned id and claim',async()=>{
 const source=fs.readFileSync('extension/background.js','utf8');
 const code=source.slice(source.indexOf('async function drainShopKeywordCheckQueue('),source.indexOf('\nfunction sleep',source.indexOf('async function drainShopKeywordCheckQueue(')));
 let queue=0,active=0,max=0;const saved=[];
 const ctx=vm.createContext({crypto:require('node:crypto').webcrypto,Date:{now:()=>1000000},skwCheckActive:false,lastScreenSerpAt:0,
  reportShopClientEvent:async()=>{}, getStore:async()=>({token:'x'}),chrome:{storage:{local:{get:async()=>({})}}},
  handlers:{fetchShopSerp:async({keyword})=>{max=Math.max(max,++active);await pause(10);active--;return {ok:true,html:keyword};}},
  apiFetch:async(path,args)=>path.includes('check-queue')?{ok:true,json:{data:{analysis_id:42,parallel_tabs:4,items:++queue===1?Array.from({length:4},(_,i)=>({item_id:i+1,keyword:'keyword'+i,claim_token:'token'+i})):[]}}}:(saved.push(args.body),{ok:true,json:{}}),
 });
 await vm.runInContext(code+';drainShopKeywordCheckQueue()',ctx);
 assert.equal(max,4);assert.equal(saved.length,4);
 for(const row of saved){assert.equal(row.html,'keyword'+(row.item_id-1));assert.equal(row.claim_token,'token'+(row.item_id-1));}
});
test('a gate prevents queued tab creation and requeues that keyword on the surviving lane',async()=>{
 const store=storage(),p=pool(store);let unblock;
 const barrier=new Promise(r=>unblock=r);const calls=[];
 const first=p.run(88,'blocked',2,async({tab})=>{calls.push(['blocked',tab.slot]);await barrier;return {ok:false,status:429};});
 let extraAttempts=0;
 const extra=p.run(88,'queued',2,async({tab})=>{
  await pause(10);
  if (!tab.allowOpen()) {extraAttempts++;return {ok:false,deferred:true};}
  calls.push(['queued',tab.slot]);return {ok:true};
 });
 await pause(1);unblock();
 await Promise.all([first,extra]);
 assert.equal(extraAttempts,1);
 assert.deepEqual(calls,[['blocked',0],['queued',0]]);
});
test('page and bridge correlate out-of-order concurrent replies to the original keyword',async()=>{
 const blade=fs.readFileSync('resources/views/admin/shop-keyword/show.blade.php','utf8');
 const code=blade.slice(blade.indexOf('    function extCall('),blade.indexOf('    function halt(',blade.indexOf('    function extCall(')));
 const callbacks=[],listeners=new Set();
 const window={addEventListener:(type,fn)=>listeners.add(fn),removeEventListener:(type,fn)=>listeners.delete(fn),postMessage:data=>queueMicrotask(()=>[...listeners].forEach(fn=>fn({source:window,data})))};
 const ctx=vm.createContext({window,crypto:require('node:crypto').webcrypto,setTimeout,clearTimeout,document:{documentElement:{setAttribute(){}}},chrome:{runtime:{sendMessage:(message,reply)=>callbacks.push({message,reply})}}});
 vm.runInContext(fs.readFileSync('extension/content/console-bridge.js','utf8'),ctx);
 vm.runInContext(code,ctx);
 const promises=Array.from({length:4},(_,i)=>vm.runInContext(`extCall('fetchShopSerp',{keyword:'keyword${i}',parallel_tabs:4,tab_open_interval_ms:500},1000)`,ctx));
 await pause(0);
 assert.equal(callbacks.length,4);
 for(const {message,reply} of callbacks.reverse()) {assert.equal(message.payload.parallel_tabs,4);assert.equal(message.payload.tab_open_interval_ms,500);reply({ok:true,html:message.payload.keyword});}
 const result=await Promise.all(promises);
 assert.deepEqual(result.map(r=>r.html),['keyword0','keyword1','keyword2','keyword3']);
});
test('every concurrent lane independently observes the configured keyword interval',async()=>{
 require('../../extension/shop-serp-policy.js');
 const store=storage(),clock=[10000,10000],waits=[[],[]];let p;
 p=new ShopSerpPool({storage:store,tabFactory:slot=>({slot}),policyFactory:slot=>new ShopSerpPolicy({storage:p.laneStorage(slot),now:()=>clock[slot],wait:async ms=>{waits[slot].push(ms);clock[slot]+=ms;},cache:{get:async()=>null,put:async()=>{}}})});
 const run=batch=>Promise.all([0,1].map(i=>p.run(99,batch+':'+i,2,({tab,policy})=>policy.run(batch+':'+i,async()=>({ok:true,status:200,result_source:'shopping'}),'ip',1000,'saved-revision'))));
 await run('first');await run('second');
 assert.deepEqual(waits,[[1000],[1000]]);
});
test('parallel workers stagger initial starts in tabless mode too',async()=>{
 const p=pool(storage()), starts=[];
 await Promise.all(Array.from({length:4},(_,i)=>p.run(501,'fetch'+i,4,async()=>{starts.push(Date.now());return {ok:true};},15)));
 assert.equal(starts.length,4);
 for(let i=1;i<starts.length;i++)assert.ok(starts[i]-starts[i-1]>=13,'initial workers must be spaced');
});
test('manual width overrides a block, grows 1 to 2 and shrinks queued work without stopping',async()=>{
 const store=storage(),p=pool(store);
 await p.run(42,'blocked',2,async()=>({captcha:true}));
 await store.set({rfShopSettings:{parallel_tabs:2,tab_open_interval_ms:0}});
 await p.clearReduction(42);
 let active=0,max=0;
 const execute=async()=>{max=Math.max(max,++active);await pause(10);active--;return {ok:true};};
 await Promise.all([p.run(42,'a',1,execute),p.run(42,'b',1,execute)]);
 assert.equal(max,2);
 await store.set({rfShopSettings:{parallel_tabs:1,tab_open_interval_ms:0}});max=0;
 await Promise.all([p.run(42,'c',2,execute),p.run(42,'d',2,execute)]);
 assert.equal(max,1);
 await p.run(42,'blocked-again',2,async()=>({status:429}));
 assert.equal(p.gates.has('42'),true);
});
test('already waiting keyword reads new width while the first keyword is still running',async()=>{
 const store=storage(),p=pool(store);
 await store.set({rfShopSettings:{parallel_tabs:1,tab_open_interval_ms:0}});
 let release,secondStarted=false;
 const barrier=new Promise(r=>release=r);
 const first=p.run(73,'first',1,async()=>{await barrier;return {ok:true};});
 while(!p.active.size) await pause(1);
 const second=p.run(73,'second',1,async()=>{secondStarted=true;return {ok:true};});
 await pause(15);assert.equal(secondStarted,false);
 await store.set({rfShopSettings:{parallel_tabs:2,tab_open_interval_ms:0}});
 try {await second;assert.equal(secondStarted,true);assert.equal(p.active.size,1);}finally{release();}
 await first;
});
