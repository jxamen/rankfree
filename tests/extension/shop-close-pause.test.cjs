const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
require('../../extension/shop-serp-pool.js');
require('../../extension/shop-serp-tab.js');
const source=fs.readFileSync('extension/background.js','utf8');
function storage(data={}) {return {data,get:async keys=>keys===null?{...data}:Object.fromEntries((Array.isArray(keys)?keys:[keys]).map(k=>[k,data[k]])),set:async values=>Object.assign(data,values)};}
function fixture(sessionData) {
 const local=storage(),session=storage(sessionData),events=[];let removed;
 const c=vm.createContext({console,chrome:{storage:{local,session},tabs:{onRemoved:{addListener:f=>removed=f}}},getStore:async()=>({token:'t'}),apiFetch:async(path,args)=>{events.push({path,...args});return {ok:true};}});
 vm.runInContext(source.slice(source.indexOf('let rfCloseWork'),source.indexOf('\nconst DEFAULT_API_BASE')),c);
 return {local,session,events,close:async id=>{removed(id);await vm.runInContext('rfCloseWork',c);}};
}
test('idle captcha tab close survives worker restart, pauses server once, ignores unrelated and intentional closes',async()=>{
 const f=fixture({rfSerpTabId:7,rfSerpAnalysisId:42,'rfSerpIntentional:9':true,'rfSerpTabId:lane:1':9,'rfSerpAnalysisId:lane:1':99});
 await f.close(123);await f.close(9);assert.equal(f.events.length,0);
 await f.close(7);assert.deepEqual(Array.from(f.local.data['rfSerpClosed:42']),[0,1,2,3]);
 assert.equal(f.events.length,1);assert.equal(f.events[0].body.kind,'tab_closed');
 let executed=0;const restarted=new ShopSerpPool({storage:f.local,tabFactory:()=>({}),policyFactory:()=>({})});
 const result=await restarted.run(42,'captcha',4,async()=>{executed++;return {ok:true};});
 assert.equal(result.paused,true);assert.equal(executed,0);
 const tab=new ShopSerpTab({browser:{},wait:async()=>{}});tab.isClosed=async()=>true;
 assert.equal((await tab.peek('captcha')).tab_closed,true);await tab.focus('captcha');
});
test('parallel close removes only that slot; last close pauses; explicit restart restores lanes',async()=>{
 const f=fixture({rfSerpTabId:7,rfSerpAnalysisId:42,'rfSerpTabId:lane:1':8,'rfSerpAnalysisId:lane:1':42});
 await f.close(7);assert.deepEqual(Array.from(f.local.data['rfSerpClosed:42']),[0]);assert.equal(f.events.length,0);
 const p=new ShopSerpPool({storage:f.local,tabFactory:slot=>({slot}),policyFactory:()=>({})});
 const used=[];await Promise.all(['next','later'].map(kw=>p.run(42,kw,2,async({tab})=>{used.push(tab.slot);await new Promise(r=>setTimeout(r,5));return {ok:true};})));assert.deepEqual(used,[1,1]);
 await f.close(8);assert.equal(f.events.length,1);
 assert.equal((await p.run(42,'next',2,async()=>{throw Error('must not run')})).paused,true);
 await f.local.set({'rfSerpClosed:42':[]});
 assert.equal((await p.run(42,'resume',2,async()=>({ok:true}))).ok,true);
});
test('closing during pacing prevents queued tab creation',async()=>{
 let opened=0;const store=storage({rfSerpLastOpened:Date.now()});let closed=false;
 const tab=new ShopSerpTab({browser:{storage:{session:store},tabs:{create:async()=>{opened++;}}},wait:async()=>{closed=true;}});
 tab.isClosed=async()=>closed;
 await assert.rejects(tab.create({}),/수집 탭 닫힘/);assert.equal(opened,0);
});
test('only explicit server restart releases fully closed analysis; failed close delivery stays stopped',async()=>{
 const start=source.indexOf('  async fetchShopSerp({');const handler=source.slice(start,source.indexOf('\n  /**',start));
 for(const [status,ack,expected] of [['paused',true,0],['checking',true,1],['checking',false,0]]) {
  const local=storage({'rfSerpClosed:42':[0,1,2,3],'rfSerpCloseAck:42':ack});let runs=0;
  const ctx=vm.createContext({rfCloseWork:Promise.resolve(),lastScreenSerpAt:0,chrome:{storage:{local}},getStore:async()=>({token:'t'}),apiFetch:async()=>({ok:true,json:{data:{status}}}),shopSerpPool:{run:async()=>{runs++;return {ok:false};}}});
  await vm.runInContext('const handlers={'+handler+'};handlers.fetchShopSerp({analysisId:42,keyword:"resume"},{tab:{id:1}})',ctx);
  assert.equal(runs,expected);
 }
});
test('extension marks its own removal before Chrome dispatches onRemoved',async()=>{
 const store=storage();let removed=false;
 const ctx=vm.createContext({chrome:{storage:{session:store},tabs:{remove:async id=>{assert.equal(store.data['rfSerpIntentional:'+id],true);removed=true;}}}});
 const code=source.slice(source.indexOf('async function removeTab('),source.indexOf('\n}',source.indexOf('async function removeTab('))+2);
 await vm.runInContext(code+';removeTab(7)',ctx);assert.equal(removed,true);
});
