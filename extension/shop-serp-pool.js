/* Experimental parallel lanes. Each lane owns its document and pacing policy.
 * A gate permanently reduces this analysis to one lane, including after worker restart. */
class ShopSerpPool {
  constructor({ storage = chrome.storage.local, tabFactory = slot => new ShopSerpTab({ slot }), policyFactory = slot => new ShopSerpPolicy({ storage: this.laneStorage(slot) }), wait = ms => new Promise(r => setTimeout(r, ms)) } = {}) {
    this.storage = storage; this.tabFactory = tabFactory; this.policyFactory = policyFactory; this.wait = wait;
    this.lanes = new Map(); this.active = new Set(); this.affinity = new Map(); this.gates = new Map();
    this.admission = Promise.resolve(); this.startLane = Promise.resolve(); this.started = new Set();
  }
  laneStorage(slot) {
    const keys = ['rfSerpPolicy', 'rfSerpPolicyByIp'];
    const name = key => keys.includes(key) && slot ? key + ':lane:' + slot : key;
    return {
      get: async requested => {
        const list = typeof requested === 'string' ? [requested] : requested;
        const data = await this.storage.get(list.map(name));
        return Object.fromEntries(list.map(k => [k, data[name(k)]]));
      },
      set: data => this.storage.set(Object.fromEntries(Object.entries(data).map(([k,v]) => [name(k),v]))),
    };
  }
  async stagger(key, slot, gap) {
    const identity = key + ':' + slot;
    if (this.started.has(identity)) return;
    this.started.add(identity);
    const job = this.startLane.then(async () => {
      const saved = await this.storage.get('rfSerpLastLaneStarted');
      const delay = Math.max(0, Number(saved.rfSerpLastLaneStarted || 0) + Math.max(0, Number(gap) || 0) - Date.now());
      if (delay) await this.wait(delay);
      await this.storage.set({rfSerpLastLaneStarted:Date.now()});
    });
    this.startLane = job.catch(() => {});
    await job;
  }
  async closedSlots(key) {
    return (await this.storage.get('rfSerpClosed:' + key))['rfSerpClosed:' + key] || [];
  }
  async run(analysisId, keyword, count, execute, startGap = 0) {
    const key = String(analysisId || 'legacy');
    const saved = await this.storage.get('rfSerpReduced:' + key);
    if (saved['rfSerpReduced:' + key] != null) this.gates.set(key, saved['rfSerpReduced:' + key]);
    const widthKey = 'rfSerpWidth:' + key;
    const closedAtStart = await this.closedSlots(key);
    const width = closedAtStart.length ? Number((await this.storage.get(widthKey))[widthKey]) || Number(count) || 1 : Number(count) || 1;
    await this.storage.set({[widthKey]:Math.min(4, Math.max(1, width))});
    let slot;
    let gateSaved;
    while (slot === undefined) {
      const closed = await this.closedSlots(key);
      const admission = this.admission.then(() => {
        const available = Array.from({length:Math.min(4, Math.max(1, width))},(_,i)=>i).filter(i => !closed.includes(i));
        if (!available.length) { slot = -1; return; }
        if (this.gates.has(key) && !available.includes(this.gates.get(key))) {
          this.gates.set(key, available[0]);
          gateSaved = this.storage.set({['rfSerpReduced:' + key]:available[0]});
        }
        const reduced = this.gates.has(key);
        const cap = reduced ? 1 : Math.min(available.length, Math.max(1, Number(count) || 1));
        if (this.active.size >= cap) return;
        const preferred = reduced ? this.gates.get(key) : this.affinity.get(key + ':' + keyword);
        const candidates = preferred !== undefined && available.includes(preferred) ? [preferred] : available;
        slot = candidates.find(i => !this.active.has(i));
        if (slot !== undefined) this.active.add(slot);
      });
      this.admission = admission.catch(() => {});
      await admission;
      if (slot === undefined) await this.wait(50);
    }
    if (gateSaved) await gateSaved;
    if (slot === -1) return {ok:false, paused:true, tab_closed:true, message:'수집 탭을 닫아 멈췄어요 — 이어서를 누르면 다시 시작'};
    if (!this.lanes.has(slot)) this.lanes.set(slot, {tab:this.tabFactory(slot), policy:this.policyFactory(slot)});
    this.affinity.set(key + ':' + keyword, slot);
    try {
      const lane = this.lanes.get(slot);
      lane.tab.analysisId = analysisId;
      lane.tab.isClosed = async () => (await this.closedSlots(key)).includes(slot);
      lane.tab.allowOpen = () => !this.gates.has(key) || this.gates.get(key) === slot;
      await this.stagger(key, slot, startGap);
      const result = await execute(lane);
      if (await lane.tab.isClosed()) return {ok:false, tab_closed:true, paused:(await this.closedSlots(key)).length === 4};
      if (result.deferred) return this.run(analysisId, keyword, 1, execute, startGap);
      if (!this.gates.has(key) && (result.captcha || [403,429].includes(result.status))) {
        this.gates.set(key, slot);
        await this.storage.set({['rfSerpReduced:' + key]:slot});
      }
      return {...result, parallel_reduced:this.gates.has(key), parallel_tabs:this.gates.has(key) ? 1 : count};
    } finally { this.active.delete(slot); }
  }
}
globalThis.ShopSerpPool = ShopSerpPool;
