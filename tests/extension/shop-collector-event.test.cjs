const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
require('../../extension/shop-serp-tab.js');
const source = fs.readFileSync(require('node:path').join(__dirname, '../../extension/background.js'), 'utf8');
const start = source.indexOf('  async fetchShopSerp({');
const handler = source.slice(start, source.indexOf('\n  /**', start));

for (const result of [{ok:false,status:403}, {ok:false,status:429}, {ok:false,status:200,captcha:true}, {ok:true,status:200}]) {
  test('background reports actual browser result '+JSON.stringify(result), async () => {
    const events = [];
    const context = vm.createContext({rfCloseWork:Promise.resolve(),
      shopSerpPolicy: {run:async()=>result},
      getStore:async()=>({token:'token',apiBase:'https://rankfree.kr'}),
      chrome:{storage:{local:{set:async()=>{},get:async()=>({rfSerpPolicy:{blockedOnResume:true}})}}},
      apiFetch:async(path,options)=>path.endsWith('interval-policy') ? {ok:true,json:{data:{interval_ms:4000,interval_revision:'v1',collector_ip:'ip'}}} : events.push({path,...options}),
    });
    await vm.runInContext('const shopSerpPool={run:async(a,k,n,execute)=>execute({tab:typeof shopSerpTab === \"undefined\" ? {} : shopSerpTab,policy:shopSerpPolicy})}; const handlers={'+handler+'};handlers.fetchShopSerp({keyword:"a",analysisId:69});',context);
    assert.equal(events.length,1);
    assert.equal(events[0].path,'/api/ext/shop-keyword/69/collector-event');
    assert.equal(events[0].body.collector,'browser');
    assert.equal(events[0].body.kind,result.captcha?'보안문자':result.ok?'success':String(result.status));
    assert.equal(events[0].body.blocked_on_resume,!result.ok);
  });
}
test('screen delegates delivery to page session; cached and cooldown replies emit no extra events',async()=>{
  for(const [result,sender] of [[{ok:false,status:403},{tab:{id:1}}],[{ok:true,cached:true},null],[{ok:false,cooldown:true},null]]) {
    const context=vm.createContext({rfCloseWork:Promise.resolve(),chrome:{storage:{local:{set:async()=>{},get:async()=>({})}}},lastScreenSerpAt:0,shopSerpPolicy:{run:async()=>result},...(sender ? {} : {getStore:async()=>({})}),apiFetch:async(path)=>{if(path.endsWith('interval-policy'))return {ok:true,json:{data:{interval_ms:4000}}};throw Error('unexpected API request');}});
    // No getStore is available: the screen path must work without an API token.
    const actual=await vm.runInContext('const shopSerpPool={run:async(a,k,n,execute)=>execute({tab:typeof shopSerpTab === \"undefined\" ? {} : shopSerpTab,policy:shopSerpPolicy})}; const handlers={'+handler+'};handlers.fetchShopSerp({keyword:"a",analysisId:69},'+JSON.stringify(sender)+');',context);
    assert.equal(actual,result);
  }
});

test('console bridge forwards IP and interval to the shared collection policy', () => {
 const source = fs.readFileSync(require('node:path').join(__dirname, '../../extension/content/console-bridge.js'), 'utf8');
 let listener, sent;
 const window = {addEventListener: (type, fn) => {listener = fn;}, postMessage:()=>{}};
 vm.runInNewContext(source, {window, document:{documentElement:{setAttribute:()=>{}}}, chrome:{runtime:{sendMessage:(m,cb)=>{sent=m;cb({ok:true});}}}});
 listener({source:window,data:{source:'rankfree-console',type:'fetchShopSerp',keyword:'test',target:{product_id:'123'},collection_mode:'fetch',collectorIp:'192.0.2.1',interval_ms:4200}});
 assert.equal(sent.payload.collectorIp,'192.0.2.1');assert.equal(sent.payload.interval_ms,4200);assert.equal(sent.payload.collection_mode,'fetch');assert.equal(sent.payload.target.product_id,'123');
});

test('tabless handler uses only the mobile integrated domain and never opens a tab or falls back', async () => {
 const calls=[], saved={};
 const context=vm.createContext({rfCloseWork:Promise.resolve(),chrome:{storage:{local:{set:async v=>Object.assign(saved,v),get:async()=>saved}}},lastScreenSerpAt:0,
  shopSerpPolicy:{run:async(k,read,ip,gap)=>{assert.equal(gap,3500);return read(gap);}},
  shopSerpTab:{read:()=>{throw Error('must not open tab');}},sleep:async()=>{},calls,
 });
 vm.runInContext(fs.readFileSync('extension/shop-serp-fetch.js','utf8'),context);
 const result=await vm.runInContext('const shopSerpPool={run:async(a,k,n,execute)=>execute({tab:shopSerpTab,policy:shopSerpPolicy})}; const handlers={spFetchHtml:async ({url})=>{calls.push(url);return {ok:true,status:200,text:"<html>no shopping</html>"};},'+handler+'};handlers.fetchShopSerp({keyword:"test",collection_mode:"fetch",interval_ms:3500},{tab:{id:1}})',context);
 assert.equal(result.ok,true);assert.equal(result.result_source,'integrated');assert.equal(calls.length,1);
 assert.equal(new URL(calls[0]).host,'m.search.naver.com');
});

test('saved fetch restriction is checked before policy cache or network',async()=>{
 const context=vm.createContext({rfCloseWork:Promise.resolve(),lastScreenSerpAt:0,
  chrome:{storage:{local:{get:async()=>({rfShopSettings:{collection_mode:'fetch',interval_ms:3500},rfFetchStopped:{fetch_stopped:true,status:403}})}}},
  shopSerpPool:{run:()=>{throw Error('must not access cached results after restriction');}}
 });
 const result=await vm.runInContext('const handlers={'+handler+'};handlers.fetchShopSerp({keyword:"test"},{tab:{id:1}})',context);
 assert.equal(result.fetch_stopped,true);assert.equal(result.ok,false);
});
