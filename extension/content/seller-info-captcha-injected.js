(function () {
  'use strict';

  var STORAGE_KEY = 'rankfree:sellerCaptchaMeta';
  var lastMeta = null;

  function publish(meta) {
    if (!meta) return;
    lastMeta = Object.assign({}, lastMeta || {}, meta, { at: Date.now() });
    try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify(lastMeta)); } catch (e) { /* noop */ }
    try {
      document.dispatchEvent(new CustomEvent('rankfree:seller-captcha-meta', {
        detail: JSON.stringify(lastMeta),
      }));
    } catch (e) { /* noop */ }
  }

  function inspectUrl(rawUrl) {
    try {
      if (!rawUrl) return;
      var url = new URL(String(rawUrl), location.href);
      if (!/captcha/i.test(url.href)) return;

      var key = url.searchParams.get('key') ||
        url.searchParams.get('captchaKey') ||
        url.searchParams.get('captcha_key') ||
        '';
      var sellerInfoType = url.searchParams.get('sellerInfoType') || '';

      publish({
        captchaUrl: url.href,
        captchaKey: key,
        sellerInfoType: sellerInfoType,
      });
    } catch (e) { /* noop */ }
  }

  document.addEventListener('rankfree:get-seller-captcha-meta', function () {
    if (!lastMeta) {
      try {
        lastMeta = JSON.parse(sessionStorage.getItem(STORAGE_KEY) || 'null');
      } catch (e) { lastMeta = null; }
    }
    publish(lastMeta || {});
  });

  try {
    var origFetch = window.fetch;
    window.fetch = function (input, init) {
      try {
        inspectUrl(typeof input === 'string' ? input : (input && input.url));
      } catch (e) { /* noop */ }
      return origFetch.apply(this, arguments).then(function (response) {
        try { inspectUrl(response && response.url); } catch (e) { /* noop */ }
        return response;
      });
    };
  } catch (e) { /* noop */ }

  try {
    var origOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method, url) {
      try { inspectUrl(url); } catch (e) { /* noop */ }
      return origOpen.apply(this, arguments);
    };
  } catch (e) { /* noop */ }

  try {
    setTimeout(function () {
      var entries = performance && performance.getEntriesByType ? performance.getEntriesByType('resource') : [];
      for (var i = 0; i < entries.length; i++) inspectUrl(entries[i].name);
    }, 500);
  } catch (e) { /* noop */ }
})();
