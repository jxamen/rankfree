// Local fixture smoke test: real Chromium extension, bridge, product content and recollect UI.
const {chromium}=require('playwright');
const fs=require('node:fs');
const path=require('node:path');
const os=require('node:os');
const assert=require('node:assert/strict');
(async()=>{
 const profile=fs.mkdtempSync(path.join(os.tmpdir(),'rf-product-browser-'));
 const extension=path.resolve('extension');
 const context=await chromium.launchPersistentContext(profile,{channel:'chromium',headless:true,args:[`--disable-extensions-except=${extension}`,`--load-extension=${extension}`]});
 try {
  const worker=context.serviceWorkers()[0]||await context.waitForEvent('serviceworker');
  // Attach Playwright routing before extension-created tabs navigate (initial navigation bypasses CDP routing).
  await worker.evaluate(() => { const create = chrome.tabs.create.bind(chrome.tabs); chrome.tabs.create = async options => { const tab = await create({url:'about:blank',active:false}); await new Promise(resolve=>setTimeout(resolve,300)); return chrome.tabs.update(tab.id,options); }; });
  let blocked=true,saveFails=true,saves=0,events=[];
  const pre={simpleProductForDetailPage:{name:'파티 상품',seoInfo:{sellerTags:[{text:'할로윈'}]},representImage:{url:'https://img.example/product.jpg'},productDeliveryInfo:{deliveryFeeType:'FREE'}},channel:{channelName:'파티타임'}};
  await context.route('https://smartstore.naver.com/**',route=>route.fulfill({contentType:'text/html; charset=utf-8',body:blocked?'<html><body>검색 서비스 이용이 제한되었습니다</body></html>':`<html><head><title>파티 상품</title></head><body>파티 상품<script>window.__PRELOADED_STATE__=${JSON.stringify(pre)}</script></body></html>`}));
  const blade=fs.readFileSync('resources/views/admin/shop-keyword/show.blade.php','utf8');
  const helpers=blade.slice(blade.indexOf('    function waitExt('),blade.indexOf('    function halt('));
  const ui=blade.slice(blade.indexOf('    const recollect ='),blade.indexOf('\n    (async function boot()')).replace(/\{\{ route\([^}]+\}\}/g,'/status');
  await context.route('http://localhost:8000/**',async route=>{
   const url=new URL(route.request().url());
   if(url.pathname==='/save'){saves++;return route.fulfill({status:saveFails?500:200,json:{ok:!saveFails,message:saveFails?'저장 서버 오류':''}});}
   if(url.pathname==='/event'){events.push(route.request().postDataJSON());return route.fulfill({json:{ok:true}});}
   if(url.pathname==='/status')return route.fulfill({json:{message:events.at(-1)?.message||''}});
   return route.fulfill({contentType:'text/html; charset=utf-8',body:`<html><body><button id="sk-recollect">상품정보 다시 수집</button><span id="sk-product-status" role="status"></span><script>const cfg={productUrl:'https://smartstore.naver.com/partytime/products/4673089244',urls:{productInfo:'/save',collectorEvent:'/event'}};const post=(url,body)=>fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});${helpers}${ui}</script></body></html>`});
  });
  const page=await context.newPage();page.on('pageerror', e=>console.error('page error',e.message));await page.goto('http://localhost:8000/admin/shop-keyword/83');
  await page.waitForFunction(()=>document.documentElement.getAttribute('data-rf-ext')==='1');
  await page.click('#sk-recollect');
  try { await page.waitForFunction(()=>document.querySelector('#sk-product-status').textContent==='네이버 제한 화면 — 풀린 뒤 자동 재시도',null,{timeout:10000}); } catch(e) {console.error('status:',await page.locator('#sk-product-status').textContent());console.error('tabs',await worker.evaluate(()=>chrome.tabs.query({})));console.error('waiters',await worker.evaluate(()=>Array.from(productInfoWaiters.keys())));console.error('docs',await Promise.all(context.pages().filter(p=>p.url().includes('naver')).map(p=>p.locator('body').innerText())));throw e;}
  await page.screenshot({path:'/tmp/rf-product-restriction.png'});
  assert.equal(saves,0);assert.equal(events.at(-1).stage,'product');
  blocked=false;
  await page.evaluate(()=>recollectProduct());
  assert.equal(await page.locator('#sk-product-status').textContent(),'저장 서버 오류');assert.equal(saves,1);
  saveFails=false;
  const request=page.waitForRequest(r=>r.url()==='http://localhost:8000/save');
  await page.evaluate(()=>recollectProduct());
  const info=(await request).postDataJSON().info;
  assert.equal(info.thumbnail_url,'https://img.example/product.jpg');assert.deepEqual(info.seller_tags,['할로윈']);assert.equal(saves,2);
  console.log('PASS: real extension/bridge restriction → retry → save failure shown → image/tags saved; screenshot /tmp/rf-product-restriction.png');
 } finally {await context.close();fs.rmSync(profile,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exitCode=1;});
