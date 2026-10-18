const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
require('../../extension/shop-serp-tab.js');
require('../../extension/shop-serp-policy.js');
function event(){const listeners=new Set();return {addListener:f=>listeners.add(f),removeListener:f=>listeners.delete(f),emit:(...a)=>listeners.forEach(f=>f(...a)),listeners};}
function browser(){
 const messages=event(),updated=event(),removed=event();let created=0,closed=0;const updates=[],store={},live=new Map();
 const c={runtime:{onMessage:messages},storage:{session:{get:async()=>store,set:async v=>Object.assign(store,v)}},tabs:{onUpdated:updated,onRemoved:removed,
 create:async opts=>{created++;assert.equal(opts.active,false);assert.equal(opts.pinned,false);assert.match(opts.url,/search.shopping.naver.com\/search\/all\?query=/);const tab={id:created+6,url:opts.url};live.set(tab.id,tab);return tab;},
 update:async(id,opts)=>{assert.equal(opts.active,false);updates.push({id,...opts});Object.assign(live.get(id),opts);return live.get(id);},
 get:async id=>{if(!live.has(id))throw Error('No tab');return live.get(id);},remove:async()=>{closed++;}}};
 return {c,messages,updated,removed,updates,live,counts:()=>({created,closed})};
}
test('tab reads trusted sender only, preserves product identity and cleans all listeners',async()=>{
 const f=browser();const p=new ShopSerpTab({browser:f.c}).read('한글');
 await new Promise(setImmediate);
 f.messages.emit({type:'__rfSerpRead',result:{ok:true,products:[]}},{tab:{id:99}});
 f.messages.emit({type:'__rfSerpRead',result:{ok:true,products:[{title:'상품',channelProductId:'123',nvMid:'456',rank:2,isAd:true,link:'https://smartstore.naver.com/a/products/123'}]}},{tab:{id:7}});
 const r=await p;assert.equal(r.ok,true);assert.match(r.html,/"channelProductId":"123"/);assert.match(r.html,/"sourceType":"AD"/);assert.match(r.html,/"rank":2/);
 assert.deepEqual(f.counts(),{created:1,closed:0});for(const e of [f.messages,f.updated,f.removed])assert.equal(e.listeners.size,0);
});
for(const kind of ['captcha','timeout','closed'])test('tab '+kind+' returns without closing tab',async()=>{
 const f=browser();const p=new ShopSerpTab({browser:f.c,timeoutMs:20}).read('a');await new Promise(setImmediate);
 if(kind==='captcha')f.updated.emit(7,{url:'https://captcha.naver.com/gate'});
 if(kind==='closed')f.removed.emit(7);
 const r=await p;assert.equal(r.ok,false);assert.equal(!!r.captcha,kind==='captcha');assert.equal(f.counts().closed,0);
});
const source=fs.readFileSync(require('node:path').join(__dirname,'../../extension/background.js'),'utf8');
const start=source.indexOf('  async fetchShopSerp({');const handler=source.slice(start,source.indexOf('\n  /**',start));
for(const mode of [undefined,'tab','request'])for(const tabOK of [true,false])test('mode '+mode+', tab success '+tabOK+' applies only final result to block policy',async()=>{
 let fetches=0,tabs=0;const waits=[];const events=[];const store={};
 const policy=new ShopSerpPolicy({storage:{get:async()=>store,set:async v=>Object.assign(store,v)},cache:{get:async()=>null,put:async()=>{}}});
 const context=vm.createContext({shopSerpPolicy:policy,shopSerpTab:{read:async kw=>{tabs++;assert.equal(kw,'same');return tabOK?{ok:true,status:200,html:'data'}:{ok:false,captcha:true,status:200};}},
 chrome:{storage:{session:{get:async()=>({}),set:async()=>{}},local:{get:async()=>({rfSerpMode:mode,rfSerpPolicy:store.rfSerpPolicy})}},action:{setBadgeText(){}},alarms:{clear(){}}},
 fetch:async()=>{fetches++;return {ok:false,status:403};},setTimeout:(f,ms)=>{waits.push(ms);f();},
 getStore:async()=>({}),apiFetch:async(_,v)=>events.push(v.body)});
 const r=await vm.runInContext('const handlers={'+handler+'};handlers.fetchShopSerp({keyword:"same",analysisId:70});',context);
 assert.equal(r.ok,tabOK);assert.equal(tabs,1);assert.equal(fetches,mode==='request'?1:0);assert.equal(waits.length,mode==='request'?1:0);
 assert.equal(events[0].kind,tabOK?'success':'captcha_wait');assert.equal(!!store.rfSerpPolicy.until,!tabOK);assert.equal(store.rfSerpPolicy.http403,0);
});
const content=fs.readFileSync(require('node:path').join(__dirname,'../../extension/content/content.js'),'utf8');
const readStart=content.indexOf('  async function runSerpRead()');
const readFn=content.slice(readStart,content.indexOf('  // 백그라운드 상품분석 진행률 수신',readStart));
for(const kind of ['products','empty','captcha','unknown'])test('document-only reader '+kind,async()=>{
 const messages=[];let reads=0;
 const context=vm.createContext({document:{body:{innerText:kind==='captcha'?'보안문자 입력':kind==='empty'?'검색 결과가 없습니다':''}},
 domNextData:()=>{reads++;return kind==='products'?{products:[{id:123}]}:null;},
 chrome:{runtime:{sendMessage:async m=>messages.push(m)}},setTimeout:f=>f(),fetch:()=>{throw Error('must not fetch');}});
 await vm.runInContext(readFn+'runSerpRead()',context);
 assert.equal(messages.length,1);const r=messages[0].result;
 assert.equal(r.ok,['products','empty'].includes(kind));assert.equal(!!r.captcha,kind==='captcha');
 if(kind==='unknown'){assert.equal(reads,20);assert.equal(r.status,0);}
});

test('reuses one tab across keywords and worker instances; recreates only after user closes it',async()=>{
 const f=browser();let reader=new ShopSerpTab({browser:f.c});
 async function read(keyword,id){const p=reader.read(keyword);await new Promise(setImmediate);f.messages.emit({type:'__rfSerpRead',result:{ok:true,products:[]}},{tab:{id}});assert.equal((await p).ok,true);}
 await read('first',7);await read('second',7);
 reader=new ShopSerpTab({browser:f.c});await read('third',7);
 assert.deepEqual(f.counts(),{created:1,closed:0});
 assert.deepEqual(f.updates.map(x=>x.id),[7,7]);assert.match(f.updates[0].url,/query=second#rfserp$/);
 f.live.delete(7);f.removed.emit(7);assert.equal(f.counts().created,1);
 await read('fourth',8);assert.deepEqual(f.counts(),{created:2,closed:0});
});
for(const kind of ['timeout'])test('reuses tab after '+kind,async()=>{
 const f=browser(),reader=new ShopSerpTab({browser:f.c,timeoutMs:20});
 const first=reader.read('a');await new Promise(setImmediate);
 if(kind==='captcha')f.updated.emit(7,{url:'https://captcha.naver.com/gate'});
 assert.equal((await first).ok,false);
 const next=reader.read('b');await new Promise(setImmediate);f.messages.emit({type:'__rfSerpRead',result:{ok:true,products:[]}},{tab:{id:7}});
 assert.equal((await next).ok,true);assert.deepEqual(f.counts(),{created:1,closed:0});
});

test('passive peek survives reader restart, reads only matching keyword and never navigates a gate',async()=>{
 const f=browser();let reader=new ShopSerpTab({browser:f.c});
 const p=reader.read('same');await new Promise(setImmediate);
 f.updated.emit(7,{url:'https://captcha.naver.com/gate'});await p;
 let solved=false,inspections=0;
 f.c.tabs.sendMessage=async(id,msg)=>{assert.equal(id,7);assert.equal(msg.type,'__rfSerpPeek');inspections++;if(!solved)throw Error('No receiver on captcha');return {ok:true,products:[{id:'123'}]};};
 reader=new ShopSerpTab({browser:f.c});
 assert.equal(await reader.peek('other'),null);assert.equal(inspections,0);
 assert.equal(await reader.peek('same'),null);solved=true;
 assert.equal((await reader.peek('same')).ok,true);
 assert.equal(f.updates.length,0);assert.deepEqual(f.counts(),{created:1,closed:0});
});

for(const kind of ['captcha','unknown','products','empty'])test('passive document probe only accepts normal results: '+kind,()=>{
 const start=content.indexOf("  chrome.runtime.onMessage.addListener((msg, sender, reply) => {");
 const code=content.slice(start,content.indexOf('  // Only read the loaded document',start));
 let listener,reply;
 const context=vm.createContext({chrome:{runtime:{onMessage:{addListener:f=>{listener=f;}}}},
 document:{body:{innerText:kind==='captcha'?'보안문자 입력':kind==='empty'?'검색 결과가 없습니다':''}},
 domNextData:()=>kind==='products'?{products:[{id:1}]}:null,
 fetch:()=>{throw Error('No network allowed');}});
 vm.runInContext(code,context);listener({type:'__rfSerpPeek'},null,r=>{reply=r;});
 assert.equal(reply.ok,['products','empty'].includes(kind));
});

test('captcha tab stays untouched across reader restart and next keyword until human recovery',async()=>{
 const f=browser();let reader=new ShopSerpTab({browser:f.c});
 const p=reader.read('held');await new Promise(setImmediate);
 f.updated.emit(7,{url:'https://captcha.naver.com/gate'});await p;
 reader=new ShopSerpTab({browser:f.c});
 assert.equal((await reader.read('next')).cooldown,true);
 assert.equal(f.updates.length,0);assert.equal(f.counts().closed,0);
 f.c.tabs.sendMessage=async()=>({ok:true,products:[]});
 const next=reader.read('next');await new Promise(setImmediate);
 f.messages.emit({type:'__rfSerpRead',result:{ok:true,products:[]}},{tab:{id:7}});
 assert.equal((await next).ok,true);assert.equal(f.updates.length,1);
});
test('normal document scrolls exactly once after reading, including passive recovery checks',async()=>{
 const start=content.indexOf("  chrome.runtime.onMessage.addListener((msg, sender, reply) => {");
 const code=content.slice(start,content.indexOf('  // 백그라운드 상품분석 진행률 수신',start));
 const order=[];let listener;
 const context=vm.createContext({window:{innerHeight:900,scrollBy:(x,y)=>{assert.equal(y,900);order.push('scroll');}},document:{body:{innerText:''}},
 domNextData:()=>{order.push('read');return {products:[{id:1}]};},chrome:{runtime:{onMessage:{addListener:f=>listener=f},sendMessage:async()=>order.push('send')}}});
 await vm.runInContext(code+'runSerpRead()',context);
 listener({type:'__rfSerpPeek'},null,()=>{});
 assert.deepEqual(order,['read','scroll','send','read']);
});
