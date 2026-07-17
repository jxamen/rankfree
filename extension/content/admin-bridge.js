/**
 * RankFree 관리자 ↔ 확장 브릿지.
 * 네이버 쇼핑 검색 API 는 서버 요청을 418 로 막기 때문에, 관리자 화면이 쇼핑 상품(상위 80)을 보려면
 * 브라우저의 확장이 대신 수집해야 한다. 관리자 페이지가 window.postMessage 로 요청하면
 * 확장이 백그라운드 탭으로 수집(collectShopping)해 서버에 저장하고 결과를 돌려준다.
 *
 * 프로토콜(페이지 → 확장):  { source:'rankfree-admin', type:'collectShop', keyword, count }
 *          (확장 → 페이지):  { source:'rankfree-ext',  type:'collectShopResult', ok, saved, total, message }
 */
(function () {
  'use strict';
  if (window.__rfAdminBridge) return;
  window.__rfAdminBridge = true;

  // 확장이 설치돼 있음을 페이지가 알 수 있게 표식을 남긴다(버튼 활성화 판단용)
  document.documentElement.setAttribute('data-rf-ext', '1');

  // 페이지 요청 타입 → 확장 핸들러
  const ROUTES = {
    collectShop: (m) => ['collectShopSerp', { keyword: String(m.keyword || ''), count: Number(m.count) || 80 }],
    collectSellerCaptchas: (m) => ['sellerCaptchaStart', {
      products: Array.isArray(m.products) ? m.products : [],
      active: !!m.active,
      force: !!m.force,
    }],
    sellerCaptchaStart: (m) => ['sellerCaptchaStart', {
      products: Array.isArray(m.products) ? m.products : [],
      active: !!m.active,
      force: !!m.force,
    }],
    sellerCaptchaStatus: () => ['sellerCaptchaStatus', {}],
    sellerCaptchaStop: () => ['sellerCaptchaStop', {}],
    bulkStart: (m) => ['bulkShopStart', {
      limit: Number(m.limit) || 0, delayMs: Number(m.delayMs) || 6000,
      concurrency: Number(m.concurrency) || 2, force: !!m.force,
    }],
    bulkStatus: () => ['bulkShopStatus', {}],
    bulkStop: () => ['bulkShopStop', {}],
  };

  window.addEventListener('message', (e) => {
    if (e.source !== window) return;
    const m = e.data;
    if (!m || m.source !== 'rankfree-admin' || !ROUTES[m.type]) return;

    const reply = (payload) => window.postMessage(
      Object.assign({ source: 'rankfree-ext', type: m.type + 'Result' }, payload), '*'
    );

    try {
      const [type, payload] = ROUTES[m.type](m);
      chrome.runtime.sendMessage({ type, payload }, (res) => {
        // 서비스워커가 잠들었거나 확장이 리로드되면 여기로 온다 — 실제 사유를 그대로 노출해야 원인을 안다
        if (chrome.runtime.lastError) {
          reply({ ok: false, message: '확장 통신 실패: ' + chrome.runtime.lastError.message + ' — 확장을 새로고침(chrome://extensions) 후 다시 시도하세요.' });
          return;
        }
        reply(res || { ok: false, message: '확장이 응답하지 않았습니다.' });
      });
    } catch (err) {
      // "Extension context invalidated" = 확장을 업데이트/리로드한 뒤 페이지를 새로고침하지 않은 상태
      const raw = String((err && err.message) || err);
      reply({
        ok: false,
        message: raw.indexOf('context invalidated') >= 0
          ? '확장이 업데이트되었습니다 — 이 페이지를 새로고침(F5)한 뒤 다시 시도하세요.'
          : '확장 호출 실패: ' + raw,
      });
    }
  });
})();
