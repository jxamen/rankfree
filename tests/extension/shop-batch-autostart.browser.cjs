// Real MV3 worker + real five-second timers. Only queue responses and the SERP
// boundary are local fixtures: no production starts, saves, or CAPTCHA solving.
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const http=require('node:http');
const fs=require('node:fs');
const os=require('node:os');
const path=require('node:path');
(async()=>{
 let available=false,reads=0,saves=0,analysis=87;
 const server=http.createServer((req,res)=>{
  res.setHeader('Content-Type','application/json');
  res.setHeader('Access-Control-Allow-Origin','*');
  res.setHeader('Access-Control-Allow-Headers','authorization,content-type');
  if(req.method==='OPTIONS'){res.end();return;}
  const u=new URL(req.url,'http://localhost');
  if(u.pathname.endsWith('/check-queue')) {reads++;res.end(JSON.stringify({data:{analysis_id:analysis,parallel_tabs:1,items:available?[{item_id:analysis,keyword:'다음 분석'}]:[]}}));}
  else if(u.pathname.endsWith('/check-html')) {saves++;available=false;res.end(JSON.stringify({status:'target_reached'}));}
  else {if(u.pathname.endsWith('/collector-event')){let body='';req.on('data',b=>body+=b);req.on('end',()=>console.log('EVENT',body));}res.end(JSON.stringify({data:{items:[]}}));}
 });
 await new Promise(r=>server.listen(0,'127.0.0.1',r));
 const profile=fs.mkdtempSync(path.join(os.tmpdir(),'rf-batch-browser-'));
 const extension=path.resolve(process.env.RF_EXTENSION_PATH || 'extension');
 let browser;
 try {
  browser=await chromium.launchPersistentContext(profile,{channel:'chromium',headless:true,args:[`--disable-extensions-except=${extension}`,`--load-extension=${extension}`]});
  const worker=browser.serviceWorkers()[0]||await browser.waitForEvent('serviceworker');
  console.log('worker started');
  await worker.evaluate(async base=>{
   await chrome.storage.local.set({rfToken:'local-fixture',rfApiBase:base});
   globalThis.batchSearches=[];
   handlers.fetchShopSerp=async args=>{batchSearches.push({analysisId:args.analysisId,at:Date.now()});return {ok:true,html:'fixture'};};
  },`http://127.0.0.1:${server.address().port}`);
  const until=async predicate=>{const start=Date.now();while(!predicate()){if(Date.now()-start>8000)throw Error('poll exceeded eight seconds');await new Promise(r=>setTimeout(r,100));}};
  await worker.evaluate(()=>drainShopKeywordCheckQueue());
  console.log('initial reads',reads);
  await until(()=>reads>0); // Empty queue, no detail page or bridge.
  let start=Date.now();available=true;await until(()=>saves===1);const first=Date.now()-start;console.log('first collected',first);
  // Leave the worker idle longer than MV3's ordinary 30-second idle timeout.
  await new Promise(r=>setTimeout(r,35000));
  console.log('idle finished',reads);
  const before=reads;analysis=88;start=Date.now();available=true;await until(()=>saves===2);const second=Date.now()-start;
  const searches=await worker.evaluate(()=>batchSearches);
  assert.deepEqual(searches.map(s=>s.analysisId),[87,88]);
  assert.ok(first<6500&&second<6500);assert.ok(reads>before);
  const tabs=await browser.pages();assert.ok(tabs.every(p=>!p.url().includes('/shop-keyword')));
  console.log(JSON.stringify({result:'PASS',first_ms:first,after_idle_ms:second,queue_reads:reads,searches:searches.length,detail_pages:0}));
 } finally {if(browser)await browser.close();server.closeAllConnections();await new Promise(r=>server.close(r));fs.rmSync(profile,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exitCode=1;});
