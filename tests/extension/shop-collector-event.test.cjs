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
      chrome:{storage:{local:{get:async()=>({rfSerpPolicy:{blockedOnResume:true}})}}},
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
    const context=vm.createContext({rfCloseWork:Promise.resolve(),chrome:{storage:{local:{get:async()=>({})}}},lastScreenSerpAt:0,shopSerpPolicy:{run:async()=>result},...(sender ? {} : {getStore:async()=>({})}),apiFetch:async(path)=>{if(path.endsWith('interval-policy'))return {ok:true,json:{data:{interval_ms:4000}}};throw Error('unexpected API request');}});
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

test('tabless checks integrated first, falls back only without shopping block and never opens a tab', async () => {
 for (const [html,expected,blocked] of [['<section data-shopping-block></section>',1,false],['<p>no shopping</p>',2,false],['보안문자',1,true]]) {
  const calls=[], waits=[];
  const context=vm.createContext({rfCloseWork:Promise.resolve(),chrome:{storage:{local:{get:async()=>({})}}},lastScreenSerpAt:0,ShopSerpTab,
   shopSerpPolicy:{run:async(k,read,ip,gap)=>{assert.equal(gap,3500);return read();}},
   shopSerpTab:{read:()=>{throw Error('must not open tab');}},
   sleep:async ms=>waits.push(ms),
  });
  const result=await vm.runInContext('const shopSerpPool={run:async(a,k,n,execute)=>execute({tab:shopSerpTab,policy:shopSerpPolicy})}; const handlers={spFetchHtml:async ({url})=>{calls.push(url);return {ok:true,status:200,text:calls.length===1?html:"shopping"};},'+handler+'};handlers.fetchShopSerp({keyword:"테스트",collection_mode:"fetch",interval_ms:3500},{tab:{id:1}})',Object.assign(context,{calls,html}));
  assert.equal(calls.length,expected);assert.equal(result.captcha,blocked);
  assert.equal(result.result_source,expected===2?'shopping':'integrated');
  assert.deepEqual(waits,expected===2?[3500]:[]);
 }
});
