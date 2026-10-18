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
 let calls=0, state;
 const context=vm.createContext({
  skwCheckActive:false,lastScreenSerpAt:0,Date:{now:()=>1000000},
  getStore:async()=>({token:'test'}),
  chrome:{storage:{local:{get:async()=>({}),set:async v=>{state=v;}}},action:{setBadgeText:()=>{}}},
  handlers:{fetchShopSerp:async()=>{calls++;return {ok:false,status:403};}},
  apiFetch:async()=>({ok:true,json:{data:{items:[{keyword:'a'},{keyword:'b'}]}}}),
 });
 await vm.runInContext(loop+';drainShopKeywordCheckQueue()',context);
 assert.equal(calls,1);assert.equal(state.rfSkwCheckSkipUntil,2800000);
});
