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
test('background stops on actual block and preserves cooldown', async () => {
 let calls=0, state, alarm;
 const context=vm.createContext({
  skwCheckActive:false,lastScreenSerpAt:0,Date:{now:()=>1000000},
  getStore:async()=>({token:'test'}),
  chrome:{storage:{local:{get:async()=>({}),set:async v=>{state=v;}}},alarms:{create:(name,options)=>{alarm={name,options};}},action:{setBadgeText:()=>{}}},
  handlers:{fetchShopSerp:async()=>{calls++;return {ok:false,status:403};}},
  apiFetch:async()=>({ok:true,json:{data:{items:[{keyword:'a'},{keyword:'b'}]}}}),
 });
 await vm.runInContext(loop+';drainShopKeywordCheckQueue()',context);
 assert.equal(calls,1);assert.equal(state.rfSkwCheckSkipUntil,1180000);
 assert.equal(alarm.name,"rf-skw-check-retry"); assert.equal(alarm.options.when,1180000);
});

test('background cooldown uses remaining policy time instead of adding another three minutes', async()=>{
 let state, alarm;
 const context=vm.createContext({
  skwCheckActive:false,lastScreenSerpAt:0,Date:{now:()=>1000000},
  getStore:async()=>({token:'test'}),
  chrome:{storage:{local:{get:async()=>({}),set:async v=>{state=v;}}},alarms:{create:(n,o)=>{alarm=o;}},action:{setBadgeText:()=>{}}},
  handlers:{fetchShopSerp:async()=>({ok:false,cooldown:true,retryAt:1010000})},
  apiFetch:async()=>({ok:true,json:{data:{items:[{keyword:'a'}]}}}),
 });
 await vm.runInContext(loop+';drainShopKeywordCheckQueue()',context);
 assert.equal(state.rfSkwCheckSkipUntil,1010000); assert.equal(alarm.when,1010000);
});

test('admin extension loop repeats three-minute waits until success without saving blocks', async()=>{
 const blade=fs.readFileSync(require('node:path').join(__dirname,'../../resources/views/admin/shop-keyword/show.blade.php'),'utf8');
 const start=blade.indexOf('    async function runExtLoop()');
 const code=blade.slice(start,blade.indexOf('    // ── 폴백:',start)).replace(/\{\{.*?\}\}/g,'66');
 let calls=0,saves=0;const waits=[];
 const context=vm.createContext({stopped:false,errCount:0,cfg:{urls:{pending:'/pending',checkHtml:'/save'}},RUNNER:'test',label:{},Date:{now:()=>1000000},
  fetch:async()=>({ok:true,status:200,json:async()=>({data:{items:[{id:1,keyword:'a'}]}})}),
  extCall:async()=>++calls<=4?{ok:false,status:403}:{ok:true,html:'raw'},
  sleep:async ms=>{waits.push(ms);},renderProgress:()=>{},halt:()=>{throw Error('unexpected halt');},
  post:async()=>{saves++;return {ok:true,status:200,json:async()=>({status:'target_reached'})};},
 });
 await vm.runInContext(code+';runExtLoop()',context);
 assert.equal(calls,5);assert.equal(saves,1);assert.deepEqual(waits,[180000,180000,180000,180000]);
});
