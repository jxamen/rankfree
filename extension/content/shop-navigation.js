// Collection-only navigation: use Naver's rendered shopping menu, never a hidden API.
(async () => {
  if (location.hash !== '#rfnav') return;
  const blocked = () => /보안문자|자동\s*입력\s*방지|비정상적인\s*(검색|접근)|접근이\s*제한/.test(document.body?.innerText || '');
  let running = false;
  chrome.runtime.onMessage.addListener((msg, sender, reply) => {
    if (msg?.type !== '__rfNavResume') return;
    reply({ok: false});
    if (!blocked() && !running) navigate();
  });
  async function navigate() {
    running = true;
    try {
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
          return;
        }
        await new Promise(resolve => setTimeout(resolve, 250));
      }
      await chrome.runtime.sendMessage({type:'__rfSerpRead',result:{ok:false,status:0,message:'상단 쇼핑 메뉴를 찾지 못했습니다.'}});
    } finally { running = false; }
  }
  await navigate();
})();
