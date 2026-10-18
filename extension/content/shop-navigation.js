// Collection-only navigation: inspect the rendered shopping block before using the menu.
(async () => {
  if (location.hash !== '#rfnav') return;
  const blocked = () => /보안문자|자동\s*입력\s*방지|비정상적인\s*(검색|접근)|접근이\s*제한/.test(document.body?.innerText || '');
  const visible = el => !!el && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden' && getComputedStyle(el).display !== 'none';
  const send = result => chrome.runtime.sendMessage({type: '__rfSerpRead', result});
  // Parse JSON only. Never execute page scripts in the extension world.
  function shoppingProducts() {
    const block = [...document.querySelectorAll('section, [data-shopping-block]')].find(el =>
      visible(el) && (el.matches('[data-slog-container="shp_lis"], [data-shopping-block]') || /네이버 쇼핑 인기상품/.test(el.querySelector('h2,h3')?.textContent || '')));
    if (!block) return null;
    for (const script of document.querySelectorAll('script')) {
      const text = script.textContent;
      const marker = /newshopping[\s\S]*?_INITIAL_STATE\s*=\s*/.exec(text);
      if (!marker) continue;
      const start = marker.index + marker[0].length;
      let depth = 0, quoted = false, escaped = false;
      for (let i = start; i < text.length; i++) {
        const c = text[i];
        if (quoted) {
          if (escaped) escaped = false;
          else if (c === '\\') escaped = true;
          else if (c === '"') quoted = false;
        } else if (c === '"') quoted = true;
        else if (c === '{') depth++;
        else if (c === '}' && --depth === 0) {
          try {
            const state = JSON.parse(text.slice(start, i + 1));
            return (state.initProps?.pagedSlot || []).flatMap(page => page.slots || []).filter(s => s.data).map(s => {
              const d = s.data;
              return {channelProductId: String(d.channelProductId || ''), nvMid: String(d.nvMid || ''),
                rank: Number(d.rank) || 0, isAd: ['AD','SUPER_POINT'].includes(d.sourceType),
                title: d.productName || '', mallName: d.mallName || '', price: d.discountedSalePrice || d.salePrice || 0,
                link: d.productUrl?.mobileUrl || d.productUrl?.pcUrl || ''};
            });
          } catch (_) { break; }
        }
      }
    }
    return document.readyState === 'loading' ? undefined : [];
  }
  let running = false, finished = false;
  chrome.runtime.onMessage.addListener((msg, sender, reply) => {
    if (msg?.type !== '__rfNavResume') return;
    reply({ok: false});
    if (!blocked() && !running && !finished) navigate();
  });
  async function navigate() {
    running = true;
    try {
      {
        if (blocked()) { await send({ok:false,captcha:true,status:200}); return; }
        const menu = [...document.querySelectorAll('a[href]')].find(a => visible(a) &&
          (a.getAttribute('role') === 'tab' || a.closest('nav, [role=tablist]')) && a.textContent.trim() === '쇼핑' && new URL(a.href, location.href).hostname.endsWith('.naver.com'));
        const products = shoppingProducts();
        if (Array.isArray(products)) {
          finished = true;
          await send(products.length || /검색 결과가 없습니다|상품이 없습니다/.test(document.body?.innerText || '') ? {ok:true,status:200,products,result_source:'integrated'} : {ok:false,status:0,message:'못 읽음 — 통합검색 상품 목록'});
          return;
        }
        if (menu && products === null) {
          const url = new URL(menu.href, location.href);
          url.hash = 'rfserp';
          menu.href = url.href;
          menu.removeAttribute('target');
          finished = true;
          menu.click();
          return;
        }

      }
      await send({ok:false,status:0,message:'못 읽음 — 통합검색 상품 목록 또는 쇼핑 메뉴'});
    } finally { running = false; }
  }
  await navigate();
})();
