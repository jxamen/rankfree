const { test } = require('node:test');
const assert = require('node:assert/strict');
require('../../extension/shop-serp-policy.js');
function fixture(state = {}) {
  let clock = Date.parse('2026-10-18T12:00:00+09:00');
  const values = new Map(); const data = { rfSerpPolicy: state };
  const policy = new ShopSerpPolicy({
    storage: { get: async () => structuredClone(data), set: async v => Object.assign(data, structuredClone(v)) },
    cache: { get: async k => values.get(k), put: async (k,v) => values.set(k,v) },
    random: () => 0, now: () => clock, wait: async ms => { clock += ms; },
  });
  return { policy, data, advance: ms => { clock += ms; }, now: () => clock };
}
test('screen and queue calls serialize and share same-day raw HTML cache; tomorrow refetches', async () => {
  const f = fixture(); let calls = 0; let active = 0; let peak = 0; const times = [];
  const fetcher = async () => { calls++; active++; peak = Math.max(peak,active); times.push(f.now()); await Promise.resolve(); active--; return {ok:true,html:'raw'}; };
  const results = await Promise.all([f.policy.run('키워드',fetcher),f.policy.run('키워드',fetcher),f.policy.run('다른키워드',fetcher)]);
  assert.equal(calls,2); assert.equal(peak,1); assert.equal(results[1].cached,true); assert.ok(times[1]-times[0]>=2000);
  f.advance(86400000); await f.policy.run('키워드',fetcher); assert.equal(calls,3);
});
for (const blocked of [{ok:false,status:403},{ok:false,status:429},{ok:false,status:200,captcha:true}]) {
 test('block pauses 3 minutes and adds 300ms spacing: '+JSON.stringify(blocked), async () => {
  const f=fixture(); let calls=0;
  await f.policy.run('a',async()=>{calls++;return blocked;});
  const r=await f.policy.run('b',async()=>{calls++;return {ok:true};});
  assert.equal(r.cooldown,true); assert.equal(calls,1); assert.equal(f.data.rfSerpPolicy.gap,2300); assert.equal(f.data.rfSerpPolicy.slowEntries,1);
  f.advance(180000); await f.policy.run('a',async()=>{calls++;return {ok:true,html:''};}); assert.equal(calls,blocked.captcha?1:2);
 });
}
test('165 successes retain interval without periodic rests', async()=>{
 for(const gap of [2000,3200]) {
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
 assert.equal(f.data.rfSerpPolicy.http403,2); assert.equal(f.data.rfSerpPolicy.gap,2600);
 assert.equal(f.data.rfSerpPolicy.until,f.now()+180000);
 assert.equal((await f.policy.run('c',block)).cooldown,true); assert.equal(calls,2);
});
test('storage failure stops before network',async()=>{
 const p=new ShopSerpPolicy({storage:{get:async()=>{throw Error('disk');}},cache:{get:async()=>undefined}});
 let calls=0; assert.equal((await p.run('a',async()=>{calls++;})).ok,false); assert.equal(calls,0);
});

test('repeated blocks always wait exactly three minutes without a retry limit', async()=>{
 for (const status of [403,429]) {
  const f=fixture(); let calls=0;
  const block=async()=>{calls++;return {ok:false,status,captcha:status===200};};
  for(let i=0;i<5;i++) {
   await f.policy.run('a',block);
   assert.equal(calls,i+1); assert.equal(f.data.rfSerpPolicy.until,f.now()+180000);
   f.advance(179999); assert.equal((await f.policy.run('a',block)).cooldown,true);
   assert.equal(calls,i+1); f.advance(1);
  }
  assert.equal(f.data.rfSerpPolicy.gap,3500);
 }
});

test('legacy thirty minute policy is capped once, expires and clears queue state on success', async()=>{
 const f=fixture({until:Date.parse('2026-10-18T12:30:00+09:00'),http403:7});
 f.data.rfSkwCheckSkipUntil=f.data.rfSerpPolicy.until;
 const r=await f.policy.run('a',async()=>{throw Error('must wait');});
 assert.equal(r.retryAt,f.now()+180000); assert.equal(f.data.rfSkwCheckSkipUntil,r.retryAt);
 f.advance(60000); assert.equal((await f.policy.run('a',async()=>{})).retryAt,r.retryAt);
 f.advance(120000); await f.policy.run('a',async()=>({ok:true,status:200,html:''}));
 assert.equal(f.data.rfSerpPolicy.until,0); assert.equal(f.data.rfSerpPolicy.blockedOnResume,false);
 assert.equal(f.data.rfSkwCheckSkipUntil,0); assert.equal(f.data.rfSerpPolicy.http403,7);
});
test('network failure preserves restriction history and permission pause survives SERP success',async()=>{
 const f=fixture({until:1,blockedOnResume:true});
 await f.policy.run('a',async()=>({ok:false,status:0}));
 assert.equal(f.data.rfSerpPolicy.until,1);
 f.data.rfSkwCheckSkipReason='permission';f.data.rfSkwCheckSkipUntil=f.now()+22000000;
 await f.policy.run('b',async()=>({ok:true,status:200,html:''}));
 assert.equal(f.data.rfSkwCheckSkipReason,'permission');
 assert.ok(f.data.rfSkwCheckSkipUntil>f.now());
});
test('next request waits 2000ms after a long tab read or 403 fallback completes',async()=>{
 const f=fixture();let finished;
 await f.policy.run('a',async()=>{f.advance(5000);finished=f.now();return {ok:true,html:'tab'};});
 await f.policy.run('b',async()=>{assert.ok(f.now()-finished>=2000);return {ok:true,html:'tab'};});
});

test('IP gaps persist on success, cap at 10 seconds, and reset next KST day', async()=>{
 const f=fixture();const block=async()=>({ok:false,status:403});
 for(let i=0;i<30;i++){await f.policy.run('a',block,'ip-a');f.advance(180000);}
 assert.equal(f.data.rfSerpPolicy.gap,10000);
 await f.policy.run('ok',async()=>({ok:true}),'ip-a');assert.equal(f.data.rfSerpPolicy.gap,10000);
 await f.policy.run('other',async()=>({ok:true}),'ip-b');assert.equal(f.data.rfSerpPolicy.gap,2000);
 f.advance(86400000);await f.policy.run('tomorrow',async()=>({ok:true}),'ip-a');assert.equal(f.data.rfSerpPolicy.gap,2000);
});
test('spacing has no jitter even when random returns one', async()=>{
 const f=fixture({gap:9500});f.policy.random=()=>1;
 let first;await f.policy.run('a',async()=>{first=f.now();return {ok:true};});
 await f.policy.run('b',async()=>{assert.equal(f.now()-first,9500);return {ok:true};});
});

test('server revision resets persisted interval on next request, including cache and cooldown',async()=>{
 const f=fixture();
 await f.policy.run('a',async()=>({ok:false,status:403}),'ip',8000,'old');
 assert.equal(f.data.rfSerpPolicy.gap,8300);
 const r=await f.policy.run('b',async()=>{throw Error('cooldown preserved');},'ip',2000,'new');
 assert.equal(r.cooldown,true);assert.equal(f.data.rfSerpPolicy.gap,2000);
 f.advance(180000);
 await f.policy.run('c',async()=>({ok:true}),'ip',2000,'new');
 assert.equal(f.data.rfSerpPolicy.gap,2000);
 await f.policy.run('d',async()=>({ok:false,status:429}),'ip',2000,'new');
 assert.equal(f.data.rfSerpPolicy.gap,2300);
});

test('human recovery clears only cooldown and preserves IP increments, counters and next spacing', async () => {
 const f=fixture();let navigations=0,solved=false,probes=0;
 const fetcher=async()=>{navigations++;return {ok:false,status:403};};
 const probe=async()=>{probes++;return solved?{ok:true,status:200,html:'human solved'}:null;};
 await f.policy.run('same',fetcher,'ip-a',2000,null,probe);
 const until=f.data.rfSerpPolicy.until;
 for(let i=0;i<3;i++){f.advance(1500);const r=await f.policy.run('same',fetcher,'ip-a',2000,null,probe);assert.equal(r.cooldown,true);assert.equal(r.retryAt,until);}
 solved=true;f.advance(1500);
 const r=await f.policy.run('same',fetcher,'ip-a',2000,null,probe);
 assert.equal(r.ok,true);assert.equal(r.retryAt,null);assert.equal(r.interval_ms,2300);
 assert.equal(navigations,1);assert.equal(probes,4);assert.equal(f.data.rfSerpPolicy.http403,1);
 assert.equal(f.data.rfSerpPolicyByIp['ip-a'].gap,2300);assert.equal(f.data.rfSkwCheckSkipUntil,0);
 assert.equal((await f.policy.run('same',fetcher,'ip-a')).cached,true);
});

test('captcha never navigates after three minutes or a day, and human recovery preserves gap',async()=>{
 const f=fixture();let fetches=0;
 const fetcher=async()=>{fetches++;return {ok:false,captcha:true,status:200};};
 await f.policy.run('held',fetcher);
 for(const elapsed of [180000,86400000]){
  f.advance(elapsed);
  assert.equal((await f.policy.run('next',fetcher,'browser',2000,null,async()=>null)).cooldown,true);
 }
 assert.equal(fetches,1);
 const recovered=await f.policy.run('held',fetcher,'browser',2000,null,async()=>({ok:true,keyword:'held',html:'human'}));
 assert.equal(recovered.html,'human');assert.equal(fetches,1);assert.equal(f.data.rfSerpPolicy.until,0);
});
test('human recovery of a different keyword cannot be saved as current keyword HTML',async()=>{
 const f=fixture();await f.policy.run('old',async()=>({ok:false,captcha:true}));
 const recovered=await f.policy.run('new',async()=>({ok:true,html:'new html'}),'browser',2000,null,async()=>({ok:true,keyword:'old',html:'old html'}));
 assert.equal(recovered.html,'new html');
});
