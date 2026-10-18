const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../../extension/background.js'), 'utf8');
const loop = source.slice(source.indexOf('async function drainShopKeywordCheckQueue()'), source.indexOf('\nfunction sleep', source.indexOf('async function drainShopKeywordCheckQueue()')));
test('background drains beyond alarm budget with no success sleeps, and proceeds after a reached target', async () => {
 let clock = 1000000, batches = 0, checks = 0, saves = 0;
 const context = vm.createContext({
  crypto:require('node:crypto').webcrypto,skwCheckActive:false,lastScreenSerpAt:0,Date:{now:()=>clock},
  getStore:async()=>({token:'test',apiBase:'test'}),
  chrome:{storage:{local:{get:async()=>({})}}},
  handlers:{fetchShopSerp:async()=>{clock+=1600;checks++;return {ok:true,html:'raw',result_source:checks%2?'integrated':'shopping'};}},
  apiFetch:async(path,options)=>{
   if(path.includes('check-queue')) {batches++;return {ok:true,json:{data:{analysis_id:batches,items:batches<=6?Array.from({length:40},(_,i)=>({item_id:i,keyword:String(i)})):[]}}};}
   assert.equal(options.body.result_source,checks%2?'integrated':'shopping');
   saves++; return {status:200,json:{status:saves===1?'target_reached':'checking'}};
  },
 });
 await vm.runInContext(loop+';drainShopKeywordCheckQueue()',context);
 assert.equal(batches,7);assert.equal(checks,201);assert.equal(context.skwCheckActive,false);
});
test('background inspects the same keyword every 1.5 seconds until human recovery, then saves', async () => {
 let calls=0, saves=0, batches=0; const waits=[];
 const context=vm.createContext({
  crypto:require('node:crypto').webcrypto,skwCheckActive:false,lastScreenSerpAt:0,Date:{now:()=>1000000},
  getStore:async()=>({token:'test'}),chrome:{storage:{local:{get:async()=>({})}}},
  sleep:async ms=>waits.push(ms),
  handlers:{fetchShopSerp:async args=>{assert.equal(args.keyword,'same');calls++;return calls===1?{ok:false,status:403}:calls<4?{ok:false,cooldown:true,retryAt:1180000}:{ok:true,html:'solved'};}},
  apiFetch:async path=>{if(path.includes('check-queue'))return {ok:true,json:{data:{analysis_id:1,items:++batches===1?[{keyword:'same',item_id:2}]:[]}}};saves++;return {ok:true,json:{}};},
 });
 await vm.runInContext(loop+';drainShopKeywordCheckQueue()',context);
 assert.equal(calls,4);assert.equal(saves,1);assert.deepEqual(waits,[1500,1500,1500]);
});

test('admin extension loop keeps browser work until human recovery even with stale fallback replies', async()=>{
 const blade=fs.readFileSync(require('node:path').join(__dirname,'../../resources/views/admin/shop-keyword/show.blade.php'),'utf8');
 const start=blade.indexOf('    const failedItems = new Map();');
 const code=blade.slice(start,blade.indexOf('    // ── 폴백:',start)).replace(/\{\{.*?\}\}/g,'66');
 let calls=0,saves=0,serverCalls=0;const waits=[],events=[];
 const context=vm.createContext({URL,location:{href:'http://localhost/admin'},loopActive:false,serverFallback:false,poll:()=>{serverCalls++;},currentIntervalMs:3000,intervalLabel:()=> ' interval',stopped:false,errCount:0,cfg:{urls:{pending:'/pending',checkHtml:'/save',collectorEvent:'/events'}},RUNNER:'test',label:{},Date:{now:()=>1000000},
  fetch:async()=>({ok:true,status:200,json:async()=>({data:{items:[{id:1,keyword:'a'}]}})}),
  extCall:async()=>++calls<=4?{ok:false,status:403}:{ok:true,cached:true,html:'raw',result_source:'shopping'},
  waitRestriction:async until=>{waits.push(until-1000000);},document:{getElementById:()=>null},sleep:async ms=>{waits.push(ms);},renderProgress:()=>{},halt:()=>{throw Error('unexpected halt');},
  post:async(url,body)=>{if(url==='/events'){events.push(body.kind);return {ok:true,json:async()=>({server_fallback:true})};}assert.equal(body.result_source,'shopping');saves++;return {ok:true,status:200,json:async()=>({status:'target_reached'})};},
 });
 await vm.runInContext(code+';runExtLoop()',context);
 assert.equal(calls,5);assert.equal(saves,1);assert.equal(serverCalls,0);assert.equal(context.serverFallback,false);assert.deepEqual(events,['403','403','403','403','success']);assert.deepEqual(waits,[1500,1500,1500,1500]);
});

test('admin clears the old failure notice on the next successful progress response', () => {
 const blade=fs.readFileSync(require('node:path').join(__dirname,'../../resources/views/admin/shop-keyword/show.blade.php'),'utf8');
 const start=blade.indexOf('    function renderProgress(d)');
 const code=blade.slice(start,blade.indexOf('    // ── 상태 필터',start));
 let removed=0;
 const context=vm.createContext({stopped:false,label:{textContent:'보안문자 대기'},document:{getElementById:id=>id==='sk-collection-error'?{remove:()=>removed++}:{classList:{add:()=>{}}}}});
 vm.runInContext(code+`;renderProgress({status:'blocked'});renderProgress({status:'error'});`,context);
 assert.equal(removed,0);
 vm.runInContext(`renderProgress({status:'checking'});`,context);
 assert.equal(removed,1);
 assert.match(context.label.textContent,/진행 중/);
});

test('legacy queue wait migrates before early return; permission pause is preserved',async()=>{
 for(const permission of [false,true]) {
  let written,alarm;
  const context=vm.createContext({crypto:require('node:crypto').webcrypto,skwCheckActive:false,lastScreenSerpAt:0,Date:{now:()=>1000000},
   getStore:async()=>({token:'test'}),
   chrome:{storage:{local:{get:async()=>({rfSkwCheckSkipUntil:2800000,rfSerpPolicy:{until:2799900,http403:8},rfSkwCheckSkipReason:permission?'permission':undefined}),set:async v=>{written=v;}}},alarms:{create:(n,o)=>{alarm=o;}}},
  });
  await vm.runInContext(loop+';drainShopKeywordCheckQueue()',context);
  if(permission) assert.equal(written,undefined);
  else {assert.equal(written.rfSkwCheckSkipUntil,1180000);assert.equal(written.rfSerpPolicy.http403,8);assert.equal(alarm.when,1180000);}
 }
});

test('one lower toggle pauses and resumes; upper and captcha buttons removed', async () => {
 const blade=fs.readFileSync(require('node:path').join(__dirname,'../../resources/views/admin/shop-keyword/show.blade.php'),'utf8');
 for (const id of ['sk-continue-now','id="sk-resume"','id="sk-captcha"']) assert.equal(blade.includes(id),false);
 assert.equal((blade.match(/id="sk-stop"/g)||[]).length,1);
 const start=blade.indexOf('    let loopActive = false;');
 const code=blade.slice(start,blade.indexOf('    // 상품정보 다시 수집',start));
 let click, runs=0;const actions=[];
 const button={addEventListener:(type,fn)=>click=fn};
 const context=vm.createContext({stopBtn:button,stopped:false,label:{},errCount:3,failedItems:new Map([[1,'failure']]),cfg:{urls:{pause:'/pause'},method:'search'},extMode:true,sleep:async()=>{},
 post:async(url,body)=>{actions.push(body.paused);return {ok:true};},runExtLoop:()=>runs++});
 vm.runInContext(code,context);
 await click(); assert.equal(context.stopped,true); assert.equal(button.textContent,'이어서');
 await click(); assert.equal(context.stopped,false); assert.equal(button.textContent,'중단');assert.equal(runs,1); assert.deepEqual(actions,[true,false]);
});

test('ordinary read failure immediately advances, retaining failed rank as unknown',async()=>{
 const blade=fs.readFileSync('resources/views/admin/shop-keyword/show.blade.php','utf8');
 const start=blade.indexOf('    const failedItems = new Map();');
 const code=blade.slice(start,blade.indexOf('    // ── 폴백:',start)).replace(/\{\{.*?\}\}/g,'66');
 const calls=[],saves=[];let halted='';
 const context=vm.createContext({URL,location:{href:'http://localhost',reload:()=>{throw Error('unexpected reload');}},loopActive:false,stopped:false,errCount:0,currentIntervalMs:4000,intervalLabel:()=>'',cfg:{urls:{pending:'/pending',checkHtml:'/save',collectorEvent:'/event'}},RUNNER:'test',label:{},document:{getElementById:()=>null},renderProgress:()=>{},sleep:async()=>{},
 fetch:async url=>{const excluded=url.searchParams.getAll('exclude[]');return {ok:true,json:async()=>({data:{items:excluded.includes('1')?(saves.length?[]:[{id:2,keyword:'next'}]):[{id:1,keyword:'bad'}]}})};},
 extCall:async(type,args)=>{calls.push(args.keyword);return args.keyword==='bad'?{ok:false,message:'상품 목록 읽기 15초 초과'}:{ok:true,html:'valid'};},
 post:async(url,body)=>{if(url==='/save')saves.push(body.item_id);return {ok:true,json:async()=>({status:'checking'})};},
 halt:msg=>{halted=msg;context.stopped=true;}});
 await vm.runInContext(code+';runExtLoop()',context);
 assert.deepEqual(calls,['bad','next']);assert.deepEqual(saves,[2]);assert.match(halted,/읽기 실패 1개/);
});


test('taken over admin runner halts immediately without a queue wait', async () => {
 const blade=fs.readFileSync('resources/views/admin/shop-keyword/show.blade.php','utf8');
 const start=blade.indexOf('    async function waitBusy(');
 const code=blade.slice(start,blade.indexOf('    const sleep',start));
 let halted, waits=0;
 const context=vm.createContext({label:{},halt:msg=>{halted=msg;},sleep:async()=>waits++,res:{json:async()=>({taken_over:true,message:'다른 창/확장이 이어 받았어요'})}});
 await vm.runInContext(code+';waitBusy(res)',context);
 assert.equal(halted,'다른 창/확장이 이어 받았어요');assert.equal(waits,0);
});

test('background excludes unread keyword and immediately drains the next analysis', async () => {
 let batches=0; const queries=[],calls=[],saves=[];
 const context=vm.createContext({crypto:require('node:crypto').webcrypto,skwCheckActive:false,lastScreenSerpAt:0,Date:{now:()=>1000000},
  getStore:async()=>({token:'test'}),chrome:{storage:{local:{get:async()=>({})}}},rfSerpCurrent:{},
  sleep:async()=>{throw Error('unexpected wait');},
  handlers:{fetchShopSerp:async args=>{calls.push(args.keyword);return args.keyword==='bad'?{ok:false,message:'못 읽음'}:{ok:true,html:'valid'};}},
  apiFetch:async(path,args)=>{
   if(path.includes('check-queue')) { queries.push(path);return {ok:true,json:{data:{analysis_id:++batches,items:batches<3?[{item_id:batches,keyword:batches===1?'bad':'next'}]:[]}}}; }
   saves.push(args.body.item_id);return {ok:true,json:{}};
  },
 });
 await vm.runInContext(loop+';drainShopKeywordCheckQueue()',context);
 assert.deepEqual(calls,['bad','next']);assert.deepEqual(saves,[2]);assert.match(queries[1],/exclude\[\]=1/);
});

test('rank labels cover both screens, non-exposure and old rows without source', () => {
 const blade=fs.readFileSync(require('node:path').join(__dirname,'../../resources/views/admin/shop-keyword/show.blade.php'),'utf8');
 const start=blade.indexOf('    const sourceLabel =');
 const code=blade.slice(start,blade.indexOf('    function applyItemResult',start));
 for(const [rank,source,label] of [[1,'integrated','1위 · 통합'],[7,'shopping','7위 · 쇼핑'],[0,'shopping','미노출 · 쇼핑'],[2,null,'2위</span>']]) {
  assert.ok(vm.runInContext(code+`;rankSpan(${rank},${JSON.stringify(source)})`,vm.createContext({cfg:{th:5}})).includes(label));
 }
});
