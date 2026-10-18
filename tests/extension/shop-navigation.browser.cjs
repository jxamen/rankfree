const { chromium } = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const nav = fs.readFileSync('extension/content/shop-navigation.js','utf8');
const content = fs.readFileSync('extension/content/content.js','utf8');
const start = content.indexOf('  async function runSerpRead()');
const reader = content.slice(start,content.indexOf('  // 백그라운드 상품분석 진행률 수신',start));
(async()=>{
 const b=await chromium.launch({...(process.env.RF_CHROMIUM_PATH ? {executablePath:process.env.RF_CHROMIUM_PATH} : {channel:'chrome'}),headless:true,timeout:15000});
 const p=await b.newPage();
 p.setDefaultTimeout(5000);
 p.on('pageerror', e=>console.error(e.message));
 try {
  const evidence=[];
  let clicks=0;
  await p.exposeFunction('markMenuClick',()=>clicks++);
  await p.addInitScript(() => { window.chrome={runtime:{onMessage:{addListener:f=>window.navListener=f},sendMessage:async m=>{window.navResult=m.result;}}}; });
  await p.route('https://m.search.naver.com/**',r=>r.fulfill({contentType:'text/html; charset=utf-8',body:'<nav><a role="tab" href="https://msearch.shopping.naver.com/search/all?query=cup" onclick="window.markMenuClick()">쇼핑</a></nav>'}));
  await p.route('https://msearch.shopping.naver.com/**',r=>r.fulfill({contentType:'text/html; charset=utf-8',body:'<body style="height:4000px">shopping products</body>'}));
  await p.goto('https://m.search.naver.com/search.naver?where=m&query=cup#rfnav');
  await p.evaluate(nav);
  await p.waitForURL('**/search/all?query=cup#rfserp');
  assert.equal(clicks,1);
  const sequence=await p.evaluate(async reader=>{
   const events=[];window.scrollBy=()=>events.push('scroll');
   window.domNextData=()=>{events.push('read');return {products:[{id:'123',rank:1}]};};
   window.chrome={runtime:{sendMessage:async m=>{events.push('send');window.result=m.result;}}};
   await eval(reader+'runSerpRead()');return {events,result:window.result};
  },reader);
  assert.deepEqual(sequence.events,['scroll','read','send']);assert.equal(sequence.result.products[0].rank,1);
  evidence.push({fixture:sequence});
  await p.route('https://m.search.naver.com/**',r=>r.fulfill({contentType:'text/html; charset=utf-8',body:'<body>보안문자 입력<nav><a role="tab" href="https://msearch.shopping.naver.com/search/all?query=cup">쇼핑</a></nav></body>'}));
  await p.goto('https://m.search.naver.com/search.naver?where=m&query=cup#rfnav');
  await p.evaluate(nav);
  assert.equal((await p.evaluate(()=>window.navResult)).captcha,true);
  assert.match(p.url(),/#rfnav$/);
  await p.evaluate(()=>{document.body.firstChild.textContent='사람이 해결함';window.navListener({type:'__rfNavResume'},null,()=>{});});
  await p.waitForURL('**/search/all?query=cup#rfserp');
  evidence.push({humanRecovery:p.url()});
  await p.route('https://m.search.naver.com/**',r=>r.fulfill({contentType:'text/html; charset=utf-8',body:'<nav>메뉴 없음</nav>'}));
  await p.goto('https://m.search.naver.com/search.naver?where=m&query=cup#rfnav');
  await p.evaluate(nav);
  assert.equal((await p.evaluate(()=>window.navResult)).ok,false);
  assert.match(p.url(),/#rfnav$/);
  evidence.push({missingMenu:'stopped without direct navigation'});
  fs.writeFileSync('/tmp/rf-nav-browser-evidence.json',JSON.stringify(evidence,null,2));
  console.log(JSON.stringify(evidence));
 }finally{await b.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
