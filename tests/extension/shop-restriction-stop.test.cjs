const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const {chromium}=require('playwright');
const root=process.env.RF_TEST_ROOT || '.';
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const context=vm.createContext({Date,setTimeout,clearTimeout,URL});
vm.runInContext(read('extension/shop-serp-pool.js'),context);
const Pool=context.ShopSerpPool;
function storage(){const data={};return {data,get:async keys=>structuredClone(Object.fromEntries((Array.isArray(keys)?keys:[keys]).map(k=>[k,data[k]]))),set:async values=>Object.assign(data,structuredClone(values))};}
const evidence={version:'visible-v1',signal:'visible-challenge',url_pattern:'naver-page',visible:true};
const blocked={ok:false,captcha:true,evidence,observed_at:100};
function setup(store=storage()) {
 const probes=[],resumes=[],readers=new Map();
 const p=new Pool({storage:store,wait:async()=>{},tabFactory:slot=>({slot,peek:async keyword=>{probes.push([slot,keyword]);return readers.get(slot) || blocked;}}),policyFactory:slot=>({resume:async ip=>resumes.push([slot,ip])})});
 return {p,store,probes,resumes,readers};
}
const restriction='<h2>검색 서비스 이용이 제한되었습니다.</h2><p>네이버 검색에서는 프로그램을 이용한 자동 검색을 방지하고자 보안 절차를 거치도록 하고 있습니다.</p><button>제한 해제</button>';
test('attached collapsed restriction gate stops navigation; hidden/script/text/normal pages do not',async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.RF_CHROME ? {executablePath:process.env.RF_CHROME} : {})});
 try {
  const page=await browser.newPage();
  await page.route('**/*',r=>r.fulfill({contentType:'text/html',body:'<body></body>'}));
  await page.goto('https://m.search.naver.com/search.naver?query=test');
  const check=async(html,expected)=>{await page.setContent(html);await page.addScriptTag({content:read('extension/captcha-detector.js')});const r=await page.evaluate(()=>RfCaptcha.inspect());assert.equal(r.captcha,expected);assert.equal(r.evidence.visible,expected);};
  await check(restriction,true);
  await check('<div hidden>'+restriction+'</div>',false);
  await check('<div style="opacity:0">'+restriction+'</div>',false);
  await check('<script>const sample='+JSON.stringify(restriction)+'</script><p>검색 결과 없음</p>',false);
  await check('<p>검색 서비스 이용이 제한되었습니다. 해결 방법 안내</p><button>제한 해제</button>',false);
  await check('<section data-shopping-block>정상 상품 목록</section>'+restriction,true);
  await check(restriction+'<p>'+'상품 정보 '.repeat(1500)+'</p>',true);
  await check('<h2>검색 서비스 이용이 제한되었습니다.</h2><button hidden>제한 해제</button>',false);
  await check(restriction,true);
  const messages=[];await page.exposeFunction('record',m=>messages.push(m));
  await page.evaluate(()=>{window.chrome={runtime:{sendMessage:async m=>{if(m.type==='__rfCollectionContext')return {owned:true};await window.record(m);},onMessage:{addListener(){}}}};});
  await page.addScriptTag({content:read('extension/content/shop-navigation.js')});
  await page.waitForFunction(()=>true);await new Promise(r=>setTimeout(r,50));
  assert.equal(messages.length,1);assert.equal(messages[0].result.captcha,true);assert.equal(messages[0].result.ok,false);
  await page.goto('https://smartstore.naver.com/shop/products/1');await check(restriction,false);
 } finally {await browser.close();}
});
test('visible gate stops all later keywords and analyses, including cached successes and setting reset',async()=>{
 const {p,store,probes}=setup();let searches=0;
 await p.run(1,'blocked',4,async()=>{searches++;return blocked;});
 const cached=async()=>{searches++;return {ok:true,cached:true,result_source:'integrated'};};
 for(const id of [1,2]) {
  await p.clearReduction(id);
  const r=await p.run(id,'cached keyword',4,cached);
  assert.equal(r.ok,false);assert.equal(r.captcha_wait,true);assert.equal(r.blocked_keyword,'blocked');
 }
 assert.equal(searches,1);assert.deepEqual(probes,[[0,'blocked'],[0,'blocked']]);
 assert.equal(Object.keys(store.data.rfSerpCaptchaWaits).length,1);
});
test('worker restart and unreadable/empty/closed tab do not release a gate; confirmed normal page does',async()=>{
 const first=setup();await first.p.run(3,'original',1,async({tab})=>{tab.collectorIp='test-ip';return blocked;});
 const {p,readers,resumes}=setup(first.store);let searches=0;
 for(const reply of [null,{ok:false,retryable:true},{ok:false,tab_closed:true},{ok:true,cached:true,result_source:'shopping'}]) {
  readers.set(0,reply);const r=await p.run(3,'next',1,async()=>{searches++;return {ok:true};});assert.equal(r.captcha_wait,true);
 }
 assert.equal(searches,0);
 readers.set(0,{ok:true,result_source:'shopping',html:'confirmed'});
 const r=await p.run(3,'next',1,async()=>{searches++;return {ok:true,result_source:'integrated',html:'next'};});
 assert.equal(r.ok,true);assert.equal(r.html,'next');assert.equal(searches,1);
 assert.ok(r.captcha_cleared_at);assert.deepEqual(resumes,[[0,'test-ip']]);
});
test('already in-flight success cannot clear another lane gate; queued navigation is guarded',async()=>{
 const {p,store}=setup();store.data['rfSerpFirstDone:4']=true;
 let releaseSuccess,releaseBlocked,started=0,guard;
 const successWait=new Promise(r=>releaseSuccess=r),blockedWait=new Promise(r=>releaseBlocked=r);
 const one=p.run(4,'blocked',2,async()=>{started++;await blockedWait;return blocked;});
 const two=p.run(4,'in-flight',2,async({tab})=>{started++;guard=tab.beforeNavigate;await successWait;return {ok:true,cached:true};});
 while(started<2)await new Promise(setImmediate);
 releaseBlocked();await one;
 assert.equal((await guard()).captcha_wait,true);
 releaseSuccess();assert.equal((await two).captcha_wait,true);
});
test('each blocked document must recover before any new search',async()=>{
 const {p,store,readers}=setup();store.data['rfSerpFirstDone:5']=true;
 let release;const barrier=new Promise(r=>release=r);let started=0;
 const tasks=[0,1].map(i=>p.run(5,'blocked'+i,2,async()=>{started++;await barrier;return blocked;}));
 while(started<2)await new Promise(setImmediate);release();await Promise.all(tasks);
 assert.equal(Object.keys(store.data.rfSerpCaptchaWaits).length,2);
 readers.set(0,{ok:true,result_source:'shopping'});
 assert.equal((await p.run(5,'next',2,async()=>{throw Error('must not search');})).captcha_wait,true);
 readers.set(1,{ok:true,result_source:'integrated'});
 assert.equal((await p.run(5,'next',2,async()=>({ok:true}))).ok,true);
});
test('worker suspension between confirmed recovery and next search preserves the pacing release',async()=>{
 const {p,store,readers}=setup();
 await p.run(6,'blocked',1,async({tab})=>{tab.collectorIp='original-ip';return blocked;});
 readers.set(0,{ok:true,result_source:'shopping'});
 assert.equal(await p.captchaGuard(),null);
 const restarted=setup(store);
 assert.equal((await restarted.p.run(6,'next',1,async()=>({ok:true}))).ok,true);
 assert.deepEqual(restarted.resumes,[[0,'original-ip']]);
});
test('explicit continue cannot bypass a live gate but can retire a closed browser session',async()=>{
 const {p,store}=setup();
 context.chrome={storage:{session:{set:async()=>{}}}};
 await p.run(7,'blocked',1,async()=>blocked);
 p.lanes.get(0).tab.hasTab=async()=>true;
 await p.restart(7);
 assert.equal((await p.run(7,'next',1,async()=>{throw Error('live gate must remain');})).captcha_wait,true);
 p.lanes.get(0).tab.hasTab=async()=>false;
 await p.restart(7);
 assert.equal((await p.run(7,'next',1,async()=>({ok:true}))).ok,true);
 assert.deepEqual(store.data.rfSerpCaptchaWaits,{});
});
