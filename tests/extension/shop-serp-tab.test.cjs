const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
require('../../extension/shop-serp-tab.js');
require('../../extension/shop-serp-policy.js');
function event(){const listeners=new Set();return {addListener:f=>listeners.add(f),removeListener:f=>listeners.delete(f),emit:(...a)=>listeners.forEach(f=>f(...a)),listeners};}
function browser(){
 const messages=event(),updated=event(),removed=event();let created=0,closed=0;
 const c={runtime:{onMessage:messages},tabs:{onUpdated:updated,onRemoved:removed,
 create:async opts=>{created++;assert.equal(opts.active,false);assert.match(opts.url,/search.shopping.naver.com\/search\/all\?query=/);return {id:7};},
 query:async()=>[],get:async()=>({url:'https://search.shopping.naver.com/search/all'}),remove:async()=>{closed++;}}};
 return {c,messages,updated,removed,counts:()=>({created,closed})};
}
test('tab reads trusted sender only, preserves product identity and cleans all listeners',async()=>{
 const f=browser();const p=new ShopSerpTab({browser:f.c}).read('한글');
 await new Promise(setImmediate);
 f.messages.emit({type:'__rfSerpRead',result:{ok:true,products:[]}},{tab:{id:99}});
 f.messages.emit({type:'__rfSerpRead',result:{ok:true,products:[{title:'상품',channelProductId:'123',nvMid:'456',rank:2,isAd:true,link:'https://smartstore.naver.com/a/products/123'}]}},{tab:{id:7}});
 const r=await p;assert.equal(r.ok,true);assert.match(r.html,/"channelProductId":"123"/);assert.match(r.html,/"sourceType":"AD"/);assert.match(r.html,/"rank":2/);
 assert.deepEqual(f.counts(),{created:1,closed:1});for(const e of [f.messages,f.updated,f.removed])assert.equal(e.listeners.size,0);
});
for(const kind of ['captcha','timeout','closed'])test('tab '+kind+' returns and closes owned tab',async()=>{
 const f=browser();const p=new ShopSerpTab({browser:f.c,timeoutMs:20}).read('a');await new Promise(setImmediate);
 if(kind==='captcha')f.updated.emit(7,{url:'https://captcha.naver.com/gate'});
 if(kind==='closed')f.removed.emit(7);
 const r=await p;assert.equal(r.ok,false);assert.equal(!!r.captcha,kind==='captcha');assert.equal(f.counts().closed,1);
});
const source=fs.readFileSync(require('node:path').join(__dirname,'../../extension/background.js'),'utf8');
const start=source.indexOf('  async fetchShopSerp({');const handler=source.slice(start,source.indexOf('\n  /**',start));
for(const mode of [undefined,'tab','request'])for(const tabOK of [true,false])test('mode '+mode+', tab success '+tabOK+' applies only final result to block policy',async()=>{
 let fetches=0,tabs=0;const waits=[];const events=[];const store={};
 const policy=new ShopSerpPolicy({storage:{get:async()=>store,set:async v=>Object.assign(store,v)},cache:{get:async()=>null,put:async()=>{}}});
 const context=vm.createContext({shopSerpPolicy:policy,shopSerpTab:{read:async kw=>{tabs++;assert.equal(kw,'same');return tabOK?{ok:true,status:200,html:'data'}:{ok:false,captcha:true,status:200};}},
 chrome:{storage:{local:{get:async()=>({rfSerpMode:mode,rfSerpPolicy:store.rfSerpPolicy})}},action:{setBadgeText(){}},alarms:{clear(){}}},
 fetch:async()=>{fetches++;return {ok:false,status:403};},setTimeout:f=>{waits.push(1600);f();},
 getStore:async()=>({}),apiFetch:async(_,v)=>events.push(v.body)});
 const r=await vm.runInContext('const handlers={'+handler+'};handlers.fetchShopSerp({keyword:"same",analysisId:70});',context);
 assert.equal(r.ok,tabOK);assert.equal(tabs,1);assert.equal(fetches,mode==='request'?1:0);assert.equal(waits.length,mode==='request'?1:0);
 assert.equal(events[0].kind,tabOK?'success':'보안문자');assert.equal(!!store.rfSerpPolicy.until,!tabOK);assert.equal(store.rfSerpPolicy.http403,0);
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
