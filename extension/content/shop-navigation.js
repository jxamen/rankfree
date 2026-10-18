// Collection-only navigation: use Naver's rendered shopping menu, never a hidden API.
(async () => {
  if (location.hash !== '#rfnav') return;
  const query = new URL(location.href).searchParams.get('query') || '';
  const fallback = 'https://msearch.shopping.naver.com/search/all?query=' + encodeURIComponent(query);
  const blocked = () => /보안문자|자동\s*입력\s*방지|비정상적인\s*(검색|접근)|접근이\s*제한/.test(document.body?.innerText || '');
  for (let attempt = 0; attempt < 20; attempt++) {
    if (blocked()) {
      await chrome.runtime.sendMessage({type:'__rfSerpRead',result:{ok:false,captcha:true,status:200}});
      return;
    }
    const links = [...document.querySelectorAll('a[href]')];
    const menu = links.find(a => (a.getAttribute('role') === 'tab' || a.closest('nav, [role=tablist]')) && a.textContent.trim() === '쇼핑' && new URL(a.href, location.href).hostname.endsWith('.naver.com'));
    if (menu) {
      const target = new URL(menu.href, location.href);
      target.hash = 'rfserp';
      menu.href = target.href;
      menu.removeAttribute('target');
      menu.click();
      // Some menu handlers cancel navigation; retain the actual link destination.
      setTimeout(() => { if (location.hash === '#rfnav') location.assign(target.href); }, 1000);
      return;
    }
    await new Promise(resolve => setTimeout(resolve, 250));
  }
  if (!blocked()) location.assign(fallback + '#rfserp');
})();
