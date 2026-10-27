const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
function harness() {
  const listeners = {}, emitted = [];
  const native = async () => ({ok:true,clone(){return {json:async()=>this.body}},body:null});
  class XHR {} XHR.prototype.open = function() {};
  const c = {module:{exports:{}},window:{fetch:native},XMLHttpRequest:XHR,URL,
    location:{href:'https://search.shopping.naver.com/ns/search?query=test'},
    CustomEvent:class {constructor(type,init){this.type=type;this.detail=init.detail}},
    document:{addEventListener:(n,fn)=>listeners[n]=fn,dispatchEvent:e=>emitted.push(e)}};
  vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../../extension/content/shop-v3-main.js'),'utf8'),c);
  return {c,parse:c.module.exports.products,listeners,emitted};
}
const product = i => ({nvMid:String(100+i),productName:'P'+i,salePrice:1000+i,mallName:'Mall',sourceType:i===2?'AITEMS_FORYOU_AD':'SAS'});
const body = () => [{page:1,slots:[{slotType:'CARD',data:product(1)},{slotType:'HORIZONTAL_LIST',data:Array.from({length:19},(_,i)=>product(i+2))}]}];
test('CARD and HORIZONTAL_LIST preserve screen positions and ad identity',()=>{
 const {parse}=harness(),p=parse(body()); assert.equal(p.length,20);
 assert.equal(p[1].nvMid,'102'); assert.equal(p[1].isAd,true); assert.equal(p[19].rank,20);
 assert.equal(p[0].price,1001); assert.equal(p[0].mallName,'Mall');
});
test('incomplete, wrong page, malformed product and empty response fail closed',()=>{
 const {parse}=harness(); assert.equal(parse({}),null); assert.equal(parse([]),null);
 const b=body();b[0].page=2;assert.equal(parse(b),null);b[0].page=1;
 b[0].slots[1].data.pop();assert.equal(parse(b),null);
 const bad=body();bad[0].slots[0].data.nvMid='';assert.equal(parse(bad),null);
});
test('initial payload forwards only product whitelist, with no session/header data',()=>{
 const {c,listeners,emitted}=harness();c.window._INITIAL_STATE={originQuery:'test',sessionId:'PRIVATE',initProps:{pagedSlot:body()}};
 listeners['rankfree:shop-v3-read']();assert.equal(emitted.length,1);
 assert.equal(emitted[0].detail.includes('PRIVATE'),false);
 c.location.href='https://search.shopping.naver.com/ns/search?query=different';
 listeners['rankfree:shop-v3-read']();assert.equal(emitted.length,1);
});

require('../../extension/shop-serp-tab.js');
test('v3 tab waits for page response and carries products and source to worker',async()=>{
 const event=()=>({addListener(){},removeListener(){}}),store={};let tab,polls=0;
 const rows=harness().parse(body());
 const browser={runtime:{onMessage:event()},storage:{session:{get:async()=>store,set:async v=>Object.assign(store,v)}},
  tabs:{onUpdated:event(),onRemoved:event(),create:async opts=>{
    assert.equal(opts.url,'https://search.shopping.naver.com/ns/search?query=test');
    return tab={id:1,status:'complete',url:opts.url};
  },get:async()=>tab,sendMessage:async()=>++polls===1?{ok:false,pending:true}:{ok:true,products:rows,source:'naver-v3',page:1,result_source:'shopping'}}};
 const result=await new ShopSerpTab({browser,timeoutMs:2500,wait:async()=>{}}).read('test',{v3Top20:true});
 assert.equal(result.ok,true);assert.equal(result.products.length,20);assert.equal(result.source,'naver-v3');assert.equal(result.page,1);
 assert.equal(polls,2);
});
