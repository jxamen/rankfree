/* Isolated bridge for the new shopping search document only. */
(() => {
  let snapshot = null;
  document.addEventListener('rankfree:shop-v3', event => {
    try {
      const data = JSON.parse(event.detail);
      if (data.source !== 'naver-v3' || data.page !== 1 || data.keyword !== new URL(location.href).searchParams.get('query') || data.products?.length !== 20) return;
      snapshot = data;
    } catch (_) {}
  });
  chrome.runtime.onMessage.addListener((msg, sender, reply) => {
    if (msg?.type !== '__rfSerpPeek') return;
    const detection = RfCaptcha.inspect();
    if (detection.captcha) { reply({ok:false,...detection}); return; }
    document.dispatchEvent(new CustomEvent('rankfree:shop-v3-read'));
    if (snapshot?.keyword !== new URL(location.href).searchParams.get('query')) snapshot = null;
    reply(snapshot ? {ok:true, status:200, ...snapshot, result_source:'shopping'} : {ok:false,pending:true});
  });
})();
