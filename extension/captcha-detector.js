// Packaged, observation-only rule. Never inspect raw HTML or execute page code.
globalThis.RfCaptcha = {
  inspect: function () {
    const evidence = {version:'visible-v1',signal:'none',url_pattern:'naver-page',visible:false};
    const answer = captcha => ({captcha,challengeVisible:captcha,evidence});
    const gate = value => {
      try {
        const u = new URL(value, location.href);
        if (u.protocol !== 'https:') return false;
        return /^(captcha|ncpt)\.naver\.com$/.test(u.hostname) ||
          (u.hostname === 'nid.naver.com' && /\/(?:captcha|ncpt)(?:\/|\.|$)/i.test(u.pathname));
      } catch (_) { return false; }
    };
    const visible = el => {
      if (!el || !el.getClientRects().length) return false;
      const rect = el.getBoundingClientRect();
      if (rect.width < 2 || rect.height < 2 || rect.bottom <= 0 || rect.right <= 0 || rect.top >= innerHeight || rect.left >= innerWidth) return false;
      for (let n = el; n && n.nodeType === 1; n = n.parentElement) {
        const s = getComputedStyle(n);
        if (s.display === 'none' || s.visibility === 'hidden' || s.visibility === 'collapse' || Number(s.opacity) === 0 || n.hidden || n.getAttribute('aria-hidden') === 'true') return false;
      }
      return true;
    };
    // Child frames cannot establish visibility in the parent. Inspect their iframe there.
    if (window.top !== window) return answer(false);
    if (!/(^|\.)naver\.com$/.test(location.hostname)) return answer(false);
    const normal = [...document.querySelectorAll('[data-slog-container="shp_lis"], [data-shopping-block], [data-shp-contents-id], [class*="product_item"], [class*="productItem"], [class*="ProductItem"], #sp_nws_all, #web')].some(visible);
    const frame = [...document.querySelectorAll('iframe[src]')].find(el => gate(el.src) && visible(el));
    if (frame) {
      evidence.signal = 'visible-gate-iframe'; evidence.url_pattern = 'naver-captcha-iframe'; evidence.visible = true;
      return answer(true);
    }
    const text = document.body?.innerText || '';
    if (/현재 서비스 접속이 불가|서비스에 접속할 수 없/.test(text)) return {...answer(false),unavailable:true};
    // Remote configuration may only tighten this packaged short-page rule (no code/regex/selectors).
    const configured = Number(globalThis.RfCaptcha?.rules?.max_page_text_chars);
    const textLimit = Number.isInteger(configured) && configured >= 200 && configured <= 4000 ? configured : 4000;
    // Naver first shows a collapsed restriction gate, before any captcha input/image.
    // Require its visible heading AND release control on the search endpoint.
    const searchPage = /^(m\.)?search\.naver\.com$/.test(location.hostname) && location.pathname === '/search.naver';
    const restriction = searchPage && [...document.querySelectorAll('h1,h2,h3,strong,p,div')].some(el =>
      visible(el) && /^검색\s*서비스\s*이용이\s*제한되었습니다[.!]?$/.test(el.innerText?.trim() || ''));
    const release = restriction && [...document.querySelectorAll('button,a,[role=button],summary')].some(el =>
      visible(el) && /^제한\s*해제\s*[⌄∨▼▽]?$/.test(el.innerText?.trim() || ''));
    if (release) {
      evidence.signal = 'visible-challenge'; evidence.visible = true;
      return answer(true);
    }
    const words = /보안\s*문자|자동\s*입력\s*방지|보안\s*확인|captcha/i.test(text);
    const input = [...document.querySelectorAll('input:not([type=hidden]):not([type=submit]):not([type=button])')].find(el => visible(el) && /captcha|ncpt|보안문자|자동입력|정답/i.test([el.id,el.name,el.placeholder,el.getAttribute('aria-label')].join(' ')));
    const image = [...document.querySelectorAll('img, canvas')].find(el => visible(el) && /captcha|ncpt|보안문자/i.test([el.id,el.className,el.getAttribute('src'),el.getAttribute('alt')].join(' ')));
    const gatePage = gate(location.href);
    if (words && input && (image || (gatePage && text.length <= textLimit))) {
      evidence.signal = 'visible-challenge'; evidence.url_pattern = gatePage ? 'naver-captcha-page' : 'naver-page'; evidence.visible = true;
      return answer(true);
    }
    if (normal) evidence.signal = 'normal-results';
    return answer(false);
  }
};

// Refresh a bounded numeric rule via the authenticated API; retain packaged defaults offline.
if (typeof document !== 'undefined' && typeof chrome !== 'undefined' && chrome.runtime?.sendMessage) {
  chrome.runtime.sendMessage({type:'captchaRules'}, rules => {
    if (!chrome.runtime.lastError && rules) globalThis.RfCaptcha.rules = rules;
  });
}
