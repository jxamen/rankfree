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

  function statusBox(ok) {
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
    return box;
  }

  function showStatus(message, ok) {
    var box = statusBox(ok);
    box.textContent = message;
  }

  // 저장 상태 박스 안에 정답 줄을 추가/갱신한다(박스 전체 색은 건드리지 않는다).
  function setAnswerStatus(message, ok) {
    var box = document.getElementById('rankfree-captcha-status');
    if (!box) {
      showStatus(message, ok);
      return;
    }
    var line = document.getElementById('rankfree-captcha-answer');
    if (!line) {
      line = document.createElement('div');
      line.id = 'rankfree-captcha-answer';
      line.style.marginTop = '6px';
      line.style.fontWeight = '700';
      box.appendChild(line);
    }
    line.style.color = ok === false ? '#9f1239' : '#047857';
    line.textContent = message;
  }

  // 폼 컨트롤용 가벼운 노출 판정(입력창은 높이가 낮을 수 있어 isVisible 대신 사용).
  function isShown(el) {
    if (!el || !el.getBoundingClientRect) return false;
    var rect = el.getBoundingClientRect();
    if (rect.width <= 0 || rect.height <= 0) return false;
    var style = getComputedStyle(el);
    return style.display !== 'none' && style.visibility !== 'hidden' && Number(style.opacity || 1) > 0;
  }

  // 정답 입력창 찾기: placeholder("정답을 입력해주세요.") 우선, 없으면 보이는 텍스트 입력.
  function findAnswerInput() {
    var byPlaceholder = document.querySelector('input[placeholder="정답을 입력해주세요."]');
    if (byPlaceholder && isShown(byPlaceholder)) return byPlaceholder;

    var inputs = Array.prototype.slice.call(document.querySelectorAll('input[type="text"], input:not([type])'));
    for (var i = 0; i < inputs.length; i++) {
      if (/정답/.test(String(inputs[i].getAttribute('placeholder') || '')) && isShown(inputs[i])) return inputs[i];
    }
    for (var j = 0; j < inputs.length; j++) {
      if (isShown(inputs[j])) return inputs[j];
    }
    return null;
  }

  // 제출(확인) 버튼 찾기: type=submit + "확인" 텍스트 우선.
  function findSubmitButton() {
    var submits = Array.prototype.slice.call(document.querySelectorAll('button[type="submit"]'));
    for (var i = 0; i < submits.length; i++) {
      if (isShown(submits[i]) && /확인/.test(textOf(submits[i]))) return submits[i];
    }
    if (submits.length === 1 && isShown(submits[0])) return submits[0];
    var buttons = Array.prototype.slice.call(document.querySelectorAll('button'));
    for (var j = 0; j < buttons.length; j++) {
      if (isShown(buttons[j]) && textOf(buttons[j]) === '확인') return buttons[j];
    }
    return null;
  }

  // React 제어 입력이라 .value 직접 대입은 무시된다 — native setter로 값을 넣는다.
  function setNativeInputValue(el, value) {
    try {
      var proto = window.HTMLInputElement && window.HTMLInputElement.prototype;
      var desc = proto && Object.getOwnPropertyDescriptor(proto, 'value');
      if (desc && desc.set) {
        desc.set.call(el, value);
        return;
      }
    } catch (e) { /* noop */ }
    el.value = value;
  }

  // 정답을 입력창에 넣고 확인 버튼을 클릭한다.
  function fillAndSubmitAnswer(answer) {
    var value = String(answer == null ? '' : answer).trim();
    if (!value) return false;

    var input = findAnswerInput();
    if (!input) {
      setAnswerStatus('정답: ' + value + ' (입력창을 찾지 못함)', false);
      return false;
    }

    try { input.focus(); } catch (e) { /* noop */ }
    setNativeInputValue(input, value);
    try {
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    } catch (e) { /* noop */ }

    // React 상태 반영(버튼 활성화 포함) 후 제출.
    setTimeout(function () {
      var submit = findSubmitButton();
      if (!submit) {
        setAnswerStatus('정답 입력됨: ' + value + ' (확인 버튼을 찾지 못함)', false);
        return;
      }
      try {
        submit.click();
        setAnswerStatus('정답 "' + value + '" 입력 후 확인 클릭', true);
      } catch (e) {
        setAnswerStatus('확인 클릭 실패: ' + String((e && e.message) || e), false);
      }
    }, 200);
    return true;
  }

  // 저장 완료 후 질문+이미지를 서버(Gemini)로 보내 정답을 받아 입력·제출한다.
  function requestQuizSolve(question, imageData) {
    if (!question && !imageData) return;
    setAnswerStatus('정답 풀이 중...', true);
    try {
      chrome.runtime.sendMessage({
        type: 'solveQuiz',
        payload: { question: question || '', image_data: imageData || '' },
      }, function (res) {
        if (chrome.runtime.lastError) {
          setAnswerStatus('정답 풀이 실패: ' + chrome.runtime.lastError.message, false);
          return;
        }
        if (res && res.ok && res.data && res.data.answer) {
          setAnswerStatus('정답: ' + res.data.answer, true);
          fillAndSubmitAnswer(res.data.answer);
        } else {
          setAnswerStatus('정답 풀이 실패: ' + ((res && res.message) || 'unknown error'), false);
        }
      });
    } catch (e) {
      setAnswerStatus('정답 풀이 실패: ' + String((e && e.message) || e), false);
    }
  }

  function showSavedStatus(data, apiBase, fallbackQuestion) {
    data = data || {};
    var box = statusBox(true);
    box.textContent = '';

    var title = document.createElement('div');
    title.textContent = 'RankFree captcha saved:';
    box.appendChild(title);

    var question = String(data.question || fallbackQuestion || '').trim();
    var questionLine = document.createElement('div');
    questionLine.style.marginTop = '6px';
    questionLine.textContent = '질문: ' + (question || '확인 안 됨');
    box.appendChild(questionLine);

    if (data.image_url) {
      var linkLine = document.createElement('div');
      linkLine.style.marginTop = '6px';
      var link = document.createElement('a');
      link.href = data.image_url;
      link.target = '_blank';
      link.rel = 'noopener';
      link.textContent = '이미지 열기';
      link.style.color = '#047857';
      link.style.fontWeight = '700';
      linkLine.appendChild(link);
      box.appendChild(linkLine);
    }

    var path = data.absolute_path || data.path || '';
    if (path) {
      var pathLine = document.createElement('div');
      pathLine.style.marginTop = '6px';
      pathLine.textContent = path;
      box.appendChild(pathLine);
    }

    if (apiBase) {
      var apiLine = document.createElement('div');
      apiLine.textContent = 'API: ' + apiBase;
      box.appendChild(apiLine);
    }
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
        showSavedStatus(res.data, res.apiBase || '', question);
        requestQuizSolve(question, image.dataUrl);
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
