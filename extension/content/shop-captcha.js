// Only the top document (or a visible gate iframe observed by its parent) can assert a challenge.
chrome.runtime.onMessage.addListener((msg, sender, reply) => {
  if (msg?.type !== '__rfSerpPeek' || window.top !== window) return;
  reply({ok:false,...RfCaptcha.inspect()});
});
