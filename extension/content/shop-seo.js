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
  const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

  let curKey = '';
  let seoMap = null; // normTitle -> {score, used_keywords, rank, is_ad}
  let loading = false;
  let failed = false;
  let loadedCount = 0; // 분석한 카드 수 — 스크롤로 상품이 늘면 재분석

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
    // 일반(product_item)·광고(adProduct_item)·슈퍼적립 광고(superSavingProduct) 카드 모두.
    const all = [...document.querySelectorAll('[class*="product_item"], [class*="adProduct_item"], [class*="superSavingProduct"]')]
      .filter((c) => titleOf(c));
    // 다른 후보를 자손으로 포함하는 래퍼(슈퍼적립은 컨테이너+아이템 이중 매칭 → 배지 2개) 제외 → 가장 안쪽만
    return all.filter((c) => !all.some((o) => o !== c && c.contains(o)));
  }
  function titleOf(card) {
    // 일반/광고/슈퍼적립 제목 링크. 클래스가 달라도 title 속성 링크로 폴백.
    const a = card.querySelector('[class*="product_title"] a, [class*="adProduct_title"] a, [class*="superSavingProduct_title"] a')
      || card.querySelector('a[title][href]');
    return a ? (a.getAttribute('title') || a.textContent || '').trim() : '';
  }
  // 광고 카드 — 광고 전용 클래스(adProduct)·슈퍼적립(superSaving) 또는 카드 내 '광고' 배지
  function isAd(card) {
    if (/adProduct|superSaving|_ad_|__ad/i.test(card.className)) return true;
    // 슈퍼적립·광고 표시가 바깥 컨테이너에만 있는 경우(안쪽 아이템 클래스엔 없음) → 조상까지 확인
    if (card.closest('[class*="superSavingProduct"], [class*="adProduct"]')) return true;
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
    if (q !== curKey) { curKey = q; loadedCount = 0; seoMap = null; failed = false; clearBadges(); }
    const cs = cards();
    if (cs.length <= loadedCount && (seoMap || failed)) { apply(); return; } // 카드 안 늘면 배지만
    if (loading) return;
    loading = true;
    const products = cs.map((c, i) => ({ title: titleOf(c), rank: rankOf(c, i), is_ad: isAd(c) }));
    if (!products.length) { loading = false; return; }
    const res = await sendBg('shoppingSeo', { keyword: q, products });
    loading = false;
    if (!res || !res.ok || !res.data || !Array.isArray(res.data.products)) { failed = true; return; }
    failed = false;
    seoMap = new Map();
    res.data.products.forEach((p) => { const k = normTitle(p.title); if (k && !seoMap.has(k)) seoMap.set(k, p); });
    loadedCount = cs.length;
    apply();
  }

  function apply() {
    if (!seoMap) return;
    let organicRank = 0; // 광고 제외 순번
    cards().forEach((card) => {
      const ad = isAd(card);
      if (!ad) organicRank++;
      if (card.querySelector(':scope .rf-shop-seo')) return;
      const seo = seoMap.get(normTitle(titleOf(card)));
      if (!seo) return;
      const box = document.createElement('div');
      box.className = 'rf-shop-seo';
      const scoreCls = seo.score >= 80 ? ' hi' : (seo.score >= 60 ? '' : ' lo');
      const kws = (seo.used_keywords && seo.used_keywords.length) ? seo.used_keywords : [];
      box.innerHTML =
        (ad ? '<span class="rf-ss-ad">광고</span>' : '<span class="rf-ss-rank">랭킹 ' + organicRank + '</span>') +
        '<span class="rf-ss-score' + scoreCls + '">제목 점수 ' + seo.score + '</span>' +
        (kws.length
          ? '<span class="rf-ss-kw">제목 키워드 ' + kws.map((k) => '<b>' + esc(k) + '</b>').join(' · ') +
            '<button type="button" class="rf-ss-copy" title="키워드 복사">복사</button></span>'
          : '<span class="rf-ss-kw rf-ss-none">제목에 핵심 키워드 없음</span>');
      const copyBtn = box.querySelector('.rf-ss-copy');
      if (copyBtn) copyBtn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        try {
          navigator.clipboard.writeText(kws.join(' '));
          const o = copyBtn.textContent; copyBtn.textContent = '복사됨';
          setTimeout(() => { copyBtn.textContent = o; }, 1000);
        } catch (er) { /* noop */ }
      });
      const titleEl = card.querySelector('[class*="product_title"], [class*="adProduct_title"], [class*="superSavingProduct_title"]');
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
    setTimeout(() => { scheduled = false; loadSeo(); }, 400);
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
