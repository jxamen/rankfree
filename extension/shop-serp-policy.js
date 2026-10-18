/* One browser-wide lane for screen + background exposure checks.
 * 1600 ms is a provisional conservative floor, NOT a measured safe interval.
 * A blocked response is never retried automatically within its 30 minute cooldown.
 */
class ShopSerpPolicy {
  constructor({ storage = chrome.storage.local, now = () => Date.now(), wait = ms => new Promise(r => setTimeout(r, ms)), cache = null } = {}) {
    this.storage = storage; this.now = now; this.wait = wait;
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
  run(keyword, fetcher) {
    const job = this.lane.then(() => this.check(keyword, fetcher));
    this.lane = job.catch(() => {}); return job;
  }
  async check(keyword, fetcher) {
    const day = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Asia/Seoul', year: 'numeric', month: '2-digit', day: '2-digit' }).format(this.now());
    const key = day + ':' + keyword.trim().normalize('NFC');
    // Fail closed when durable storage fails: avoid silently repeating same-day requests.
    try {
      const hit = await this.cache.get(key);
      if (hit) return { ...hit, cached: true };
      const data = await this.storage.get('rfSerpPolicy');
      const s = data.rfSerpPolicy || {};
      s.gap = Math.max(1600, Math.min(15000, Number(s.gap) || 1600));
      if (this.now() < (s.until || 0)) return { ok: false, cooldown: true, retryAt: s.until, message: '최근 차단으로 30분 휴식 중입니다.' };
      const wait = Math.max(0, (s.last || 0) + s.gap - this.now());
      if (wait) await this.wait(wait);
      const previous = s.last;
      s.last = this.now();
      await this.storage.set({ rfSerpPolicy: s });
      const res = await fetcher();
      if (res.captcha || res.status === 403 || res.status === 429) {
        s.gap = Math.min(15000, s.gap * 2); s.until = this.now() + 1800000; s.successes = 0;
        s.slowEntries = (s.slowEntries || 0) + 1;
      } else if (res.ok) {
        await this.cache.put(key, res);
        s.successes = (s.successes || 0) + 1;
        if (s.successes >= 20) { s.gap = Math.max(1600, s.gap - 100); s.successes = 0; }
      } else { s.successes = 0; }
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
      await this.storage.set({ rfSerpPolicy: s });
      return res;
    } catch (e) { return { ok: false, message: '속도·당일 캐시 저장소 오류 — 수집을 멈췄습니다.' }; }
  }
}
globalThis.ShopSerpPolicy = ShopSerpPolicy;
