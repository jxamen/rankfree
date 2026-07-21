/**
 * RankFree 콘솔 ↔ 확장 브릿지 — 쇼핑 노출 키워드 분석(25) 화면단 순위체크.
 * 서버가 m.search(순위)·상품페이지(제목/태그)를 직접 fetch 하면 IP 한도(429)로 수십 건에서 멈춘다.
 * 콘솔 페이지가 window.postMessage 로 요청하면 확장이 브라우저(사용자 IP)에서 대신 가져와 돌려주고,
 * 페이지가 그 HTML 을 서버에 보내 순위를 판정한다(파싱·매칭은 서버 한 곳).
 *
 * 프로토콜(페이지 → 확장):  { source:'rankfree-console', type, ...payload }
 *          (확장 → 페이지):  { source:'rankfree-ext', type: type+'Result', ...result }
 */
(function () {
  'use strict';
  if (window.__rfConsoleBridge) return;
  window.__rfConsoleBridge = true;

  // 확장이 설치돼 있음을 페이지가 알 수 있게 표식을 남긴다(화면단 체크 모드 판단용)
  document.documentElement.setAttribute('data-rf-ext', '1');

  // 페이지 요청 타입 → 확장 핸들러
  const ROUTES = {
    fetchShopSerp: (m) => ['fetchShopSerp', { keyword: String(m.keyword || '') }],
    fetchKeywordSignals: (m) => ['fetchKeywordSignals', { keyword: String(m.keyword || '') }],
    collectProductPage: (m) => ['collectProductPage', { url: String(m.url || '') }],
  };

  window.addEventListener('message', (e) => {
    if (e.source !== window) return;
    const m = e.data;
    if (!m || m.source !== 'rankfree-console' || !ROUTES[m.type]) return;

    const reply = (payload) => window.postMessage(
      Object.assign({ source: 'rankfree-ext', type: m.type + 'Result' }, payload), '*'
    );

    try {
      const [type, payload] = ROUTES[m.type](m);
      chrome.runtime.sendMessage({ type, payload }, (res) => {
        if (chrome.runtime.lastError) {
          reply({ ok: false, message: '확장 통신 실패: ' + chrome.runtime.lastError.message + ' — 확장을 새로고침(chrome://extensions) 후 다시 시도하세요.' });
          return;
        }
        reply(res || { ok: false, message: '확장이 응답하지 않았습니다.' });
      });
    } catch (err) {
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
