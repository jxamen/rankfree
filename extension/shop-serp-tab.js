/* Called inside ShopSerpPolicy's single lane. Reuse one inactive, unpinned collection tab across jobs. */
class ShopSerpTab {
  constructor({ browser = chrome, timeoutMs = 60000, wait = ms => new Promise(r => setTimeout(r, ms)) } = {}) {
    this.browser = browser; this.timeoutMs = timeoutMs; this.wait = wait;
    this.tabId = null;
  }
  static html(products) {
    const slots = products.map((p, i) => ({ data: {
      sourceType: p.isAd ? 'AD' : 'SAS', rank: p.rank || i + 1,
      channelProductId: String(p.channelProductId || p.mallProductId || ''),
      nvMid: String(p.nvMid || p.id || ''), productUrl: { pcUrl: p.link || '' },
      mallName: p.mallName || '', productName: p.title || '', salePrice: p.price || 0,
    } }));
    return '<script>var _INITIAL_STATE = ' + JSON.stringify({ initProps: { pagedSlot: [{ slots }] } }).replace(/</g, '\\u003c') + ';</script>';
  }
  // Passive document inspection: never navigate, reload, solve, or change the active tab.
  async peek(keyword) {
    try {
      const saved = await this.browser.storage.session.get(['rfSerpTabId', 'rfSerpTabKeyword']);
      if (saved.rfSerpTabKeyword !== keyword || saved.rfSerpTabId == null) return null;
      const tab = await this.browser.tabs.get(saved.rfSerpTabId);
      const url = new URL(tab.url);
      if (url.origin === 'https://m.search.naver.com' && url.hash === '#rfnav' && url.searchParams.get('query') === keyword) {
        await this.browser.tabs.sendMessage(tab.id, { type: '__rfNavResume' });
        return null;
      }
      if (!['https://search.shopping.naver.com', 'https://msearch.shopping.naver.com'].includes(url.origin) || !url.pathname.startsWith('/search/') || url.searchParams.get('query') !== keyword) return null;
      const r = await this.browser.tabs.sendMessage(saved.rfSerpTabId, { type: '__rfSerpPeek', prepare: true });
      if (r?.ok && Array.isArray(r.products)) {
        return { ok: true, status: 200, html: ShopSerpTab.html(r.products), result_source: r.result_source || 'shopping', mode: 'tab' };
      }
    } catch (_) { /* Gate pages have no content script. Keep waiting. */ }
    return null;
  }
  async read(keyword, target = {}) {
    const c = this.browser;
    let tab;
    let finish;
    const result = new Promise(resolve => { finish = resolve; });
    const blocked = () => finish({ ok: false, captcha: true, status: 200, message: '네이버 보안 화면 감지 — 3분 후 재확인합니다.' });
    const onMessage = (msg, sender, reply) => {
      if (tab && sender?.tab?.id === tab.id && msg?.type === '__rfSerpTarget') { reply(target); return; }
      if (!tab || sender?.tab?.id !== tab.id || msg?.type !== '__rfSerpRead') return;
      const r = msg.result;
      if (r?.ok && Array.isArray(r.products)) finish({ ok: true, status: 200, html: ShopSerpTab.html(r.products), result_source: r.result_source || 'shopping', mode: 'tab' });
      else if (r && !r.ok) finish(r);
    };
    const onUpdated = (id, info) => {
      if (id === tab?.id && /^https:\/\/(?:nid|captcha|ncpt)\.naver\.com\//i.test(info.url || '')) blocked();
    };
    const onRemoved = id => {
      if (id !== tab?.id) return;
      this.tabId = null;
      finish({ ok: false, status: 0, message: '수집 탭이 닫혔습니다.' });
    };
    c.runtime.onMessage.addListener(onMessage);
    c.tabs.onUpdated.addListener(onUpdated);
    c.tabs.onRemoved.addListener(onRemoved);
    const timer = setTimeout(() => finish({ ok: false, status: 0, message: '쇼핑 탭 응답 시간이 초과되었습니다.' }), this.timeoutMs);
    try {
      const url = 'https://m.search.naver.com/search.naver?where=m&query=' + encodeURIComponent(keyword) + '#rfnav';
      const storage = c.storage.session;
      if (this.tabId === null) {
        const saved = await storage.get('rfSerpTabId');
        this.tabId = saved.rfSerpTabId ?? null;
      }
      if (this.tabId !== null) {
        try { tab = await c.tabs.get(this.tabId); }
        catch (_) { this.tabId = null; }
      }
      if (tab) {
        await c.tabs.update(tab.id, { url, active: false });
      } else {
        tab = await c.tabs.create({ url, active: false, pinned: false });
        this.tabId = tab.id;
        await storage.set({ rfSerpTabId: tab.id });
      }
      await storage.set({ rfSerpTabKeyword: keyword });
      const current = await c.tabs.get(tab.id);
      onUpdated(tab.id, { url: current.url || current.pendingUrl });
      const response = await result;
      clearTimeout(timer);

      return response;
    } catch (e) { return { ok: false, status: 0, message: '쇼핑 탭 오류: ' + e.message }; }
    finally {
      clearTimeout(timer);
      c.runtime.onMessage.removeListener(onMessage);
      c.tabs.onUpdated.removeListener(onUpdated);
      c.tabs.onRemoved.removeListener(onRemoved);
    }
  }
}
globalThis.ShopSerpTab = ShopSerpTab;
