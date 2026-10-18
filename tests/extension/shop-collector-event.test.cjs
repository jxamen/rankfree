const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../../extension/background.js'), 'utf8');
const start = source.indexOf('  async fetchShopSerp({');
const handler = source.slice(start, source.indexOf('\n  /**', start));

for (const result of [{ok:false,status:403}, {ok:false,status:429}, {ok:false,status:200,captcha:true}, {ok:true,status:200}]) {
  test('background reports actual browser result '+JSON.stringify(result), async () => {
    const events = [];
    const context = vm.createContext({
      shopSerpPolicy: {run:async()=>result},
      getStore:async()=>({token:'token',apiBase:'https://rankfree.kr'}),
      chrome:{storage:{local:{get:async()=>({rfSerpPolicy:{blockedOnResume:true}})}}},
      apiFetch:async(path,options)=>events.push({path,...options}),
    });
    await vm.runInContext('const handlers={'+handler+'};handlers.fetchShopSerp({keyword:"a",analysisId:69});',context);
    assert.equal(events.length,1);
    assert.equal(events[0].path,'/api/ext/shop-keyword/69/collector-event');
    assert.equal(events[0].body.collector,'browser');
    assert.equal(events[0].body.kind,result.captcha?'보안문자':result.ok?'success':String(result.status));
    assert.equal(events[0].body.blocked_on_resume,!result.ok);
  });
}
test('screen delegates delivery to page session; cached and cooldown replies emit no extra events',async()=>{
  for(const [result,sender] of [[{ok:false,status:403},{tab:{id:1}}],[{ok:true,cached:true},null],[{ok:false,cooldown:true},null]]) {
    const context=vm.createContext({lastScreenSerpAt:0,shopSerpPolicy:{run:async()=>result},apiFetch:async()=>{throw Error('unexpected API request');}});
    // No getStore is available: the screen path must work without an API token.
    const actual=await vm.runInContext('const handlers={'+handler+'};handlers.fetchShopSerp({keyword:"a",analysisId:69},'+JSON.stringify(sender)+');',context);
    assert.equal(actual,result);
  }
});

test('console bridge forwards IP and interval to the shared collection policy', () => {
 const source = fs.readFileSync(require('node:path').join(__dirname, '../../extension/content/console-bridge.js'), 'utf8');
 let listener, sent;
 const window = {addEventListener: (type, fn) => {listener = fn;}, postMessage:()=>{}};
 vm.runInNewContext(source, {window, document:{documentElement:{setAttribute:()=>{}}}, chrome:{runtime:{sendMessage:(m,cb)=>{sent=m;cb({ok:true});}}}});
 listener({source:window,data:{source:'rankfree-console',type:'fetchShopSerp',keyword:'test',collectorIp:'192.0.2.1',interval_ms:4200}});
 assert.equal(sent.payload.collectorIp,'192.0.2.1');assert.equal(sent.payload.interval_ms,4200);
});
