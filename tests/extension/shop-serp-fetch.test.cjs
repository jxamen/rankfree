const {test}=require('node:test'), assert=require('node:assert/strict');
const vm=require('node:vm'), fs=require('node:fs');
const ctx={module:{exports:{}},console};
vm.runInNewContext(fs.readFileSync('extension/shop-serp-fetch.js','utf8'),ctx);
const F=ctx.module.exports;
const card=(n,extra={})=>({nvMid:String(n),channelProductId:String(n),sourceType:'SAS',cardType:'PRODUCT_CARD',productName:'P'+n,rank:999,trackingToken:'must-not-forward',...extra});
const state=()=>({areaCode:'shp_gui',initProps:{pagedSlot:Array.from({length:5},(_,p)=>({page:p+1,slots:[{slotType:'HORIZONTAL_LIST',data:Array.from({length:14},(_,i)=>card(p*14+i+1))}]}))}});
const html=s=>'<html><script>naver.search.ext.newshopping.shopping._INITIAL_STATE = '+JSON.stringify(s)+';</script></html>';
test('all 5 pages / 70 cards; ad variants; rank across page boundaries',()=>{
 const s=state(); s.initProps.pagedSlot[0].slots[0].data[0].sourceType='AD';
 s.initProps.pagedSlot[0].slots[0].data[1].sourceType='AITEMS_FORYOU_AD';
 s.initProps.pagedSlot[1].slots[0].data[0].cardType='AD_CARD';
 const p=F.parse(html(s));assert.equal(p.items.length,70);assert.equal(p.pages,5);
 assert.equal(F.rank(p,{product_id:'70'}).rank,67);
 assert.equal(F.rank(p,{product_id:'15'}).ad,true);
 assert.equal(F.rank(p,{product_id:'15'}).rank,0);
 assert.equal(p.items[69].raw_rank,999);assert.equal(p.items[69].screen_rank,70);
 assert.equal(F.html(p).includes('must-not-forward'),false);
 const normalized=F.parse(F.html(p));assert.equal(normalized.items[69].raw_rank,67);
});
test('CARD, escaped braces, bracket marker and malformed inputs',()=>{
 const s=state();s.initProps.pagedSlot[0].slots=[{slotType:'CARD',data:card(1,{productName:'a } \\" {'})}];
 assert.equal(F.parse(html(s).replace('.shopping.','["shopping"].')).status,'ok');
 s.initProps.pagedSlot[2].page=4;assert.equal(F.parse(html(s)).status,'parse_failed');
 assert.equal(F.parse(html(state()).slice(0,-80)).status,'parse_failed');
 assert.equal(F.parse('<html>no results</html>').status,'area_absent');
 assert.equal(F.parse('<html>newshopping</html>').status,'parse_failed');
});
test('mobile and PC remain separate; requests never use shopping search',async()=>{
 const urls=[];const r=await F.collect('keyword',{product_id:'70'},{wait:async()=>{},read:async u=>{urls.push(u);return {ok:true,status:200,text:html(state())}}});
 assert.equal(r.ok,true);assert.equal(r.device_results.mobile.rank,70);assert.equal(r.device_results.pc.rank,70);
 assert.equal(urls.length,2);assert.equal(new URL(urls[0]).host,'m.search.naver.com');assert.equal(new URL(urls[1]).host,'search.naver.com');
});
test('HTTP restriction and security page stop before PC without zero rank',async()=>{
 for(const response of [{ok:false,status:429,text:''},{ok:true,status:200,text:'<html><title>NAVER 보안 확인</title></html>'}]){
  let count=0;const r=await F.collect('k',{}, {wait:async()=>{},read:async()=>{count++;return response}});
  assert.equal(count,1);assert.equal(r.ok,false);assert.equal(r.fetch_stopped,true);assert.equal(r.html,undefined);
 }
});
test('durable hold prevents future requests and malformed HTML never falls back',async()=>{
 let saved,calls=0;
 const options={wait:async()=>{},stop:async r=>saved=r,read:async()=>{if(saved)return saved;calls++;return {ok:false,status:403,text:''}}};
 await F.run('a',{},options);await F.run('b',{},options);assert.equal(calls,1);
 let malformedCalls=0;const r=await F.collect('k',{}, {wait:async()=>{},read:async()=>{malformedCalls++;return {ok:true,status:200,text:'<html>newshopping</html>'}}});
 assert.equal(malformedCalls,1);assert.equal(r.ok,false);
});
