(function () {
  'use strict';

  var uploadedSignatures = new Set();
  var latestMeta = null;
  var scanTimer = null;

  document.addEventListener('rankfree:seller-captcha-meta', function (event) {
    try { latestMeta = JSON.parse(String(event.detail || '{}')); } catch (e) { latestMeta = null; }
  });

  function textOf(node) {
    return String((node && node.textContent) || '').replace(/\s+/g, ' ').trim();
  }

  function isVisible(el) {
    if (!el || !el.getBoundingClientRect) return false;
    var rect = el.getBoundingClientRect();
    if (rect.width < 40 || rect.height < 30) return false;
    var style = getComputedStyle(el);
    return style.display !== 'none' && style.visibility !== 'hidden' && Number(style.opacity || 1) > 0;
  }

  function popupInfo() {
    var pathMatch = location.pathname.match(/\/popup\/seller-info\/([^/]+)\/?([^/?#]*)/);
    var params = new URLSearchParams(location.search);
    var prevUrl = params.get('prevUrl') || '';
    var prevDecoded = prevUrl;
    try { prevDecoded = decodeURIComponent(prevUrl); } catch (e) { /* noop */ }

    var storeId = '';
    var storeMatch = prevDecoded.match(/(?:smartstore|brand)\.naver\.com\/([^/?#]+)/i);
    if (storeMatch) storeId = storeMatch[1];

    return {
      channelUid: pathMatch ? decodeURIComponent(pathMatch[1]) : '',
      sellerInfoType: (pathMatch && pathMatch[2]) || params.get('sellerInfoType') || 'profile',
      storeId: storeId,
      prevUrl: prevDecoded,
    };
  }

  function getMeta() {
    return new Promise(function (resolve) {
      var done = false;
      function finish(value) {
        if (done) return;
        done = true;
        document.removeEventListener('rankfree:seller-captcha-meta', onMeta);
        resolve(value || latestMeta || {});
      }
      function onMeta(event) {
        try { finish(JSON.parse(String(event.detail || '{}'))); } catch (e) { finish({}); }
      }

      document.addEventListener('rankfree:seller-captcha-meta', onMeta);
      try {
        var stored = JSON.parse(sessionStorage.getItem('rankfree:sellerCaptchaMeta') || 'null');
        if (stored) latestMeta = stored;
      } catch (e) { /* noop */ }
      try { document.dispatchEvent(new CustomEvent('rankfree:get-seller-captcha-meta')); } catch (e) { /* noop */ }
      setTimeout(function () { finish(latestMeta || {}); }, 250);
    });
  }

  function findQuestion() {
    var walker = document.createTreeWalker(document.body || document.documentElement, NodeFilter.SHOW_TEXT);
    var best = '';
    var node;
    while ((node = walker.nextNode())) {
      var text = String(node.nodeValue || '').replace(/\s+/g, ' ').trim();
      if (!text || text.length > 180) continue;
      if (text.indexOf('?') === -1 && text.indexOf('입니까') === -1) continue;
      if (/정답|입력|닫기|확인/.test(text)) continue;
      if (/영수증|구매|물건|개|상품|금액|합계/.test(text)) {
        best = text;
        break;
      }
    }
    if (best) return best;

    var bodyText = textOf(document.body);
    var match = bodyText.match(/([^.!?\n]{0,80}(?:영수증|구매|물건|상품|금액|합계)[^.!?\n]{0,120}(?:\?|입니까))/);
    return match ? match[1].trim() : '';
  }

  function imageCandidates() {
    var candidates = [];

    Array.prototype.forEach.call(document.images || [], function (img) {
      if (!isVisible(img)) return;
      var rect = img.getBoundingClientRect();
      var src = img.currentSrc || img.src || '';
      if (!src) return;
      candidates.push({ kind: 'url', url: src, area: rect.width * rect.height, element: img });
    });

    Array.prototype.forEach.call(document.querySelectorAll('canvas'), function (canvas) {
      if (!isVisible(canvas)) return;
      var rect = canvas.getBoundingClientRect();
      candidates.push({ kind: 'canvas', area: rect.width * rect.height, element: canvas });
    });

    Array.prototype.forEach.call(document.querySelectorAll('div,section,figure,span'), function (el) {
      if (!isVisible(el)) return;
      var rect = el.getBoundingClientRect();
      if (rect.width < 120 || rect.height < 60) return;
      var bg = getComputedStyle(el).backgroundImage || '';
      var match = bg.match(/^url\(["']?(.+?)["']?\)$/);
      if (match) candidates.push({ kind: 'url', url: match[1], area: rect.width * rect.height, element: el });
    });

    candidates.sort(function (a, b) { return b.area - a.area; });
    return candidates.slice(0, 5);
  }

  function blobToDataUrl(blob) {
    return new Promise(function (resolve, reject) {
      var reader = new FileReader();
      reader.onload = function () { resolve(String(reader.result || '')); };
      reader.onerror = function () { reject(reader.error || new Error('Failed to read image.')); };
      reader.readAsDataURL(blob);
    });
  }

  async function candidateToDataUrl(candidate) {
    if (!candidate) return '';
    if (candidate.kind === 'canvas') {
      try { return candidate.element.toDataURL('image/png'); } catch (e) { return ''; }
    }
    var url = candidate.url;
    if (!url) return '';
    if (url.indexOf('data:image/') === 0) return url;
    try {
      var res = await fetch(url, { credentials: 'include' });
      if (!res.ok) return '';
      var blob = await res.blob();
      if (!/^image\//i.test(blob.type || '')) return '';
      return await blobToDataUrl(blob);
    } catch (e) {
      return '';
    }
  }

  async function findImageData() {
    var candidates = imageCandidates();
    for (var i = 0; i < candidates.length; i++) {
      var dataUrl = await candidateToDataUrl(candidates[i]);
      if (dataUrl) return { dataUrl: dataUrl, sourceUrl: candidates[i].url || '' };
    }
    return null;
  }

  function keyFromUrl(url) {
    try {
      var parsed = new URL(url, location.href);
      return parsed.searchParams.get('key') ||
        parsed.searchParams.get('captchaKey') ||
        parsed.searchParams.get('captcha_key') ||
        '';
    } catch (e) {
      return '';
    }
  }

  function showStatus(message, ok) {
    var box = document.getElementById('rankfree-captcha-status');
    if (!box) {
      box = document.createElement('div');
      box.id = 'rankfree-captcha-status';
      box.style.cssText = [
        'position:fixed',
        'right:12px',
        'bottom:12px',
        'z-index:2147483647',
        'max-width:360px',
        'padding:10px 12px',
        'border-radius:8px',
        'box-shadow:0 8px 24px rgba(0,0,0,.18)',
        'font:12px/1.45 system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif',
        'white-space:pre-wrap',
      ].join(';');
      document.documentElement.appendChild(box);
    }
    box.style.background = ok === false ? '#fff1f2' : '#ecfdf5';
    box.style.color = ok === false ? '#9f1239' : '#065f46';
    box.style.border = ok === false ? '1px solid #fecdd3' : '1px solid #a7f3d0';
    box.textContent = message;
  }

  async function scanAndUpload(reason) {
    var info = popupInfo();
    if (!info.channelUid) return;

    var question = findQuestion();
    var pageText = textOf(document.body);
    var hasCaptchaCue = question ||
      pageText.indexOf('\uC601\uC218\uC99D') !== -1 ||
      pageText.indexOf('\uC815\uB2F5') !== -1 ||
      /captcha|quiz/i.test(pageText);
    if (!hasCaptchaCue) return;

    var image = await findImageData();
    if (!image || !image.dataUrl) return;

    var meta = await getMeta();
    var captchaKey = (meta && meta.captchaKey) || keyFromUrl(image.sourceUrl) || '';
    var signature = [info.channelUid, captchaKey, question, image.dataUrl.length].join('|');
    if (uploadedSignatures.has(signature)) return;
    uploadedSignatures.add(signature);

    showStatus('RankFree captcha image saving...', true);
    chrome.runtime.sendMessage({
      type: 'saveSellerCaptcha',
      payload: {
        store_id: info.storeId,
        channel_uid: info.channelUid,
        channel_id: (meta && meta.channelId) || '',
        captcha_key: captchaKey,
        seller_info_type: (meta && meta.sellerInfoType) || info.sellerInfoType || 'profile',
        question: question,
        image_data: image.dataUrl,
        seller_info_url: location.href,
        prev_url: info.prevUrl,
      },
    }, function (res) {
      if (chrome.runtime.lastError) {
        showStatus('RankFree captcha save failed:\n' + chrome.runtime.lastError.message, false);
        return;
      }
      if (res && res.ok && res.data) {
        showStatus('RankFree captcha saved:\n' + (res.data.absolute_path || res.data.path) + (res.apiBase ? '\nAPI: ' + res.apiBase : ''), true);
        try {
          chrome.runtime.sendMessage({
            type: '__sellerCaptchaCaptured',
            ok: true,
            channelUid: info.channelUid,
            data: res.data,
            apiBase: res.apiBase || '',
          });
        } catch (e) { /* noop */ }
      } else {
        showStatus('RankFree captcha save failed:\n' + ((res && res.message) || 'unknown error') + (res && res.apiBase ? '\nAPI: ' + res.apiBase : ''), false);
        try {
          chrome.runtime.sendMessage({
            type: '__sellerCaptchaCaptured',
            ok: false,
            channelUid: info.channelUid,
            message: (res && res.message) || 'unknown error',
            apiBase: (res && res.apiBase) || '',
          });
        } catch (e) { /* noop */ }
      }
    });
  }

  function scheduleScan(reason) {
    clearTimeout(scanTimer);
    scanTimer = setTimeout(function () { scanAndUpload(reason); }, 250);
  }

  scheduleScan('load');
  setTimeout(function () { scheduleScan('late-load'); }, 1200);
  setTimeout(function () { scheduleScan('late-load-2'); }, 3000);

  try {
    var observer = new MutationObserver(function () { scheduleScan('mutation'); });
    observer.observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['src', 'style', 'class'] });
  } catch (e) { /* noop */ }
})();
