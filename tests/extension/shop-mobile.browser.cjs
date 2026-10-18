const {chromium}=require('playwright');
const fs=require('node:fs'),assert=require('node:assert/strict');
const script=fs.readFileSync('extension/content/content.js','utf8');
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true});
 try {
  const page=await browser.newPage();
  await page.addInitScript(()=>{
   window.results=[];window.listeners=[];
   window.chrome={runtime:{onMessage:{addListener:f=>listeners.push(f)},sendMessage:async m=>results.push(m)}};
  });
  const item=(id,extra={})=>({item:{id,productTitle:'마녀모자',mallName:'상점',lowPrice:1000,mallProductId:id,rank:1,...extra}});
  const data={props:{pageProps:{dehydratedState:{queries:[{state:{data:{unrelated:true}}},{state:{data:{shoppingResult:{products:[item('11',{adId:'ad-1'}),item('22',{rank:2})]}}}}]}}}};
  let body='<div class="mobile_changed_cards">상품 목록</div><script type="application/json" nonce="test" id="__NEXT_DATA__">'+JSON.stringify(data)+'</script>';
  await page.route('https://msearch.shopping.naver.com/**',route=>route.fulfill({body,contentType:'text/html; charset=utf-8'}));
  await page.goto('https://msearch.shopping.naver.com/search/all?query=hat#rfserp');
  await page.evaluate(script);
  await page.waitForFunction(()=>results.some(r=>r.type==='__rfSerpRead'));
  let result=await page.evaluate(()=>results.find(r=>r.type==='__rfSerpRead').result);
  assert.equal(result.ok,true);assert.equal(result.products.length,2);assert.equal(result.products[0].isAd,true);assert.equal(result.products[1].isAd,false);assert.equal(result.products[1].rank,2);
  body='<div data-shp-contents-id="33"><a href="https://smartstore.naver.com/a/products/44">상품</a><span aria-label="광고">광고</span></div>';
  await page.reload();await page.evaluate(script);
  await page.waitForFunction(()=>results.some(r=>r.type==='__rfSerpRead'));
  result=await page.evaluate(()=>results.find(r=>r.type==='__rfSerpRead').result);
  assert.equal(result.products[0].channelProductId,'44');assert.equal(result.products[0].nvMid,'33');assert.equal(result.products[0].isAd,true);
  await page.evaluate(()=>document.body.insertAdjacentHTML('afterbegin','<p>보안문자 입력</p>'));
  result=await page.evaluate(()=>new Promise(resolve=>{for(const fn of listeners) fn({type:'__rfSerpPeek'},null,resolve);}));
  assert.equal(result.ok,false);assert.equal(result.captcha,true);
  console.log('PASS: mobile hydration across query arrays, changed CSS, ad/organic identity, DOM fallback, captcha over stale products');
  if(process.env.RF_LIVE_NAVER==='1') {
   await page.unrouteAll();
   await page.goto('https://msearch.shopping.naver.com/search/all?query='+encodeURIComponent('마녀모자 할로윈 신사 카우보이'),{waitUntil:'domcontentloaded',timeout:20000});
   const live=await page.evaluate(()=>({url:location.href,title:document.title,next:!!document.getElementById('__NEXT_DATA__'),cards:document.querySelectorAll('[data-shp-contents-id]').length}));
   console.log('LIVE',JSON.stringify(live));
  }
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
