/**
 * RankFree — 네이버 쇼핑 검색 결과 각 상품 아래에 SEO 배지 주입.
 * search.shopping.naver.com/search 의 상품 카드([class*="product_item"]) 제목 밑에
 * 랭킹 · 제목 SEO 점수 · 제목에 쓴 키워드를 표시한다(서버 /api/ext/shopping-seo).
 * 스크롤 시 상품이 동적 추가되므로 MutationObserver로 새 카드에도 주입한다.
 */
(function () {
  'use strict';
  if (window.__rfShopSeo) return;
  window.__rfShopSeo = true;

  const normTitle = (s) => String(s || '').toLowerCase().replace(/\s+/g, '').replace(/[·.,()[\]'"‘’“”\-–—&/]/g, '');

  let curKey = '';
  let seoMap = null; // normTitle -> {score, used_keywords, rank, is_ad}
  let loading = false;
  let failed = false;

  function query() {
    try { return new URLSearchParams(location.search).get('query') || ''; } catch (e) { return ''; }
  }

  function sendBg(type, payload) {
    return new Promise((resolve) => {
      try {
        chrome.runtime.sendMessage({ type, payload }, (res) => {
          if (chrome.runtime.lastError) { resolve({ ok: false }); return; }
          resolve(res || { ok: false });
        });
      } catch (e) { resolve({ ok: false }); }
    });
  }

  function cards() {
    return [...document.querySelectorAll('[class*="product_item"]')].filter((c) => c.querySelector('[class*="product_title"]'));
  }
  function titleOf(card) {
    const a = card.querySelector('[class*="product_title"] a');
    return a ? (a.getAttribute('title') || a.textContent || '').trim() : '';
  }
  // 광고 카드 — 광고 전용 클래스(adProduct) 또는 카드 내 '광고' 배지
  function isAd(card) {
    if (/adProduct|_ad_|__ad/i.test(card.className)) return true;
    return [...card.querySelectorAll('span,em,i')].some((e) => (e.textContent || '').trim() === '광고');
  }
  // 랭킹 — data-shp-contents-rank 우선, 없으면 카드 순서
  function rankOf(card, idx) {
    const el = card.querySelector('[data-shp-contents-rank]');
    const r = el && parseInt(el.getAttribute('data-shp-contents-rank'), 10);
    return r || (idx + 1);
  }

  async function loadSeo() {
    const q = query();
    if (!q) return;
    if (q === curKey && (seoMap || loading || failed)) return;
    curKey = q; seoMap = null; failed = false; loading = true;
    clearBadges();
    const cs = cards();
    const products = cs.map((c, i) => ({ title: titleOf(c), rank: rankOf(c, i), is_ad: isAd(c) }));
    if (!products.length) { loading = false; return; }
    const res = await sendBg('shoppingSeo', { keyword: q, products });
    loading = false;
    if (!res || !res.ok || !res.data || !Array.isArray(res.data.products)) { failed = true; return; }
    seoMap = new Map();
    res.data.products.forEach((p) => { const k = normTitle(p.title); if (k && !seoMap.has(k)) seoMap.set(k, p); });
    apply();
  }

  function apply() {
    if (!seoMap) return;
    cards().forEach((card, i) => {
      if (card.querySelector(':scope .rf-shop-seo')) return;
      const seo = seoMap.get(normTitle(titleOf(card)));
      if (!seo) return;
      const box = document.createElement('div');
      box.className = 'rf-shop-seo';
      const scoreCls = seo.score >= 80 ? ' hi' : (seo.score >= 60 ? '' : ' lo');
      box.innerHTML =
        '<span class="rf-ss-rank">랭킹 ' + rankOf(card, i) + '</span>' +
        '<span class="rf-ss-score' + scoreCls + '">제목 점수 ' + seo.score + '</span>' +
        (seo.used_keywords && seo.used_keywords.length
          ? '<span class="rf-ss-kw">제목 키워드 ' + seo.used_keywords.map((k) => '<b>' + k + '</b>').join(' · ') + '</span>'
          : '<span class="rf-ss-kw rf-ss-none">제목에 핵심 키워드 없음</span>');
      const titleEl = card.querySelector('[class*="product_title"]');
      if (titleEl) titleEl.insertAdjacentElement('afterend', box);
      else card.insertBefore(box, card.firstChild);
    });
  }

  function clearBadges() {
    document.querySelectorAll('.rf-shop-seo').forEach((b) => b.remove());
  }

  let scheduled = false;
  function schedule() {
    if (scheduled) return;
    scheduled = true;
    setTimeout(() => { scheduled = false; if (seoMap) apply(); else loadSeo(); }, 400);
  }

  function start() {
    loadSeo();
    new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    // 검색어(SPA) 변경 대응
    let last = location.href;
    setInterval(() => { if (location.href !== last) { last = location.href; loadSeo(); } }, 1000);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
