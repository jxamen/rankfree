const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('extension/background.js','utf8');
const code=source.slice(source.indexOf('async function reportShopClientEvent'),source.indexOf('\nfunction sleep',source.indexOf('async function reportShopClientEvent')));
function context(extra={}) {return vm.createContext({crypto:require('node:crypto').webcrypto,Date:{now:()=>1000000},productInfoWaiters:new Map(),skwQueueActive:false,skwCheckActive:false,skwCheckHeartbeat:0,lastScreenSerpAt:999999,getStore:async()=>({token:'test'}),chrome:{storage:{local:{get:async()=>({})}},runtime:{reload:()=>{}}},...extra});}
test('one minute alarm recovers screen stall despite recent screen request, then saves',async()=>{
 let queues=0,saves=0;const events=[];
 const c=context({handlers:{fetchShopSerp:async()=>({ok:true,html:'data'})},apiFetch:async(path,args)=>{
  if(path.includes('check-queue')){queues++;if(queues===1)assert.match(path,/watchdog=1/);return {ok:true,json:{data:{analysis_id:79,items:queues===1?[{item_id:1,keyword:'할로윈포장'}]:[]}}};}
  if(path.endsWith('collector-event'))events.push(args.body);else saves++;
  return {ok:true,json:{}};
 }});
 await vm.runInContext(code+';watchShopKeywordQueue()',c);
 assert.equal(saves,1);assert.equal(c.skwCheckActive,false);assert.equal(events[0].kind,'watchdog_restart');
 assert.match(source,/rf-skw-watchdog', \{ periodInMinutes: 1/);
});
test('hung worker promise reloads once; live captcha heartbeat is left alone',async()=>{
 for(const heartbeat of [800000,999000]){
  let reloads=0;const c=context({skwCheckActive:true,skwCheckHeartbeat:heartbeat,apiFetch:async()=>({ok:true}),chrome:{runtime:{reload:()=>reloads++}}});
  await vm.runInContext(code+';watchShopKeywordQueue()',c);
  assert.equal(reloads,heartbeat===800000?1:0);
 }
});
test('queue exception is reported with keyword and stage and clears active flag',async()=>{
 const reports=[];const c=context({lastScreenSerpAt:0,rfSerpCurrent:{analysisId:79,keyword:'할로윈포장'},apiFetch:async(path,args)=>{
  if(path.endsWith('collector-event')){reports.push(args.body);return {ok:true};}
  throw Error('reproduced queue error');
 }});
 await vm.runInContext(code+';drainShopKeywordCheckQueue()',c);
 assert.equal(c.skwCheckActive,false);assert.equal(reports[0].stage,'queue');assert.match(reports[0].message,/reproduced/);
});
test('boosting copy waits beyond 15 minutes, finishes and processes next order; actual error rejects',async()=>{
 const php=fs.readFileSync(process.env.BS_BATCH_COPY || 'tests/fixtures/bs-batch.skin.php','utf8');
 const code=php.slice(php.indexOf('    async function process(item)'),php.indexOf('    async function run(fresh)'));
 let clock=0,polls=0;const links=[],notes=[];
 const c=vm.createContext({Date:{now:()=>clock},note:(i,m)=>notes.push(m),sleep:async()=>{clock+=60000;},call:async(action,item)=>{
  if(action==='rankfree_short_links'){links.push(item.no);return {};}
  return {analysis_id:item.no,analysis_status:item.no===3?'error':polls++>20?'done':'checking',keyword_target:1,short_link_cnt:links.includes(item.no)?1:0,exposed_cnt:1,checked:0};
 }});
 await vm.runInContext(code+';process({no:1}).then(()=>process({no:2}))',c);
 assert.ok(clock>900000);assert.deepEqual(links,[1,2]);assert.ok(notes.some(x=>x.includes('자동 복구')));
 await assert.rejects(vm.runInContext('process({no:3})',c),/분석 상태: error/);
});

test('watchdog defers runtime reload while product tab or queue save is active',async()=>{
 for(const extra of [{productInfoWaiters:new Map([['111',()=>{}]])},{skwQueueActive:true}]) {
  let reloads=0;
  const c=context({skwCheckActive:true,skwCheckHeartbeat:0,...extra,chrome:{runtime:{reload:()=>reloads++}}});
  await vm.runInContext(code+';watchShopKeywordQueue()',c);
  assert.equal(reloads,0);
 }
});
