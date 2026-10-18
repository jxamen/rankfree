const { test } = require('node:test');
const assert = require('node:assert/strict');
require('../../extension/shop-serp-policy.js');
function fixture(state = {}) {
  let clock = Date.parse('2026-10-18T12:00:00+09:00');
  const values = new Map(); const data = { rfSerpPolicy: state };
  const policy = new ShopSerpPolicy({
    storage: { get: async () => structuredClone(data), set: async v => Object.assign(data, structuredClone(v)) },
    cache: { get: async k => values.get(k), put: async (k,v) => values.set(k,v) },
    now: () => clock, wait: async ms => { clock += ms; },
  });
  return { policy, data, advance: ms => { clock += ms; }, now: () => clock };
}
test('screen and queue calls serialize and share same-day raw HTML cache; tomorrow refetches', async () => {
  const f = fixture(); let calls = 0; let active = 0; let peak = 0; const times = [];
  const fetcher = async () => { calls++; active++; peak = Math.max(peak,active); times.push(f.now()); await Promise.resolve(); active--; return {ok:true,html:'raw'}; };
  const results = await Promise.all([f.policy.run('키워드',fetcher),f.policy.run('키워드',fetcher),f.policy.run('다른키워드',fetcher)]);
  assert.equal(calls,2); assert.equal(peak,1); assert.equal(results[1].cached,true); assert.ok(times[1]-times[0]>=1600);
  f.advance(86400000); await f.policy.run('키워드',fetcher); assert.equal(calls,3);
});
for (const blocked of [{ok:false,status:403},{ok:false,status:429},{ok:false,status:200,captcha:true}]) {
 test('block pauses 3 minutes without increasing spacing: '+JSON.stringify(blocked), async () => {
  const f=fixture(); let calls=0;
  await f.policy.run('a',async()=>{calls++;return blocked;});
  const r=await f.policy.run('b',async()=>{calls++;return {ok:true};});
  assert.equal(r.cooldown,true); assert.equal(calls,1); assert.equal(f.data.rfSerpPolicy.gap,1600); assert.equal(f.data.rfSerpPolicy.slowEntries,1);
  f.advance(180000); await f.policy.run('a',async()=>{calls++;return {ok:true,html:''};}); assert.equal(calls,2);
 });
}
test('165 successes retain interval without periodic rests', async()=>{
 for(const gap of [1600,3200]) {
  const f=fixture({gap}); const times=[];
  for(let i=0;i<165;i++) await f.policy.run(String(i),async()=>{times.push(f.now());return {ok:true,html:''};});
  assert.equal(f.data.rfSerpPolicy.gap,gap);
  assert.ok(times.slice(1).every((t,i)=>t-times[i]===gap));
 }
});
test('first request after cooldown blocked again renews wait and records resume',async()=>{
 const f=fixture(); let calls=0;
 const block=async()=>{calls++;return {ok:false,status:403};};
 await f.policy.run('a',block); assert.equal(f.data.rfSerpPolicy.blockedOnResume,false);
 f.advance(180000); const repeated = await f.policy.run('b',block);
 assert.equal(repeated.blockedOnResume,true);
 assert.equal(f.data.rfSerpPolicy.blockedOnResume,true);
 assert.equal(f.data.rfSerpPolicy.http403,2); assert.equal(f.data.rfSerpPolicy.gap,1600);
 assert.equal(f.data.rfSerpPolicy.until,f.now()+180000);
 assert.equal((await f.policy.run('c',block)).cooldown,true); assert.equal(calls,2);
});
test('storage failure stops before network',async()=>{
 const p=new ShopSerpPolicy({storage:{get:async()=>{throw Error('disk');}},cache:{get:async()=>undefined}});
 let calls=0; assert.equal((await p.run('a',async()=>{calls++;})).ok,false); assert.equal(calls,0);
});

test('repeated blocks always wait exactly three minutes without a retry limit', async()=>{
 for (const status of [403,429,200]) {
  const f=fixture(); let calls=0;
  const block=async()=>{calls++;return {ok:false,status,captcha:status===200};};
  for(let i=0;i<5;i++) {
   await f.policy.run('a',block);
   assert.equal(calls,i+1); assert.equal(f.data.rfSerpPolicy.until,f.now()+180000);
   f.advance(179999); assert.equal((await f.policy.run('a',block)).cooldown,true);
   assert.equal(calls,i+1); f.advance(1);
  }
  assert.equal(f.data.rfSerpPolicy.gap,1600);
 }
});
