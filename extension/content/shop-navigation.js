// Collection-only navigation: inspect the rendered shopping block before using the menu.
(async () => {
  if (location.hash !== '#rfnav') return;
  const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
  const blocked = () => /보안문자|자동\s*입력\s*방지|비정상적인\s*(검색|접근)|접근이\s*제한/.test(document.body?.innerText || '');
  const visible = el => !!el && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden' && getComputedStyle(el).display !== 'none';
  const send = result => chrome.runtime.sendMessage({type: '__rfSerpRead', result});
  // Parse JSON only. Never execute page scripts in the extension world.
  function shoppingProducts() {
    const block = [...document.querySelectorAll('section, [data-shopping-block]')].find(el =>
      visible(el) && (el.matches('[data-slog-container="shp_lis"], [data-shopping-block]') || /네이버 쇼핑 인기상품/.test(el.querySelector('h2,h3')?.textContent || '')));
    if (!block) return [];
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
    return [];
  }
  function topFive(products, target) {
    const norm = s => String(s || '').trim().replace(/ /g, '').toLowerCase();
    let organic = 0;
    for (const p of products) {
      if (p.isAd) continue;
      organic++;
      const matches = target.product_id
        ? String(target.product_id) === (target.id_kind === 'nvmid' ? p.nvMid : p.channelProductId)
        : !!norm(target.mall_name) && norm(p.mallName).includes(norm(target.mall_name));
      if (matches) return (p.rank || organic) <= 5;
    }
    return false;
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
      for (let attempt = 0; attempt < 120; attempt++) {
        if (blocked()) { await send({ok:false,captcha:true,status:200}); return; }
        const menu = [...document.querySelectorAll('a[href]')].find(a => visible(a) &&
          (a.getAttribute('role') === 'tab' || a.closest('nav, [role=tablist]')) && a.textContent.trim() === '쇼핑' && new URL(a.href, location.href).hostname.endsWith('.naver.com'));
        if (document.readyState === 'complete' && menu) {
          // No scrolling on integrated search. Recheck after the full dwell time.
          await sleep(1500 + Math.floor(Math.random() * 1001));
          if (blocked()) { await send({ok:false,captcha:true,status:200}); return; }
          if (document.readyState !== 'complete' || !visible(menu)) continue;
          const target = await chrome.runtime.sendMessage({type: '__rfSerpTarget'}) || {};
          const products = shoppingProducts();
          if (topFive(products, target)) {
            finished = true;
            await send({ok:true,status:200,products,result_source:'integrated'});
            return;
          }
          const url = new URL(menu.href, location.href);
          url.hash = 'rfserp';
          menu.href = url.href;
          menu.removeAttribute('target');
          finished = true;
          menu.click();
          return;
        }
        await sleep(250);
      }
      await send({ok:false,status:0,message:'통합검색 로딩 또는 상단 쇼핑 메뉴 표시를 기다리다 시간이 초과되었습니다.'});
    } finally { running = false; }
  }
  await navigate();
})();
