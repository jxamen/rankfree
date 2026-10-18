/* One browser-wide lane for screen + background exposure checks.
 * 3000 ms is a provisional conservative floor, NOT a measured safe interval.
 * A blocked response is never retried automatically within its 3 minute cooldown.
 */
class ShopSerpPolicy {
  constructor({ storage = chrome.storage.local, now = () => Date.now(), wait = ms => new Promise(r => setTimeout(r, ms)), cache = null, random = Math.random } = {}) {
    this.random = random; this.storage = storage; this.now = now; this.wait = wait;
    this.cache = cache || this.dailyCache();
    this.lane = Promise.resolve();
  }
  dailyCache() {
    const db = new Promise((resolve, reject) => {
      const r = indexedDB.open('rf-shop-serp-day', 1);
      r.onupgradeneeded = () => r.result.createObjectStore('serps');
      r.onsuccess = () => resolve(r.result); r.onerror = () => reject(r.error);
    });
    return {
      async get(key) {
        const d = await db;
        return new Promise((resolve, reject) => {
          const r = d.transaction('serps').objectStore('serps').get(key);
          r.onsuccess = () => resolve(r.result); r.onerror = () => reject(r.error);
        });
      },
      async put(key, value) {
        const d = await db;
        return new Promise((resolve, reject) => {
          const t = d.transaction('serps', 'readwrite'); const st = t.objectStore('serps');
          st.put(value, key);
          // Keep only today's results. Raw fragments are parsed per target on the server.
          const r = st.openCursor(); r.onsuccess = () => { const c = r.result; if (c) { if (!String(c.key).startsWith(key.slice(0, 11))) c.delete(); c.continue(); } };
          t.oncomplete = resolve; t.onerror = () => reject(t.error); t.onabort = () => reject(t.error);
        });
      },
    };
  }
  run(keyword, fetcher, ip = "browser", serverGap = 3000) {
    const job = this.lane.then(() => this.check(keyword, fetcher, ip, serverGap));
    this.lane = job.catch(() => {}); return job;
  }
  async check(keyword, fetcher, ip, serverGap) {
    const day = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Seoul', year: 'numeric', month: '2-digit', day: '2-digit' }).format(this.now());
    const key = day + ':tab-v1:' + keyword.trim().normalize('NFC');
    // Fail closed when durable storage fails: avoid silently repeating same-day requests.
    try {
      const data = await this.storage.get(['rfSerpPolicy', 'rfSerpPolicyByIp']);
      const policies = data.rfSerpPolicyByIp || {};
      const s = policies[ip] || (ip === 'browser' ? data.rfSerpPolicy : null) || {};
      if (s.day && s.day !== day) { s.gap = 3000; }
      s.day = day;
      policies[ip] = s;
      const save = () => this.storage.set({ rfSerpPolicy: s, rfSerpPolicyByIp: policies });
      if ((s.until || 0) > this.now() + 180000) {
        const previousUntil = s.until;
        s.until = this.now() + 180000;
        await save();
        const queue = await this.storage.get(['rfSkwCheckSkipUntil', 'rfSkwCheckSkipReason']);
        if (Math.abs((queue.rfSkwCheckSkipUntil || 0) - previousUntil) <= 5000 && queue.rfSkwCheckSkipReason !== 'permission') {
          await this.storage.set({ rfSkwCheckSkipUntil: s.until, rfSkwCheckSkipReason: 'naver' });
        }
      }
      s.gap = Math.max(3000, Math.min(10000, Number(s.gap) || 3000));
      s.gap = Math.max(3000, Math.min(10000, Math.max(Number(s.gap) || 3000, Number(serverGap) || 3000)));
      const hit = await this.cache.get(key);
      if (hit) { await save(); return { ...hit, cached: true, interval_ms: s.gap }; }
      if (this.now() < (s.until || 0)) return { ok: false, cooldown: true, interval_ms: s.gap, retryAt: s.until, message: '최근 차단으로 3분 휴식 중입니다.' };
      const wait = Math.max(0, (s.last || 0) + Math.min(10000, s.gap + Math.floor(this.random() * 1001)) - this.now());
      if (wait) await this.wait(wait);
      const resumedAfterBlock = !!s.until;
      const previous = s.last;
      s.last = this.now();
      await save();
      const res = await fetcher();
      // Include the 403 tab recheck in spacing: next navigation waits after completion.
      s.last = this.now();
      if (res.captcha || res.status === 403 || res.status === 429) {
        s.until = this.now() + 180000; s.successes = 0; s.lastBlockedAt = this.now(); s.blockedOnResume = resumedAfterBlock;
        s.gap = Math.min(10000, s.gap + 300);
        s.slowEntries = (s.slowEntries || 0) + 1;
      } else if (res.ok) {
        s.until = 0; s.blockedOnResume = false;
        await save();
        const queue = await this.storage.get('rfSkwCheckSkipReason');
        if (queue.rfSkwCheckSkipReason !== 'permission') {
          await this.storage.set({ rfSkwCheckSkipUntil: 0, rfSkwCheckSkipReason: '' });
        }
        await this.cache.put(key, res);
      }

      const hour = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Seoul', dateStyle: 'short', timeStyle: 'short', hour12: false }).format(s.last).slice(0, 13);
      s.hourly = s.hourly || {};
      const bucket = s.hourly[hour] || { requests: 0, http403: 0, http429: 0, captchas: 0, slowEntries: 0 };
      bucket.requests++; bucket.http403 += res.status === 403 ? 1 : 0; bucket.http429 += res.status === 429 ? 1 : 0;
      bucket.captchas += res.captcha ? 1 : 0; bucket.slowEntries += (res.captcha || res.status === 403 || res.status === 429) ? 1 : 0;
      s.hourly[hour] = bucket;
      for (const old of Object.keys(s.hourly).sort().slice(0, -72)) delete s.hourly[old];
      s.lastIntervalMs = previous ? s.last - previous : null;
      if (res.status === 403) s.last403IntervalMs = s.lastIntervalMs;
      s.requests = (s.requests || 0) + 1;
      s.http403 = (s.http403 || 0) + (res.status === 403 ? 1 : 0);
      s.http429 = (s.http429 || 0) + (res.status === 429 ? 1 : 0);
      s.captchas = (s.captchas || 0) + (res.captcha ? 1 : 0);
      await save();
      return { ...res, interval_ms: s.gap, retryAt: s.until || null, blockedOnResume: !!s.blockedOnResume && (res.captcha || res.status === 403 || res.status === 429) };
    } catch (e) { return { ok: false, message: '속도·당일 캐시 저장소 오류 — 수집을 멈췄습니다.' }; }
  }
}
globalThis.ShopSerpPolicy = ShopSerpPolicy;
