// Observe only: captcha entry and submission belong to the person.
chrome.runtime.onMessage.addListener((msg, sender, reply) => {
  if (msg?.type !== '__rfSerpPeek') return;
  const visible = el => el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden';
  const challengeVisible = [...document.querySelectorAll('input:not([type=hidden]):not([type=submit]):not([type=button])')].some(visible);
  reply({ok:false,captcha:true,challengeVisible});
});
