const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const code=fs.readFileSync(process.env.RF_TAB_SOURCE || path.join(__dirname,'../../extension/shop-serp-tab.js'),'utf8');
const gate={ok:false,captcha:true,evidence:{visible:true}};
function setup(url='https://m.search.naver.com/search.naver?where=m&query=test&extra=a%2Fb#section') {
 const data={rfSerpTabId:7,rfSerpTabKeyword:'test',rfSerpRecovery:{keyword:'test',current:'test'}};
 const tab={id:7,url,status:'complete'},updates=[];
 const chrome={storage:{session:{get:async keys=>structuredClone(Object.fromEntries(keys.map(k=>[k,data[k]]))),set:async v=>Object.assign(data,structuredClone(v))}},tabs:{get:async()=>({...tab}),sendMessage:async()=>gate,update:async(id,opts)=>{updates.push({id,...opts});Object.assign(tab,opts);}}};
 const context=vm.createContext({chrome,URL,Date});vm.runInContext(code,context);
 return {data,tab,updates,chrome,Reader:context.ShopSerpTab,reader:new context.ShopSerpTab()};
}
test('visible gate opens ct=1 in the same tab while preserving query, parameters, hash and wait',async()=>{
 const f=setup();assert.equal((await f.reader.peek('test')).captcha,true);
 assert.equal(f.updates.length,1);assert.equal(f.updates[0].id,7);assert.equal(f.updates[0].active,true);
 const u=new URL(f.tab.url);assert.equal(u.searchParams.get('ct'),'1');assert.equal(u.searchParams.get('query'),'test');assert.equal(u.searchParams.get('extra'),'a/b');assert.equal(u.hash,'#section');assert.ok(f.data.rfSerpRecovery);
});
test('restart, parallel probes and a redirect stripping ct never reload the challenge repeatedly',async()=>{
 const f=setup();await Promise.all([f.reader.peek('test'),f.reader.peek('test')]);
 assert.equal(f.updates.length,1);
 f.tab.url='https://m.search.naver.com/search.naver?query=test';
 f.reader=new f.Reader();await f.reader.peek('test');assert.equal(f.updates.length,1);
 f.chrome.tabs.sendMessage=async()=>({ok:true,products:[{id:'original'}]});
 assert.equal((await f.reader.peek('test')).ok,true);assert.equal(f.data.rfSerpRecovery,null);assert.equal(f.updates.length,1);
});
for(const url of [
 'https://m.search.naver.com/search.naver?query=test&ct=1',
 'https://captcha.naver.com/challenge?query=test',
 'https://nid.naver.com/nidlogin.login?query=test',
 'https://m.search.naver.com.evil.example/search.naver?query=test',
 'http://m.search.naver.com/search.naver?query=test',
 'https://m.search.naver.com/other?query=test',
 'https://m.search.naver.com/search.naver?query=other',
])test('no navigation for existing ct, other site/page/keyword: '+url,async()=>{
 const f=setup(url);await f.reader.peek('test');assert.equal(f.updates.length,0);
});
test('ct=0 is replaced, never appended as a duplicate or HTML amp entity',async()=>{
 const f=setup('https://search.naver.com/search.naver?query=test&ct=0&ct=2');await f.reader.peek('test');
 assert.deepEqual(new URL(f.tab.url).searchParams.getAll('ct'),['1']);assert.equal(f.tab.url.includes('amp;'),false);
});
for(const result of [{ok:false,captcha:false},{ok:false,captcha:true,evidence:{visible:false}},{ok:true,products:[{id:'normal'}]}])test('normal/unconfirmed pages never navigate '+JSON.stringify(result),async()=>{
 const f=setup();f.chrome.tabs.sendMessage=async()=>result;await f.reader.peek('test');assert.equal(f.updates.length,0);
});
test('navigation failure retains captcha wait without retry loops',async()=>{
 const f=setup();let calls=0;f.chrome.tabs.update=async()=>{calls++;throw Error('tab unavailable');};
 assert.equal((await f.reader.peek('test')).captcha,true);assert.ok(f.data.rfSerpRecovery);
 assert.equal((await new f.Reader().peek('test')).captcha,true);assert.equal(calls,1);
});
