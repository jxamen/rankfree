const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../../extension/background.js'), 'utf8');
const loop = source.slice(source.indexOf('async function drainShopKeywordCheckQueue()'), source.indexOf('\nfunction sleep', source.indexOf('async function drainShopKeywordCheckQueue()')));
test('background drains beyond alarm budget with no success sleeps, and proceeds after a reached target', async () => {
 let clock = 1000000, batches = 0, checks = 0, saves = 0;
 const context = vm.createContext({
  skwCheckActive:false,lastScreenSerpAt:0,Date:{now:()=>clock},
  getStore:async()=>({token:'test',apiBase:'test'}),
  chrome:{storage:{local:{get:async()=>({})}}},
  handlers:{fetchShopSerp:async()=>{clock+=1600;checks++;return {ok:true,html:'raw'};}},
  apiFetch:async(path)=>{
   if(path.includes('check-queue')) {batches++;return {ok:true,json:{data:{analysis_id:batches,items:batches<=6?Array.from({length:40},(_,i)=>({item_id:i,keyword:String(i)})):[]}}};}
   saves++; return {status:200,json:{status:saves===1?'target_reached':'checking'}};
  },
 });
 await vm.runInContext(loop+';drainShopKeywordCheckQueue()',context);
 assert.equal(batches,7);assert.equal(checks,201);assert.equal(context.skwCheckActive,false);
});
test('background inspects the same keyword every 1.5 seconds until human recovery, then saves', async () => {
 let calls=0, saves=0, batches=0; const waits=[];
 const context=vm.createContext({
  skwCheckActive:false,lastScreenSerpAt:0,Date:{now:()=>1000000},
  getStore:async()=>({token:'test'}),chrome:{storage:{local:{get:async()=>({})}}},
  sleep:async ms=>waits.push(ms),
  handlers:{fetchShopSerp:async args=>{assert.equal(args.keyword,'same');calls++;return calls===1?{ok:false,status:403}:calls<4?{ok:false,cooldown:true,retryAt:1180000}:{ok:true,html:'solved'};}},
  apiFetch:async path=>{if(path.includes('check-queue'))return {ok:true,json:{data:{analysis_id:1,items:++batches===1?[{keyword:'same',item_id:2}]:[]}}};saves++;return {ok:true,json:{}};},
 });
 await vm.runInContext(loop+';drainShopKeywordCheckQueue()',context);
 assert.equal(calls,4);assert.equal(saves,1);assert.deepEqual(waits,[1500,1500,1500]);
});

test('admin extension loop polls the same item until success without saving blocks', async()=>{
 const blade=fs.readFileSync(require('node:path').join(__dirname,'../../resources/views/admin/shop-keyword/show.blade.php'),'utf8');
 const start=blade.indexOf('    async function runExtLoop()');
 const code=blade.slice(start,blade.indexOf('    // ── 폴백:',start)).replace(/\{\{.*?\}\}/g,'66');
 let calls=0,saves=0;const waits=[],events=[];
 const context=vm.createContext({currentIntervalMs:3000,intervalLabel:()=> ' interval',stopped:false,errCount:0,cfg:{urls:{pending:'/pending',checkHtml:'/save',collectorEvent:'/events'}},RUNNER:'test',label:{},Date:{now:()=>1000000},
  fetch:async()=>({ok:true,status:200,json:async()=>({data:{items:[{id:1,keyword:'a'}]}})}),
  extCall:async()=>++calls<=4?{ok:false,status:403}:{ok:true,html:'raw'},
  waitRestriction:async until=>{waits.push(until-1000000);},document:{getElementById:()=>null},sleep:async ms=>{waits.push(ms);},renderProgress:()=>{},halt:()=>{throw Error('unexpected halt');},
  post:async(url,body)=>{if(url==='/events'){events.push(body.kind);return {ok:true};}saves++;return {ok:true,status:200,json:async()=>({status:'target_reached'})};},
 });
 await vm.runInContext(code+';runExtLoop()',context);
 assert.equal(calls,5);assert.equal(saves,1);assert.deepEqual(events,['403','403','403','403','success']);assert.deepEqual(waits,[1500,1500,1500,1500]);
});

test('admin clears the old failure notice on the next successful progress response', () => {
 const blade=fs.readFileSync(require('node:path').join(__dirname,'../../resources/views/admin/shop-keyword/show.blade.php'),'utf8');
 const start=blade.indexOf('    function renderProgress(d)');
 const code=blade.slice(start,blade.indexOf('    // ── 상태 필터',start));
 let removed=0;
 const context=vm.createContext({document:{getElementById:()=>({remove:()=>removed++})}});
 vm.runInContext(code+`;renderProgress({status:'blocked'});renderProgress({status:'error'});`,context);
 assert.equal(removed,0);
 vm.runInContext(`renderProgress({status:'checking'});`,context);
 assert.equal(removed,1);
});

test('legacy queue wait migrates before early return; permission pause is preserved',async()=>{
 for(const permission of [false,true]) {
  let written,alarm;
  const context=vm.createContext({skwCheckActive:false,lastScreenSerpAt:0,Date:{now:()=>1000000},
   getStore:async()=>({token:'test'}),
   chrome:{storage:{local:{get:async()=>({rfSkwCheckSkipUntil:2800000,rfSerpPolicy:{until:2799900,http403:8},rfSkwCheckSkipReason:permission?'permission':undefined}),set:async v=>{written=v;}}},alarms:{create:(n,o)=>{alarm=o;}}},
  });
  await vm.runInContext(loop+';drainShopKeywordCheckQueue()',context);
  if(permission) assert.equal(written,undefined);
  else {assert.equal(written.rfSkwCheckSkipUntil,1180000);assert.equal(written.rfSerpPolicy.http403,8);assert.equal(alarm.when,1180000);}
 }
});
