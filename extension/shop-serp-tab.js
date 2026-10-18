/* Called inside ShopSerpPolicy's single lane. Each job owns one inactive tab. */
class ShopSerpTab {
  constructor({ browser = chrome, timeoutMs = 45000 } = {}) {
    this.browser = browser; this.timeoutMs = timeoutMs;
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
  async read(keyword) {
    const c = this.browser;
    let tab;
    let finish;
    const result = new Promise(resolve => { finish = resolve; });
    const blocked = () => finish({ ok: false, captcha: true, status: 200, message: '네이버 보안 화면 감지 — 3분 후 재확인합니다.' });
    const onMessage = (msg, sender) => {
      if (!tab || sender?.tab?.id !== tab.id || msg?.type !== '__rfSerpRead') return;
      const r = msg.result;
      if (r?.ok && Array.isArray(r.products)) finish({ ok: true, status: 200, html: ShopSerpTab.html(r.products), mode: 'tab' });
      else if (r && !r.ok) finish(r);
    };
    const onUpdated = (id, info) => {
      if (id === tab?.id && /^https:\/\/(?:nid|captcha|ncpt)\.naver\.com\//i.test(info.url || '')) blocked();
    };
    const onRemoved = id => { if (id === tab?.id) finish({ ok: false, status: 0, message: '수집 탭이 닫혔습니다.' }); };
    c.runtime.onMessage.addListener(onMessage);
    c.tabs.onUpdated.addListener(onUpdated);
    c.tabs.onRemoved.addListener(onRemoved);
    const timer = setTimeout(() => finish({ ok: false, status: 0, message: '쇼핑 탭 응답 시간이 초과되었습니다.' }), this.timeoutMs);
    try {
      // Remove only our marked tabs left behind by a terminated service worker.
      const stale = await c.tabs.query({ url: 'https://search.shopping.naver.com/search/*' });
      for (const t of stale) { if ((t.url || t.pendingUrl || '').endsWith('#rfserp')) await c.tabs.remove(t.id); }
      tab = await c.tabs.create({ url: 'https://search.shopping.naver.com/search/all?query=' + encodeURIComponent(keyword) + '#rfserp', active: false });
      const current = await c.tabs.get(tab.id);
      onUpdated(tab.id, { url: current.url || current.pendingUrl });
      return await result;
    } catch (e) { return { ok: false, status: 0, message: '쇼핑 탭 오류: ' + e.message }; }
    finally {
      clearTimeout(timer);
      c.runtime.onMessage.removeListener(onMessage);
      c.tabs.onUpdated.removeListener(onUpdated);
      c.tabs.onRemoved.removeListener(onRemoved);
      if (tab) { try { await c.tabs.remove(tab.id); } catch (_) {} }
    }
  }
}
globalThis.ShopSerpTab = ShopSerpTab;
