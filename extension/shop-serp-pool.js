/* Experimental parallel lanes. Each lane owns its document and pacing policy.
 * A block reduces this analysis to one lane until explicit resume or a manual setting change. */
class ShopSerpPool {
  constructor({ storage = chrome.storage.local, tabFactory = slot => new ShopSerpTab({ slot }), policyFactory = slot => new ShopSerpPolicy({ storage: this.laneStorage(slot) }), wait = ms => new Promise(r => setTimeout(r, ms)) } = {}) {
    this.storage = storage; this.tabFactory = tabFactory; this.policyFactory = policyFactory; this.wait = wait;
    this.lanes = new Map(); this.active = new Set(); this.affinity = new Map(); this.gates = new Map();
    this.admission = Promise.resolve(); this.startLane = Promise.resolve(); this.started = new Set();
    this.captchaLane = Promise.resolve();
  }
  // All lanes share a durable hold. Only the blocked documents can prove recovery;
  // cached results, another analysis and a pacing/settings reset cannot release it.
  captchaWork(work) {
    const job = this.captchaLane.then(work);
    this.captchaLane = job.catch(() => {});
    return job;
  }
  lane(slot) {
    if (!this.lanes.has(slot)) this.lanes.set(slot, {tab:this.tabFactory(slot), policy:this.policyFactory(slot)});
    return this.lanes.get(slot);
  }
  async holdCaptcha(slot, analysisId, keyword, result) {
    return this.captchaWork(async () => {
      const waits = (await this.storage.get('rfSerpCaptchaWaits')).rfSerpCaptchaWaits || {};
      waits[slot] = {analysisId, keyword, ip:this.lane(slot).tab.collectorIp || 'browser', observed_at:result.observed_at || Date.now(), evidence:result.evidence};
      await this.storage.set({rfSerpCaptchaWaits:waits});
    });
  }
  async captchaGuard(inspect = true) {
    return this.captchaWork(async () => {
      const data = await this.storage.get(['rfSerpCaptchaWaits','rfSerpCaptchaRecoveredSlots']);
      const waits = data.rfSerpCaptchaWaits || {};
      const recovered = data.rfSerpCaptchaRecoveredSlots || {};
      let cleared = false;
      let closed = false;
      if (inspect) for (const [slot, blocked] of Object.entries(waits)) {
        let result;
        const tab = this.lane(Number(slot)).tab;
        try {
          if (blocked.tab_closed || (tab.hasTab && !await tab.hasTab())) result = {tab_closed:true};
          else result = await tab.peek(blocked.keyword);
        } catch (_) {}
        // Closing a challenge is a pause, not proof that the challenge was solved.
        // Keep the hold until explicit continue, but never ask to solve a missing tab.
        if (result?.tab_closed) { blocked.tab_closed = true; closed = true; continue; }
        if (result?.ok && !result.cached && ['integrated','shopping'].includes(result.result_source)) {
          delete waits[slot]; cleared = true; recovered[slot] = blocked.ip || 'browser';
        }
      }
      if (cleared || closed) await this.storage.set({rfSerpCaptchaWaits:waits,rfSerpCaptchaRecoveredSlots:recovered,
        ...(!Object.keys(waits).length ? {rfSerpCaptchaClearedAt:Date.now()} : {})});
      const blocked = Object.values(waits).find(wait => !wait.tab_closed);
      if (!blocked && Object.keys(waits).length) return {ok:false,tab_closed:true,paused:true,
        message:'수집 탭을 닫아 멈췄어요 — 이어서를 누르면 다시 시작'};
      if (!blocked) return null;
      return {ok:false,captcha:true,cooldown:true,captcha_wait:true,blocked_keyword:blocked.keyword,
        observed_at:blocked.observed_at,evidence:blocked.evidence,
        message:'검색을 멈췄어요 — 수집 탭에서 제한 해제를 완료해 주세요.'};
    });
  }
  async restart(analysisId, ip = 'browser') {
    const key = String(analysisId || 'legacy');
    // Keep live documents and browser pacing; only closed lanes need replacements.
    const closed = await this.closedSlots(key);
    await this.captchaWork(async () => {
      const waits = (await this.storage.get('rfSerpCaptchaWaits')).rfSerpCaptchaWaits || {};
      for (const slot of closed) if (String(waits[slot]?.analysisId) === key) delete waits[slot];
      // Explicit continue can retire a closed browser session, never a live gate.
      for (const slot of Object.keys(waits)) {
        const tab = this.lane(Number(slot)).tab;
        if (tab.hasTab && !await tab.hasTab()) delete waits[slot];
      }
      await this.storage.set({rfSerpCaptchaWaits:waits});
    });
    const session = {};
    for (let slot = 0; slot < 4; slot++) {
      const tail = slot ? ':lane:' + slot : '';
      if (closed.includes(slot)) for (const name of ['rfSerpTabId', 'rfSerpTabKeyword', 'rfSerpAnalysisId', 'rfSerpRecovery']) session[name + tail] = null;
      const lane = this.lanes.get(slot);
      if (closed.includes(slot) && lane) { lane.tab.tabId = null; lane.tab.target = null; lane.tab.alternativeKeywords = []; }
      await (lane?.policy || this.policyFactory(slot)).resume(ip);
    }
    await chrome.storage.session.set(session);
    await this.storage.set({['rfSerpClosed:' + key]:[], ['rfSerpCloseAck:' + key]:false, ['rfSerpReduced:' + key]:null, ['rfSerpFirstDone:' + key]:false});
    this.gates.delete(key);
    this.affinity.clear();
    this.started.clear();
    globalThis.rfSerpCurrent = {};
    return {ok:true};
  }
  async clearReduction(analysisId) {
    const key = String(analysisId);
    this.gates.delete(key);
    await this.storage.set({['rfSerpReduced:' + key]:null});
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
    const waiting = await this.captchaGuard();
    if (waiting) return waiting;
    const saved = await this.storage.get('rfSerpReduced:' + key);
    if (saved['rfSerpReduced:' + key] != null) this.gates.set(key, saved['rfSerpReduced:' + key]);
    const widthKey = 'rfSerpWidth:' + key;
    const closedAtStart = await this.closedSlots(key);
    let width = closedAtStart.length ? Number((await this.storage.get(widthKey))[widthKey]) || Number(count) || 1 : Number(count) || 1;
    await this.storage.set({[widthKey]:Math.min(4, Math.max(1, width))});
    let slot;
    let first = false;
    let gateSaved;
    while (slot === undefined) {
      const waiting = await this.captchaGuard(false);
      if (waiting) return waiting;
      const prefs = (await this.storage.get('rfShopSettings')).rfShopSettings;
      if (prefs) { count = prefs.parallel_tabs; startGap = prefs.tab_open_interval_ms; }
      if (!closedAtStart.length) width = Number(count) || 1;
      const closed = await this.closedSlots(key);
      const firstDone = (await this.storage.get('rfSerpFirstDone:' + key))['rfSerpFirstDone:' + key];
      const admission = this.admission.then(() => {
        const available = Array.from({length:Math.min(4, Math.max(1, width))},(_,i)=>i).filter(i => !closed.includes(i));
        if (!available.length) { slot = -1; return; }
        if (this.gates.has(key) && !available.includes(this.gates.get(key))) {
          this.gates.set(key, available[0]);
          gateSaved = this.storage.set({['rfSerpReduced:' + key]:available[0]});
        }
        const reduced = this.gates.has(key);
        const cap = reduced ? 1 : Math.min(available.length, Math.max(1, Number(count) || 1));
        if (this.active.size >= (firstDone ? cap : 1)) return;
        const preferred = reduced ? this.gates.get(key) : this.affinity.get(key + ':' + keyword);
        const candidates = preferred !== undefined && available.includes(preferred) ? [preferred] : available;
        slot = candidates.find(i => !this.active.has(i));
        if (slot !== undefined) { this.active.add(slot); first = !firstDone; }
      });
      this.admission = admission.catch(() => {});
      await admission;
      if (slot === undefined) await this.wait(50);
    }
    if (gateSaved) await gateSaved;
    if (slot === -1) return {ok:false, paused:true, tab_closed:true, message:'수집 탭을 닫아 멈췄어요 — 이어서를 누르면 다시 시작'};
    this.lane(slot);
    this.affinity.set(key + ':' + keyword, slot);
    let released = false;
    const release = async () => {
      if (released) return;
      released = true;
      try { if (first) await this.storage.set({['rfSerpFirstDone:' + key]:true}); }
      finally { this.active.delete(slot); }
    };
    try {
      const lane = this.lanes.get(slot);
      lane.tab.analysisId = analysisId;
      lane.tab.isClosed = async () => (await this.closedSlots(key)).includes(slot);
      lane.tab.allowOpen = () => !this.gates.has(key) || this.gates.get(key) === slot;
      lane.tab.beforeNavigate = () => this.captchaGuard(false);
      await this.stagger(key, slot, startGap);
      const waiting = await this.captchaGuard(false);
      if (waiting) return waiting;
      await this.captchaWork(async () => {
        const recovered = (await this.storage.get('rfSerpCaptchaRecoveredSlots')).rfSerpCaptchaRecoveredSlots || {};
        if (!(slot in recovered)) return;
        await lane.policy.resume(recovered[slot]);
        delete recovered[slot];
        await this.storage.set({rfSerpCaptchaRecoveredSlots:recovered});
      });
      // A manual decrease can arrive while this lane waits for its opening gap.
      const latest = (await this.storage.get('rfShopSettings')).rfShopSettings;
      if (latest && slot >= latest.parallel_tabs) { await release(); return this.run(analysisId, keyword, latest.parallel_tabs, execute, latest.tab_open_interval_ms); }
      const result = await execute(lane);
      if (result.captcha && result.evidence?.visible && !result.captcha_wait) await this.holdCaptcha(slot, analysisId, keyword, result);
      if (await lane.tab.isClosed()) return {ok:false, tab_closed:true, paused:(await this.closedSlots(key)).length === 4};
      if (result.deferred) { await release(); return this.run(analysisId, keyword, 1, execute, startGap); }
      if (!this.gates.has(key) && (result.captcha || [403,429].includes(result.status))) {
        this.gates.set(key, slot);
        await this.storage.set({['rfSerpReduced:' + key]:slot});
      }
      // A response already in flight may finish after another lane encountered a gate.
      // Keep it pending rather than letting that success dismiss the security wait.
      const held = result.captcha ? null : await this.captchaGuard(false);
      const clearedAt = (await this.storage.get('rfSerpCaptchaClearedAt')).rfSerpCaptchaClearedAt;
      return {...(held || result), captcha_cleared_at:clearedAt, parallel_reduced:this.gates.has(key), parallel_tabs:this.gates.has(key) ? 1 : count};
    } finally { await release(); }
  }
}
globalThis.ShopSerpPool = ShopSerpPool;
