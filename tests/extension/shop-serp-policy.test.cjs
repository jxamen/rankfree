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
 test('block pauses 30 minutes and increases spacing: '+JSON.stringify(blocked), async () => {
  const f=fixture(); let calls=0;
  await f.policy.run('a',async()=>{calls++;return blocked;});
  const r=await f.policy.run('b',async()=>{calls++;return {ok:true};});
  assert.equal(r.cooldown,true); assert.equal(calls,1); assert.equal(f.data.rfSerpPolicy.gap,3200); assert.equal(f.data.rfSerpPolicy.slowEntries,1);
  f.advance(1800000); await f.policy.run('a',async()=>{calls++;return {ok:true,html:''};}); assert.equal(calls,2);
 });
}
test('20 consecutive successes reduce interval gradually, never below floor', async()=>{
 const f=fixture({gap:1700});
 for(let i=0;i<40;i++) await f.policy.run(String(i),async()=>({ok:true,html:''}));
 assert.equal(f.data.rfSerpPolicy.gap,1600);
});
test('storage failure stops before network',async()=>{
 const p=new ShopSerpPolicy({storage:{get:async()=>{throw Error('disk');}},cache:{get:async()=>undefined}});
 let calls=0; assert.equal((await p.run('a',async()=>{calls++;})).ok,false); assert.equal(calls,0);
});
