const { chromium } = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const nav = fs.readFileSync('extension/content/shop-navigation.js','utf8');
const content = fs.readFileSync('extension/content/content.js','utf8');
const start = content.indexOf('  async function runSerpRead()');
const reader = content.slice(start,content.indexOf('  // 백그라운드 상품분석 진행률 수신',start));
(async()=>{
 const b=await chromium.launch({channel:'chrome',headless:true});
 const p=await b.newPage();
 try {
  const evidence=[];
  await p.route('https://m.search.naver.com/**',r=>r.fulfill({contentType:'text/html',body:'<nav><a role="tab" href="https://msearch.shopping.naver.com/search/all?query=cup" onclick="sessionStorage.setItem(\'clicked\',\'1\')">쇼핑</a></nav>'}));
  await p.route('https://msearch.shopping.naver.com/**',r=>r.fulfill({contentType:'text/html',body:'<body style="height:4000px">shopping products</body>'}));
  await p.goto('https://m.search.naver.com/search.naver?where=m&query=cup#rfnav');
  await p.evaluate(nav);
  await p.waitForURL('**/search/all?query=cup#rfserp');
  const sequence=await p.evaluate(async reader=>{
   const events=[];window.scrollBy=()=>events.push('scroll');
   window.domNextData=()=>{events.push('read');return {products:[{id:'123',rank:1}]};};
   window.chrome={runtime:{sendMessage:async m=>{events.push('send');window.result=m.result;}}};
   await eval(reader+'runSerpRead()');return {events,result:window.result};
  },reader);
  assert.deepEqual(sequence.events,['scroll','read','send']);assert.equal(sequence.result.products[0].rank,1);
  evidence.push({fixture:sequence});
  await p.unrouteAll();
  await p.goto('https://m.search.naver.com/search.naver?where=m&query=텀블러#rfnav',{waitUntil:'domcontentloaded'});
  const menu=await p.getByRole('tab',{name:'쇼핑',exact:true}).getAttribute('href');
  await p.evaluate(nav);await p.waitForURL('https://msearch.shopping.naver.com/**');await p.waitForLoadState('domcontentloaded');
  const result=await p.evaluate(async reader=>{
   window.domNextData=()=>null;window.chrome={runtime:{sendMessage:async m=>{window.result=m.result;}}};
   await eval(reader+'runSerpRead()');return window.result;
  },reader);
  evidence.push({live:{menu,url:p.url(),result}});
  assert.equal(result.ok,false);assert.equal(result.captcha,true);
  fs.writeFileSync('/tmp/rf-nav-browser-evidence.json',JSON.stringify(evidence,null,2));
  console.log(JSON.stringify(evidence));
 }finally{await b.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
