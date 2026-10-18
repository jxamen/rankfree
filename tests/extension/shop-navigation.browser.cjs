const { chromium } = require('playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const nav = fs.readFileSync('extension/content/shop-navigation.js','utf8');
const content = fs.readFileSync('extension/content/content.js','utf8');
const start = content.indexOf('  let serpScrolled = false;');
const reader = content.slice(start,content.indexOf('  // 백그라운드 상품분석 진행률 수신',start));
const slot = (id, rank, sourceType='SAS') => ({data:{channelProductId:id,nvMid:id,rank,sourceType,mallName:'내 상점'}});
const html = (slots, hidden=false) => `<nav><a role="tab" href="https://msearch.shopping.naver.com/search/all?query=cup" onclick="window.events.push({type:'click',at:Date.now()})">쇼핑</a></nav><section data-shopping-block style="${hidden?'display:none':''}"><h2>네이버 쇼핑 인기상품</h2></section><script>var newshopping={};newshopping._INITIAL_STATE=${JSON.stringify({initProps:{pagedSlot:[{slots}]}})};</script>`;
(async()=>{
 const b=await chromium.launch({...(process.env.RF_CHROMIUM_PATH ? {executablePath:process.env.RF_CHROMIUM_PATH} : {channel:'chrome'}),headless:true,timeout:15000});
 const p=await b.newPage();p.setDefaultTimeout(5000);
 try {
  const evidence=[];
  await p.addInitScript(() => {
   window.events=[];window.target={product_id:'mine',id_kind:'channel'};
   window.scrollBy=()=>window.events.push({type:'scroll'});
   window.chrome={runtime:{onMessage:{addListener:f=>window.navListener=f},sendMessage:async m=>{
    if(m.type==='__rfSerpTarget')return window.target;
    window.navResult=m.result;window.events.push({type:'result',at:Date.now()});
   }}};
  });
  await p.route('https://msearch.shopping.naver.com/**',r=>r.fulfill({contentType:'text/html; charset=utf-8',body:'<body style="height:4000px"><div data-product-list>shopping products</div></body>'}));
  for(const [name,slots,integrated,hidden] of [
   ['rank one',[slot('ad',1,'AD'),slot('mine',1)],true],
   ['rank five',[slot('other',1),slot('mine',5)],true],
   ['rank six',[slot('mine',6)],true],
   ['ad only',[slot('mine',1,'AD'),slot('other',1)],true],
   ['super point',[slot('mine',1,'SUPER_POINT')],true],
   ['hidden block',[slot('mine',1)],false,true],
   ['no block',[],false,true],
   ['empty block',[],true],
  ]) {
   await p.route('https://m.search.naver.com/**',r=>r.fulfill({contentType:'text/html; charset=utf-8',body:html(slots,hidden)}));
   await p.goto('https://m.search.naver.com/search.naver?where=m&query=cup&case='+encodeURIComponent(name)+'#rfnav');
   await p.evaluate(()=>{
    // Exercise independent readiness and menu visibility gates before the dwell.
    const a=document.querySelector('nav a');a.style.display='none';
    Object.defineProperty(document,'readyState',{configurable:true,get:()=>window.ready||'loading'});
    setTimeout(()=>{window.ready='complete';a.style.display='';window.readyAt=Date.now();},350);
    Math.random=()=>0;
   });
   const began=Date.now();
   await p.evaluate(nav);
   if(integrated) {
    assert.match(p.url(),/#rfnav$/);
    const state=await p.evaluate(()=>({r:window.navResult,events:window.events,at:window.readyAt}));
    assert.equal(state.r.result_source,'integrated');
    assert.equal(state.at,undefined); // decision while still loading, without dwell
    assert.equal(state.events.some(e=>e.type==='scroll'||e.type==='click'),false);
    evidence.push({name,screen:'integrated',elapsedMs:Date.now()-began, decidedBeforeComplete:state.at===undefined});
   } else {
    await p.waitForURL('**/search/all?query=cup#rfserp');
    evidence.push({name,screen:'shopping'});
   }
   await p.unroute('https://m.search.naver.com/**');
  }
  // Loaded shopping DOM: wait for complete + visible products, scroll exactly once,
  // then both ordinary reads and recovery probes share that preparation.
  await p.goto('https://msearch.shopping.naver.com/search/all?query=cup#rfserp');
  const sequence=await p.evaluate(async reader=>{
   const events=[];window.scrollBy=()=>events.push('scroll');
   window.serpProducts=()=>{events.push('read');return {products:[{id:'123',rank:1}]};};
   Object.defineProperty(document,'readyState',{configurable:true,get:()=>window.ready||'loading'});
   window.ready='loading';const list=document.querySelector('[data-product-list]');list.style.display='none';
   setTimeout(()=>{events.push('complete');window.ready='complete';},100);
   setTimeout(()=>{events.push('visible');list.style.display='';},350);
   window.chrome={runtime:{onMessage:{addListener:f=>window.peek=f},sendMessage:async m=>{events.push('send');window.result=m.result;}}};
   await eval(reader+'runSerpRead()');
   await new Promise(resolve=>window.peek({type:'__rfSerpPeek'},null,resolve));
   return {events,result:window.result};
  },reader);
  assert.deepEqual(sequence.events,['read','scroll','complete','visible','read','send','read']);
  assert.equal(sequence.result.result_source,'shopping');evidence.push({shoppingRead:sequence});
  // Reproduce the reported 66/38 discrepancy with real DOM updates.
  await p.setContent('<div id="sk-sum-exposed">66</div><div id="sk-sum-checked">103</div><p id="sk-search-judged"></p><table><tbody id="sk-exposed-body"><tr data-item="999"><td>38</td></tr></tbody></table><button class="sk-copy" data-copy="exposed"></button>');
  const blade=fs.readFileSync('resources/views/admin/shop-keyword/show.blade.php','utf8');
  const progress=blade.slice(blade.indexOf('    function renderProgress(d)'),blade.indexOf('    // ── 상태 필터'));
  const snapshot=blade.slice(blade.indexOf('    let exposedNo ='),blade.indexOf('    function addAdBadge('));
  const ui=await p.evaluate(({progress,snapshot})=>{
   const fill=null,label=null,cfg={th:5},extMode=true,stopped=false;let lastD;
   const esc=s=>String(s),shopUrl=kw=>'https://example.com/?q='+encodeURIComponent(kw);
   window.__skCopyData={};
   return eval(progress+snapshot+`
    const items=Array.from({length:66},(_,i)=>({id:i+1,keyword:'가면 '+i,rank:1,result_source:'integrated'}));
    renderProgress({total:1668,checked:103,exposed:66,exposed_items:items});
    renderProgress({total:1668,checked:103,exposed:66,exposed_items:items});
    const first={rows:document.querySelectorAll('tr[data-item]').length,count:document.getElementById('sk-sum-exposed').textContent,copy:window.__skCopyData.exposed.length};
    renderProgress({total:1668,checked:103,exposed:66,exposed_items:items.slice(0,38)});
    ({first,after:{rows:document.querySelectorAll('tr[data-item]').length,count:document.getElementById('sk-sum-exposed').textContent}});
   `);
  },{progress,snapshot});
  assert.deepEqual(ui,{first:{rows:66,count:'66',copy:66},after:{rows:38,count:'38'}});evidence.push({summaryListSync:ui});
  // A real Naver page exercises its current rendered block and embedded JSON.
  if(process.env.RF_LIVE_NAVER==='1') {
   await p.goto('https://m.search.naver.com/search.naver?where=m&query='+encodeURIComponent('비타민c 유유제약')+'#rfnav',{waitUntil:'load',timeout:25000});
   const target=await p.evaluate(nav=>{
    const visible=el=>!!el&&el.getClientRects().length>0&&getComputedStyle(el).visibility!=='hidden'&&getComputedStyle(el).display!=='none';
    const a=nav.indexOf('  function shoppingProducts()');const z=nav.indexOf('  function topFive(',a);
    const products=eval(nav.slice(a,z)+'shoppingProducts()');
    const product=products.find(p=>!p.isAd&&p.rank>=1&&p.rank<=5);
    if(!product)throw Error('live top-five product unavailable');
    window.target={product_id:product.channelProductId,id_kind:'channel'};return window.target;
   },nav);
   await p.evaluate(nav);
   const result=await p.evaluate(()=>({r:window.navResult,events:window.events}));
   assert.equal(result.r.result_source,'integrated');assert.equal(result.events.some(e=>e.type==='scroll'||e.type==='click'),false);
   evidence.push({liveNaver:{target,screen:result.r.result_source,products:result.r.products.length}});
  }
  fs.writeFileSync('/tmp/rf-nav-browser-evidence.json',JSON.stringify(evidence,null,2));console.log(JSON.stringify(evidence));
 }finally{await b.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
