const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
require('../../extension/shop-serp-tab.js');
require('../../extension/shop-serp-policy.js');
function event(){const listeners=new Set();return {addListener:f=>listeners.add(f),removeListener:f=>listeners.delete(f),emit:(...a)=>listeners.forEach(f=>f(...a)),listeners};}
function browser(){
 const messages=event(),updated=event(),removed=event();let created=0,closed=0;const updates=[],store={},live=new Map();
 const c={scripting:{executeScript:async()=>{}},runtime:{onMessage:messages},storage:{session:{get:async()=>store,set:async v=>Object.assign(store,v)}},tabs:{onUpdated:updated,onRemoved:removed,
 create:async opts=>{created++;assert.equal(opts.active,false);assert.equal(opts.pinned,false);assert.match(opts.url,/m.search.naver.com\/search.naver\?where=m&query=/);const tab={id:created+6,url:opts.url};live.set(tab.id,tab);return tab;},
 update:async(id,opts)=>{assert.equal(opts.active,!!opts.active);updates.push({id,...opts});Object.assign(live.get(id),opts);return live.get(id);},
 get:async id=>{if(!live.has(id))throw Error('No tab');return live.get(id);},remove:async()=>{closed++;}}};
 return {c,messages,updated,removed,updates,live,counts:()=>({created,closed})};
}
test('tab reads trusted sender only, preserves product identity and cleans all listeners',async()=>{
 const f=browser();const p=new ShopSerpTab({browser:f.c,wait:async()=>{}}).read('한글');
 await new Promise(setImmediate);
 f.messages.emit({type:'__rfSerpRead',result:{ok:true,products:[]}},{tab:{id:99}});
 f.messages.emit({type:'__rfSerpRead',result:{ok:true,products:[{title:'상품',channelProductId:'123',nvMid:'456',rank:2,isAd:true,link:'https://smartstore.naver.com/a/products/123'}]}},{tab:{id:7}});
 const r=await p;assert.equal(r.ok,true);assert.match(r.html,/"channelProductId":"123"/);assert.match(r.html,/"sourceType":"AD"/);assert.match(r.html,/"rank":2/);
 assert.deepEqual(f.counts(),{created:1,closed:0});for(const e of [f.messages,f.updated,f.removed])assert.equal(e.listeners.size,0);
});
for(const kind of ['captcha','timeout','closed'])test('tab '+kind+' returns without closing tab',async()=>{
 const f=browser();const p=new ShopSerpTab({browser:f.c,timeoutMs:20,wait:async()=>{}}).read('a');await new Promise(setImmediate);
 if(kind==='captcha')f.updated.emit(7,{url:'https://captcha.naver.com/gate'});
 if(kind==='closed')f.removed.emit(7);
 const r=await p;assert.equal(r.ok,false);assert.equal(!!r.captcha,kind==='captcha');assert.equal(f.counts().closed,0);
});
const source=fs.readFileSync(require('node:path').join(__dirname,'../../extension/background.js'),'utf8');
const start=source.indexOf('  async fetchShopSerp({');const handler=source.slice(start,source.indexOf('\n  /**',start));
for(const mode of [undefined,'tab','request'])for(const tabOK of [true,false])test('mode '+mode+', tab success '+tabOK+' applies only final result to block policy',async()=>{
 let fetches=0,tabs=0;const waits=[];const events=[];const store={};
 const policy=new ShopSerpPolicy({storage:{get:async()=>store,set:async v=>Object.assign(store,v)},cache:{get:async()=>null,put:async()=>{}}});
 const context=vm.createContext({rfCloseWork:Promise.resolve(),shopSerpPolicy:policy,shopSerpTab:{read:async kw=>{tabs++;assert.equal(kw,'same');return tabOK?{ok:true,status:200,html:'data'}:{ok:false,captcha:true,status:200};}},
 chrome:{storage:{local:{set:async()=>{},get:async()=>({rfSerpMode:mode,rfSerpPolicy:store.rfSerpPolicy})}},action:{setBadgeText(){}},alarms:{clear(){}}},
 fetch:async()=>{fetches++;return {ok:false,status:403};},setTimeout:(f,ms)=>{waits.push(ms);f();},
 getStore:async()=>({}),apiFetch:async(path,v)=>path.endsWith('interval-policy') ? {ok:true,json:{data:{interval_ms:4000}}} : events.push(v.body)});
 const r=await vm.runInContext('const shopSerpPool={run:async(a,k,n,execute)=>execute({tab:typeof shopSerpTab === \"undefined\" ? {} : shopSerpTab,policy:shopSerpPolicy})}; const handlers={'+handler+'};handlers.fetchShopSerp({keyword:"same",analysisId:70});',context);
 assert.equal(r.ok,tabOK);assert.equal(tabs,1);assert.equal(fetches,0);assert.equal(waits.length,0);
 assert.equal(events[0].kind,tabOK?'success':'보안문자');assert.equal(!!store.rfSerpPolicy.until,!tabOK);assert.equal(store.rfSerpPolicy.http403,0);
});
const content=fs.readFileSync(require('node:path').join(__dirname,'../../extension/content/content.js'),'utf8');
const readStart=content.indexOf('  let serpScrolled = false;');
const readFn=content.slice(readStart,content.indexOf('  // 백그라운드 상품분석 진행률 수신',readStart));
for(const kind of ['products','empty','captcha','unknown'])test('document-only reader '+kind,async()=>{
 const messages=[];let reads=0;
 const context=vm.createContext({rfCloseWork:Promise.resolve(),window:{innerHeight:900,scrollBy:()=>{}},getComputedStyle:()=>({visibility:'visible'}),document:{readyState:'complete',querySelector:()=>({getClientRects:()=>[{}]}),body:{innerText:kind==='captcha'?'보안문자 입력':kind==='empty'?'검색 결과가 없습니다':''}},
 serpProducts:()=>{reads++;return kind==='products'?{products:[{id:123}]}:null;},
 chrome:{runtime:{onMessage:{addListener(){}},sendMessage:async m=>messages.push(m)}},setTimeout:f=>f(),fetch:()=>{throw Error('must not fetch');}});
 await vm.runInContext(readFn+'runSerpRead()',context);
 assert.equal(messages.length,1);const r=messages[0].result;
 assert.equal(r.ok,['products','empty'].includes(kind));assert.equal(!!r.captcha,kind==='captcha');
 if(kind==='unknown'){assert.equal(reads,1);assert.equal(r.status,0);}
});

test('reuses one tab across keywords and worker instances; recreates only after user closes it',async()=>{
 const f=browser();let reader=new ShopSerpTab({browser:f.c,wait:async()=>{}});
 async function read(keyword,id){const p=reader.read(keyword);await new Promise(setImmediate);f.messages.emit({type:'__rfSerpRead',result:{ok:true,products:[]}},{tab:{id}});assert.equal((await p).ok,true);}
 await read('first',7);await read('second',7);
 reader=new ShopSerpTab({browser:f.c,wait:async()=>{}});await read('third',7);
 assert.deepEqual(f.counts(),{created:1,closed:0});
 assert.deepEqual(f.updates.map(x=>x.id),[7,7]);assert.match(f.updates[0].url,/query=second$/);
 f.live.delete(7);f.removed.emit(7);assert.equal(f.counts().created,1);
 await read('fourth',8);assert.deepEqual(f.counts(),{created:2,closed:0});
});
for(const kind of ['captcha','timeout'])test('reuses tab after '+kind,async()=>{
 const f=browser(),reader=new ShopSerpTab({browser:f.c,timeoutMs:20,wait:async()=>{}});
 const first=reader.read('a');await new Promise(setImmediate);
 if(kind==='captcha')f.updated.emit(7,{url:'https://captcha.naver.com/gate'});
 assert.equal((await first).ok,false);
 if(kind==='captcha'){ f.c.tabs.sendMessage=async()=>({ok:true,products:[]}); assert.equal((await reader.peek('a')).ok,true); }
 const next=reader.read('b');await new Promise(setImmediate);f.messages.emit({type:'__rfSerpRead',result:{ok:true,products:[]}},{tab:{id:7}});
 assert.equal((await next).ok,true);assert.deepEqual(f.counts(),{created:1,closed:0});
});

test('passive peek survives reader restart, reads only matching keyword and never navigates a gate',async()=>{
 const f=browser();let reader=new ShopSerpTab({browser:f.c,wait:async()=>{}});
 const p=reader.read('same');await new Promise(setImmediate);
 f.updated.emit(7,{url:'https://captcha.naver.com/gate'});await p;
 let solved=false,inspections=0;
 f.c.tabs.sendMessage=async(id,msg)=>{assert.equal(id,7);assert.equal(msg.type,'__rfSerpPeek');inspections++;if(!solved)throw Error('No receiver on captcha');return {ok:true,products:[{id:'123'}]};};
 reader=new ShopSerpTab({browser:f.c,wait:async()=>{}});
 assert.equal(await reader.peek('other'),null);assert.equal(inspections,0);
 assert.equal(await reader.peek('same'),null);solved=true;f.live.get(7).url='https://msearch.shopping.naver.com/search/all?query=same';
 assert.equal((await reader.peek('same')).ok,true);
 assert.equal(f.updates.filter(u=>u.url).length,1);assert.match(f.updates.find(u=>u.url).url,/query=same&ct=1$/);assert.deepEqual(f.counts(),{created:1,closed:0});
});

for(const kind of ['captcha','unknown','products','empty'])test('passive document probe only accepts normal results: '+kind,async()=>{
 const start=content.indexOf('  let serpScrolled = false;');
 const code=content.slice(start,content.indexOf('  // Only read the loaded document',start));
 let listener,reply;
 const context=vm.createContext({rfCloseWork:Promise.resolve(),window:{innerHeight:900,scrollBy:()=>{}},setTimeout:f=>f(),chrome:{runtime:{onMessage:{addListener:f=>{listener=f;}}}},
 getComputedStyle:()=>({visibility:'visible'}),document:{readyState:'complete',querySelector:()=>({getClientRects:()=>[{}]}),body:{innerText:kind==='captcha'?'보안문자 입력':kind==='empty'?'검색 결과가 없습니다':''}},
 serpProducts:()=>kind==='products'?{products:[{id:1}]}:null,
 fetch:()=>{throw Error('No network allowed');}});
 vm.runInContext(code,context);await new Promise(resolve=>listener({type:'__rfSerpPeek'},null,r=>{reply=r;resolve();}));
 assert.equal(reply.ok,['products','empty'].includes(kind));
});

test('loaded document scrolls once and settles before reading rank',async()=>{
 const events=[];
 const context=vm.createContext({rfCloseWork:Promise.resolve(),window:{innerHeight:900,scrollBy:()=>events.push('scroll')},
 getComputedStyle:()=>({visibility:'visible'}),document:{readyState:'complete',querySelector:()=>({getClientRects:()=>[{}]}),body:{innerText:''}},serpProducts:()=>{events.push('read');return {products:[{id:1}]};},
 chrome:{runtime:{onMessage:{addListener(){}},sendMessage:async()=>events.push('send')}},setTimeout:(f,ms)=>{events.push(ms);f();}});
 await vm.runInContext(readFn+'runSerpRead()',context);
 assert.deepEqual(events,['read','scroll',750,'read','send']);
});

test('target request reaches only its active collection reader, without generic reply racing it', async()=>{
 const begin=source.indexOf('chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {');
 const generic=source.slice(begin,source.indexOf('\n});',begin)+4);
 let listener;
 vm.runInNewContext(generic,{handlers:{},chrome:{runtime:{onMessage:{addListener:f=>listener=f}}}});
 let genericReplied=false;
 assert.equal(listener({type:'__rfSerpTarget'},{tab:{id:7}},()=>genericReplied=true),false);
 assert.equal(genericReplied,false);
 const f=browser(),target={product_id:'111',id_kind:'channel'};
 const p=new ShopSerpTab({browser:f.c,wait:async()=>{}}).read('same',target);await new Promise(setImmediate);
 let actual;
 f.messages.emit({type:'__rfSerpTarget'},{tab:{id:99}},v=>actual=v);assert.equal(actual,undefined);
 f.messages.emit({type:'__rfSerpTarget'},{tab:{id:7}},v=>actual=v);assert.deepEqual(actual,target);
 f.messages.emit({type:'__rfSerpRead',result:{ok:true,products:[{channelProductId:'111',rank:1}],result_source:'integrated'}},{tab:{id:7}});
 assert.equal((await p).result_source,'integrated');
});

test('tabless shopping HTML normalizes embedded list, identity and advertisements for server ranking', () => {
 const root={props:{pageProps:{shoppingResult:{products:[
  {item:{channelProductId:'ad',productTitle:'advertisement',rank:1,mallName:'mall',adType:'1'}},
  {item:{channelProductId:'mine',nvMid:'catalog',productTitle:'organic',rank:3,mallName:'mall'}},
 ]}}}};
 const html=ShopSerpTab.fetchedHtml('<script id="__NEXT_DATA__" type="application/json">'+JSON.stringify(root)+'</script>');
 const state=JSON.parse(html.match(/= ([\s\S]*);<\/script>/)[1]);
 const slots=state.initProps.pagedSlot[0].slots;
 assert.equal(slots[0].data.sourceType,'AD');assert.equal(slots[1].data.channelProductId,'mine');
 assert.equal(slots[1].data.nvMid,'catalog');assert.equal(slots[1].data.rank,3);
});

test('captcha activates the existing tab and focuses its window; missing recovery tab is never recreated',async()=>{
 const f=browser(),focused=[];
 f.c.windows={update:async(id,opts)=>focused.push({id,...opts})};
 const reader=new ShopSerpTab({browser:f.c});
 const p=reader.read('same');await new Promise(setImmediate);
 f.live.get(7).windowId=42;
 f.updated.emit(7,{url:'https://captcha.naver.com/gate'});
 assert.equal((await p).captcha,true);assert.equal(f.updates.at(-1).active,true);assert.deepEqual(focused,[{id:42,focused:true}]);
 f.live.delete(7);
 f.c.tabs.create=async opts=>{assert.equal(opts.active,true);assert.match(opts.url,/msearch.shopping.naver.com/);return {id:8,windowId:43};};
 await reader.peek('same');assert.equal(focused.at(-1).id,42);assert.equal(f.counts().created,1);
});

test('captcha recovery strips hashes, waits eight seconds, tries three keywords and never loops navigation on resume',async()=>{
 const f=browser();let now=10000,reply={ok:false,captcha:true};
 const realNow=Date.now;Date.now=()=>now;
 try {
  let reader=new ShopSerpTab({browser:f.c,wait:async()=>{}});
  reader.alternativeKeywords=['next','core','third'];
  const p=reader.read('original');await new Promise(setImmediate);
  f.messages.emit({type:'__rfSerpRead',result:{ok:false,captcha:true,status:200}},{tab:{id:7}});
  assert.equal((await p).captcha,true);
  assert.equal(f.updates.filter(u=>u.url).length,1);
  assert.match(f.updates[0].url,/query=original&ct=1$/);
  f.c.tabs.sendMessage=async()=>reply;
  // A new service worker / Continue must inspect, never issue a fresh navigation.
  reader=new ShopSerpTab({browser:f.c,wait:async()=>{}});reader.alternativeKeywords=['next','core','third'];
  await reader.read('original');now+=7999;await reader.peek('original');
  assert.equal(f.updates.filter(u=>u.url).length,1);
  now++;await reader.peek('original');assert.match(f.updates.at(-1).url,/query=next&ct=1$/);
  reply={ok:false,captcha:true,challengeVisible:true};now+=9000;await reader.peek('original');
  const count=f.updates.length;now+=7000;reply={ok:false,captcha:true};await reader.peek('original');assert.equal(f.updates.length,count);
  now+=1000;await reader.peek('original');assert.match(f.updates.at(-1).url,/query=core&ct=1$/);
  now+=8000;await reader.peek('original');assert.match(f.updates.at(-1).url,/query=third&ct=1$/);
  now+=8000;assert.match((await reader.peek('original')).message,/보안문자 화면이 열리지 않음/);
  const finalCount=f.updates.length;now+=8000;await reader.peek('original');assert.equal(f.updates.length,finalCount);
  // Solving an alternative restores original; alternative products must not be saved as original.
  reply={ok:true,products:[{id:'alternative'}],result_source:'integrated'};
  const restored=reader.peek('original');await new Promise(setImmediate);
  assert.match(f.updates.at(-1).url,/query=original$/);
  f.messages.emit({type:'__rfSerpRead',result:{ok:true,products:[{id:'original'}],result_source:'integrated'}},{tab:{id:7}});
  const r=await restored;assert.match(r.html,/original/);assert.doesNotMatch(r.html,/alternative/);
 } finally {Date.now=realNow;}
});

test('solved original document is saved through policy after Continue without navigation',async()=>{
 const f=browser(),store={},saved=[];
 const reader=new ShopSerpTab({browser:f.c,wait:async()=>{}});
 const policy=new ShopSerpPolicy({storage:{get:async()=>store,set:async v=>Object.assign(store,v)},cache:{get:async()=>null,put:async(k,v)=>saved.push(v)},wait:async()=>{}});
 const run=()=>policy.run('same',()=>reader.read('same'),'browser',1000,null,()=>reader.peek('same'));
 const p=run();await new Promise(setImmediate);f.updated.emit(7,{url:'https://captcha.naver.com/gate'});await p;
 const before=f.updates.length;await policy.resume('browser');
 f.c.tabs.sendMessage=async()=>({ok:true,products:[{id:'solved'}],result_source:'integrated'});
 const r=await run();assert.equal(r.ok,true);assert.equal(r.result_source,'integrated');assert.match(r.html,/solved/);
 assert.equal(f.updates.length,before);assert.equal(saved.length,1);assert.equal(store.rfSerpPolicy.until,0);
});

test('integrated recovery document is observed without clicking shopping or captcha controls',async()=>{
 const navigation=fs.readFileSync(require('node:path').join(__dirname,'../../extension/content/shop-navigation.js'),'utf8');
 const listeners=[];const sent=[];
 const context=vm.createContext({location:{hash:'',href:'https://m.search.naver.com/search.naver?query=same&ct=1'},URL,
 chrome:{runtime:{sendMessage:async m=>{sent.push(m);return {owned:true,recovering:true};},onMessage:{addListener:f=>listeners.push(f)}}},
 document:{body:{innerText:'검색 서비스 이용이 제한되었습니다'},querySelectorAll:selector=>selector==='input'?[{name:'captcha',getClientRects:()=>[{}],getAttribute:()=>'',click:()=>assert.fail('must not click')}]:[],readyState:'complete'},
 getComputedStyle:()=>({visibility:'visible',display:'block'})});
 await vm.runInContext(navigation,context);
 assert.equal(sent.length,1);
 let reply;listeners.forEach(listener=>listener({type:'__rfSerpPeek'},null,r=>reply=r));
 assert.equal(reply.captcha,true);assert.equal(reply.challengeVisible,true);assert.equal(reply.ok,false);
});
