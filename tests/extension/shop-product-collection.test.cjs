const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('extension/background.js','utf8');
const start=source.indexOf('  async collectProductPage(');
const method=source.slice(start,source.indexOf('\n  },',start)+5);
function fixture({blocked=false,redirect=false,timeout=false,early=false}={}) {
 const waiters=new Map(), removed=[],tabs=new Map();let created=0, timer,probe;
 const c=vm.createContext({productInfoWaiters:waiters,productBlockedTabs:new Map(),setTimeout:fn=>(timer=fn,1),clearTimeout:()=>{},setInterval:fn=>(probe=fn,2),clearInterval:()=>{},removeTab:id=>removed.push(id),chrome:{
  tabs:{onUpdated:{addListener:()=>{},removeListener:()=>{}},create:async()=>{
   created++;const tab={id:created,url:redirect?'https://nid.naver.com/login':'https://smartstore.naver.com/s/products/111'};tabs.set(tab.id,tab);
   if(early)waiters.get('111')({payload:{title:'상품',seller_tags:['태그']}});
   return tab;
  },get:async id=>tabs.get(id),update:async(id,opts)=>{tabs.set(id,{id,url:opts.url});return tabs.get(id);}},
  scripting:{executeScript:async()=>{if(timeout)timer();return [{result:blocked}];}}
 }});
 vm.runInContext('const handlers={'+method+'};',c);
 return {c,waiters,removed,get created(){return created;},inspect:()=>probe(),run:()=>vm.runInContext("handlers.collectProductPage({url:'https://smartstore.naver.com/s/products/111'})",c)};
}
test('waiter is installed before tab creation; fast extraction is not lost',async()=>{
 const f=fixture({early:true});const r=await f.run();assert.equal(r.ok,true);assert.equal(r.info.title,'상품');assert.equal(f.waiters.size,0);assert.deepEqual(f.removed,[1]);
});
test('same URL restriction and security redirects return reason; next retry reuses tab and succeeds',async()=>{
 for(const options of [{blocked:true},{redirect:true}]) {
  const f=fixture(options);const r=await f.run();assert.equal(r.blocked,true);assert.equal(r.retryable,true);assert.equal(r.message,'네이버 제한 화면 — 풀린 뒤 자동 재시도');assert.deepEqual(f.removed,[]);
  f.c.chrome.scripting.executeScript=async()=>{f.waiters.get('111')({payload:{title:'회복',thumbnail_url:'image',seller_tags:['태그']}});return [{result:false}];};
  const recovered=await f.run();assert.equal(recovered.ok,true);assert.equal(f.created,1);assert.deepEqual(f.removed,[1]);
 }
});
test('timeout cleans up and allows another attempt',async()=>{
 const f=fixture({timeout:true});const r=await f.run();assert.equal(r.ok,false);assert.match(r.message,/시간 초과/);assert.equal(f.waiters.size,0);assert.deepEqual(f.removed,[1]);
});
test('concurrent request cannot replace an active product waiter',async()=>{
 const f=fixture();const first=f.run();const second=await f.run();assert.equal(second.ok,false);assert.match(second.message,/이미 수집/);f.waiters.get('111')({payload:{title:'상품'}});await first;assert.equal(f.created,1);
});
test('extracted info reaches waiter even when API save has not resolved or fails',async()=>{
 const a=source.indexOf('  async saveProductInfo(');const code=source.slice(a,source.indexOf('\n  },',a)+5);
 let notified=false, resolveSave;
 const c=vm.createContext({productInfoWaiters:new Map([['111',r=>{notified=!!r.payload;}]]),getStore:async()=>({token:'token'}),apiFetch:()=>new Promise(resolve=>resolveSave=resolve)});
 vm.runInContext('const handlers={'+code+'};',c);
 const pending=vm.runInContext("handlers.saveProductInfo({channel_product_id:'111',title:'상품'})",c);
 assert.equal(notified,true);await new Promise(setImmediate);resolveSave({ok:false,status:500,json:{message:'failed'}});assert.equal((await pending).ok,false);
});
test('product content does not save restriction titles and extracts image/tags after recovery',async()=>{
 const content=fs.readFileSync('extension/content/product.js','utf8');
 const a=content.indexOf('  async function collectProductInfo()');const code=content.slice(a,content.indexOf('\n  async function spFetchTop',a));
 for(const blocked of [true,false]){
  const saved=[];
  const c=vm.createContext({document:{body:{innerText:blocked?'검색 서비스 이용이 제한되었습니다':'상품 상세'},querySelector:()=>null},getChannelProductNo:()=>111,getMyPreloadedAsync:async()=>({}),spNodeFrom:()=>({product:{name:'파티 상품',seoInfo:{sellerTags:[{text:'할로윈'}]},representImage:{url:'https://img/product.jpg'}},smartStoreV2:{channel:{channelName:'파티타임'}}}),productDeliveryFee:()=>0,num:Number,getProductPrice:()=>1000,sendBg:async(type,payload)=>saved.push(payload)});
  await vm.runInContext(code+';collectProductInfo()',c);
  assert.equal(saved.length,blocked?0:1);
  if(!blocked){assert.equal(saved[0].thumbnail_url,'https://img/product.jpg');assert.equal(saved[0].seller_tags[0],'할로윈');}
 }
});
test('recollect displays restriction immediately, retries and reloads only after successful save',async()=>{
 const blade=fs.readFileSync('resources/views/admin/shop-keyword/show.blade.php','utf8');const a=blade.indexOf('    const recollect =');const code=blade.slice(a,blade.indexOf('\n    (async function boot()',a)).replace(/\{\{ route\([^}]+\}\}/g, '/status');
 let attempt=0,reloads=0,retry;const events=[];const button={},status={};
 const c=vm.createContext({document:{getElementById:id=>id==='sk-recollect'?Object.assign(button,{addEventListener:()=>{}}):status},cfg:{productUrl:'url',urls:{productInfo:'save',collectorEvent:'event'}},waitExt:async()=>true,extCall:async()=>++attempt===1?{ok:false,blocked:true,message:'네이버 제한 화면 — 풀린 뒤 자동 재시도'}:{ok:true,info:{title:'상품'}},post:async(url,body)=>{if(url==='event'){events.push(body);return {ok:true};}return {ok:attempt>2,status:attempt>2?200:500,json:async()=>({message:'저장 서버 오류'})};},setInterval:()=>{},setTimeout:fn=>(retry=fn,1),clearTimeout:()=>{},location:{reload:()=>reloads++}});
 vm.runInContext(code,c);await vm.runInContext('recollectProduct()',c);
 assert.equal(status.textContent,'네이버 제한 화면 — 풀린 뒤 자동 재시도');assert.equal(events[0].stage,'product');assert.equal(reloads,0);
 await retry();assert.equal(status.textContent,'저장 서버 오류');assert.equal(reloads,0);
 await retry();assert.equal(reloads,1);assert.equal(status.textContent,'');assert.equal(button.disabled,false);
});

test('retry ignores the old restriction DOM while a reused tab is loading',async()=>{
 const f=fixture({early:false});let loading=true,probes=0;
 f.c.chrome.tabs.get=async()=>({status:loading?'loading':'complete',url:'https://smartstore.naver.com/s/products/111'});
 f.c.chrome.scripting.executeScript=async()=>{probes++;f.waiters.get('111')({payload:{title:'회복'}});return [{result:false}];};
 const pending=f.run();await new Promise(setImmediate);assert.equal(probes,0);
 loading=false;await f.inspect();assert.equal((await pending).ok,true);
});
test('Naver service unavailable is distinguished from a restriction screen',async()=>{
 const f=fixture({blocked:'unavailable'});const result=await f.run();assert.equal(result.ok,false);assert.equal(result.retryable,true);assert.match(result.message,/서비스 접속 불가/);
});

test('headless product queue reports real failures, continues next item and never labels a successful save as client_error', async()=>{
 const a=source.indexOf('async function drainShopKeywordProductQueue()');
 const code=source.slice(a,source.indexOf('// 순위 확인도 확장이',a));
 const events=[],saves=[],collected=[];
 const c=vm.createContext({skwQueueActive:false,getStore:async()=>({token:'token',apiBase:'local'}),
  chrome:{storage:{local:{get:async()=>({}),set:async()=>{}}}},
  apiFetch:async(url,opts)=>url.includes('product-queue')?{ok:true,json:{data:{items:[
   {analysis_id:1,product_url:'blocked'},{analysis_id:2,product_url:'success'},{analysis_id:3,product_url:'save-fails'}
  ]}}}:(saves.push({url,opts}),{ok:!url.includes('/3/'),status:422,json:{message:'상품 불일치'}}),
  handlers:{collectProductPage:async({url})=>{collected.push(url);return url==='blocked'?{ok:false,message:'네이버 제한 화면'}:{ok:true,info:{title:'상품'}};}},
  reportShopClientEvent:async(...args)=>events.push(args),setTimeout:fn=>fn()
 });
 await vm.runInContext(code+';drainShopKeywordProductQueue()',c);
 assert.deepEqual(collected,['blocked','success','save-fails']);
 assert.equal(saves.length,2);assert.equal(events.length,2);
 assert.equal(events[0][0],1);assert.match(events[0][3],/네이버 제한/);
 assert.equal(events[1][0],3);assert.match(events[1][3],/422.*상품 불일치/);
 assert.equal(c.skwQueueActive,false);
});
