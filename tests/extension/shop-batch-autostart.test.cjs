const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('extension/background.js','utf8');
const poll=source.slice(source.indexOf('async function pollShopKeywordQueues()'),source.indexOf('// ── 알람·부트 훅'));
const loop=source.slice(source.indexOf('async function drainShopKeywordCheckQueue('),source.indexOf('\nfunction sleep',source.indexOf('async function drainShopKeywordCheckQueue(')));
const hooks=source.slice(source.indexOf('// ── 알람·부트 훅'),source.indexOf('\n/*\n * 서비스워커가 뜨자마자'));
const flush=async()=>{for(let i=0;i<50;i++) await Promise.resolve();};

test('boot reads an empty queue; next API analysis is searched within five seconds without a page',async()=>{
 let now=1000000,available=false,queries=0,saves=0; const timers=[];
 const c=vm.createContext({crypto:require('node:crypto').webcrypto,Date:{now:()=>now},skwCheckActive:false,lastScreenSerpAt:0,lastScreenAnalysisId:null,
  getStore:async()=>({token:'test'}),reportShopClientEvent:async()=>{},drainShopKeywordProductQueue:async()=>{},
  handlers:{fetchShopSerp:async()=>({ok:true,html:'result'})},
  apiFetch:async path=>{if(path.includes('check-queue')){queries++;return {ok:true,json:{data:{analysis_id:87,items:available?[{item_id:1,keyword:'next'}]:[]}}};}available=false;saves++;return {ok:true,json:{status:'target_reached'}};},
  setInterval:(fn,ms)=>{timers.push({fn,ms});return timers.length;},clearInterval:()=>{},
  BULK_WATCHDOG_ALARM:'bulk',SKW_QUEUE_ALARM:'queue',SHOP_RANK_ALARM:'rank',
  chrome:{storage:{local:{get:async()=>({})}},alarms:{onAlarm:{addListener:()=>{}},get:async()=>true,create:()=>{}}}
 });
 vm.runInContext(loop+poll+hooks,c);await flush();assert.equal(queries,1);assert.equal(saves,0);
 // Boosting creates the next analysis only after the previous collector exited.
 available=true;now+=5000;await timers.find(t=>t.ms===5000).fn();await flush();
 assert.equal(saves,1);assert.equal(c.skwCheckActive,false);
});

test('only a terminal previous screen releases the sixty-second yield; failed reads retain it',async()=>{
 for(const status of ['checking','target_reached','done','paused','ad_ended',null]) {
  const c=vm.createContext({Date:{now:()=>1000000},lastScreenSerpAt:999999,lastScreenAnalysisId:86,getStore:async()=>({token:'test'}),
   apiFetch:async()=>({ok:status!==null,json:{data:{status}}}),drainShopKeywordProductQueue:async()=>{},drainShopKeywordCheckQueue:async()=>{}});
  await vm.runInContext(poll+';pollShopKeywordQueues()',c);
  assert.equal(c.lastScreenSerpAt,['checking',null].includes(status)?999999:0);
 }
});

test('a new screen request during the state read retains priority',async()=>{
 const c=vm.createContext({Date:{now:()=>1000000},lastScreenSerpAt:999998,lastScreenAnalysisId:86,getStore:async()=>({token:'test'}),
  apiFetch:async()=>{c.lastScreenSerpAt=999999;c.lastScreenAnalysisId=87;return {ok:true,json:{data:{status:'target_reached'}}};},
  drainShopKeywordProductQueue:async()=>{},drainShopKeywordCheckQueue:async()=>{}});
 await vm.runInContext(poll+';pollShopKeywordQueues()',c);assert.equal(c.lastScreenSerpAt,999999);
});

test('a stalled product tab does not delay ready keyword searches',async()=>{
 let checks=0;
 const c=vm.createContext({lastScreenSerpAt:0,lastScreenAnalysisId:null,drainShopKeywordProductQueue:()=>new Promise(()=>{}),drainShopKeywordCheckQueue:async()=>checks++});
 vm.runInContext(poll+';pollShopKeywordQueues()',c);await flush();assert.equal(checks,1);
});
