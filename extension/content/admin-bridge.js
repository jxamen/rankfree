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

  window.addEventListener('message', (e) => {
    if (e.source !== window) return;
    const m = e.data;
    if (!m || m.source !== 'rankfree-admin' || m.type !== 'collectShop') return;

    const reply = (payload) => window.postMessage(
      Object.assign({ source: 'rankfree-ext', type: 'collectShopResult' }, payload), '*'
    );

    try {
      chrome.runtime.sendMessage(
        { type: 'collectShopSerp', payload: { keyword: String(m.keyword || ''), count: Number(m.count) || 80 } },
        (res) => {
          if (chrome.runtime.lastError) {
            reply({ ok: false, message: '확장과 통신할 수 없습니다. 확장을 새로고침해 주세요.' });
            return;
          }
          reply(res || { ok: false, message: '수집 실패' });
        }
      );
    } catch (err) {
      reply({ ok: false, message: '확장이 설치돼 있지 않거나 권한이 없습니다.' });
    }
  });
})();
