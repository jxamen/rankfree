/* Observe the page's own v3 response. Never read or forward headers/cookies. */
(() => {
  'use strict';
  let latest = null;
  const scalar = v => typeof v === 'string' || typeof v === 'number' ? String(v) : '';
  function products(body) {
    const page = Array.isArray(body) && body[0];
    if (!page || Number(page.page) !== 1 || !Array.isArray(page.slots)) return null;
    const rows = [];
    for (const slot of page.slots) {
      const items = slot.slotType === 'CARD' ? [slot.data] : slot.slotType === 'HORIZONTAL_LIST' && Array.isArray(slot.data) ? slot.data : [];
      for (const p of items) {
        if (!p || !/^\d+$/.test(scalar(p.nvMid)) || !scalar(p.productName)) return null;
        const price = Number(p.discountedSalePrice ?? p.salePrice);
        if (!Number.isSafeInteger(price) || price < 0) return null;
        rows.push({nvMid:scalar(p.nvMid), title:scalar(p.productName), price,
          mallName:scalar(p.mallName), rank:rows.length + 1,
          isAd:['AD','AITEMS_FORYOU_AD'].includes(p.sourceType),
          channelProductId:scalar(p.channelProductId), link:scalar(p.productUrl?.pcUrl)});
        if (rows.length === 20) return rows;
      }
    }
    return null; // An incomplete first page cannot establish top 20.
  }
  const query = () => new URL(location.href).searchParams.get('query');
  function publish(body, keyword) {
    const rows = products(body);
    if (!rows || !keyword || keyword !== query()) return;
    latest = {keyword, products:rows, source:'naver-v3', page:1};
    document.dispatchEvent(new CustomEvent('rankfree:shop-v3', {detail:JSON.stringify(latest)}));
  }
  function endpoint(input) {
    try {
      const u = new URL(typeof input === 'string' || input instanceof URL ? String(input) : input.url, location.href);
      return u.origin === 'https://ns-portal.shopping.naver.com' && u.pathname === '/api/v3/shopping-paged-slot' ? u : null;
    } catch (_) { return null; }
  }
  const original = window.fetch;
  window.fetch = function(input) {
    const u = endpoint(input), result = original.apply(this, arguments);
    if (u) result.then(response => {
      if (response.ok) response.clone().json().then(body => publish(body, u.searchParams.get('query')), () => {});
    }, () => {});
    return result;
  };
  const open = XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open = function(method, url) {
    const u = endpoint(url);
    if (u) this.addEventListener('load', () => {
      if (this.status !== 200) return;
      try { publish(this.responseType === 'json' ? this.response : JSON.parse(this.responseText), u.searchParams.get('query')); } catch (_) {}
    }, {once:true});
    return open.apply(this, arguments);
  };
  document.addEventListener('rankfree:shop-v3-read', () => {
    if (latest?.keyword === query()) {
      document.dispatchEvent(new CustomEvent('rankfree:shop-v3', {detail:JSON.stringify(latest)}));
      return;
    }
    // Initial rendering can contain the same paged-slot response without a new fetch.
    const initial = window._INITIAL_STATE;
    if (initial?.initProps?.pagedSlot) publish(initial.initProps.pagedSlot, scalar(initial.originQuery || initial.query));
  });
  if (typeof module !== 'undefined') module.exports = {products};
})();
