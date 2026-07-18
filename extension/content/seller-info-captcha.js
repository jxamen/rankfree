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
    positionCardAboveSubmit(box);
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

  // 새로고침 버튼 찾기(오답·불명확 질문 시 새 캡차 요청용). blind 텍스트가 '새로고침'.
  function findRefreshButton() {
    var buttons = Array.prototype.slice.call(document.querySelectorAll('button'));
    for (var i = 0; i < buttons.length; i++) {
      if (isShown(buttons[i]) && /새로\s*고침|refresh/i.test(textOf(buttons[i]))) return buttons[i];
    }
    return null;
  }

  var refreshCount = 0;
  var givenUp = false;          // 재시도 3회 초과 시 포기 — 이후 풀이/새로고침 중단
  var MAX_RETRIES = 3;          // 캡차당 최대 새로고침(재시도) 횟수

  // 질문이 알려진 유형(개수/금액)인지 — 키워드가 명확히 보일 때만 풀이한다.
  //  개수: 몇 개·개수·수량·합계 / 금액: 구매 금액·금액·얼마·토탈·총합 (공통: 합)
  function questionIsRecognized(q) {
    q = String(q || '');
    var quantity = /몇\s*개|개수|수량|합계/.test(q);
    var amount = /구매\s*금액|총\s*금액|금액|얼마|토탈|총합/.test(q);
    return quantity || amount || /합/.test(q);
  }

  // 새 캡차 요청. 오답·풀이지연·질문불명확이 모두 이 경로를 쓰며, 최대 3회까지만.
  function tryRefresh(reason) {
    if (refreshCount >= MAX_RETRIES) {
      givenUp = true;
      setAnswerStatus('재시도 ' + MAX_RETRIES + '회 실패 — 수동 확인이 필요합니다. (' + reason + ')', false);
      // 재시도 소진 → 배경에 실패 신호(대량수집이 다음 상품으로 넘어가고 탭 정리).
      try {
        var g = popupInfo();
        chrome.runtime.sendMessage({ type: '__sellerCaptchaCaptured', ok: false, channelUid: g.channelUid, message: 'retries exhausted' });
      } catch (e) { /* noop */ }
      return false;
    }
    var refresh = findRefreshButton();
    if (!refresh) return false;
    refreshCount++;
    setAnswerStatus(reason + ' — 새로고침 재시도 (' + refreshCount + '/' + MAX_RETRIES + ')', false);
    fireClick(refresh);   // 새 캡차 → MutationObserver가 감지해 재검사/재풀이
    return true;
  }

  // 제출 후 결과 확인: 통과(판매자정보 렌더)면 종료, 아직 캡차면 오답 → 새로고침 재시도.
  function checkAnswerResult() {
    setTimeout(function () {
      if (textOf(document.body).indexOf('사업자등록번호') !== -1) return; // 통과
      if (!findAnswerInput()) return; // 화면 전환 중 — 대기
      tryRefresh('오답');
    }, 2800);
  }

  // 상태 카드를 확인(제출) 버튼 위쪽에 배치해 버튼을 가리지 않게 한다.
  function positionCardAboveSubmit(box) {
    if (!box) return;
    box.style.right = '12px';
    box.style.left = 'auto';
    box.style.top = 'auto';
    var submit = findSubmitButton();
    if (submit) {
      var rect = submit.getBoundingClientRect();
      box.style.bottom = Math.max(12, Math.round(window.innerHeight - rect.top + 10)) + 'px';
    } else {
      box.style.bottom = '12px';
    }
  }

  // React 제어 입력: native setter 로 값을 넣고, _valueTracker 를 이전 값으로 되돌려
  // React 가 '값이 바뀌었다'고 인식(onChange 발화)하게 한다. (native setter만으론 무시됨)
  function setNativeInputValue(el, value) {
    var last = '';
    try { last = el.value; } catch (e) { /* noop */ }
    try {
      var proto = window.HTMLInputElement && window.HTMLInputElement.prototype;
      var desc = proto && Object.getOwnPropertyDescriptor(proto, 'value');
      if (desc && desc.set) {
        desc.set.call(el, value);
      } else {
        el.value = value;
      }
    } catch (e) {
      try { el.value = value; } catch (e2) { /* noop */ }
    }
    try {
      if (el._valueTracker && typeof el._valueTracker.setValue === 'function') {
        el._valueTracker.setValue(last);
      }
    } catch (e) { /* noop */ }
  }

  // 실제 사용자 클릭에 가깝게 이벤트를 순서대로 발생시킨다(단순 .click()으로 반응 안 하는 핸들러 대비).
  function fireClick(el) {
    var opts = { bubbles: true, cancelable: true, view: window };
    ['pointerdown', 'mousedown', 'pointerup', 'mouseup'].forEach(function (type) {
      try { el.dispatchEvent(new MouseEvent(type, opts)); } catch (e) { /* noop */ }
    });
    try { el.click(); } catch (e) {
      try { el.dispatchEvent(new MouseEvent('click', opts)); } catch (e2) { /* noop */ }
    }
  }

  // 모델이 설명을 덧붙여도(예: "...합계는 4700") 실제 정답 숫자만 뽑아낸다.
  function extractAnswerValue(answer) {
    var t = String(answer == null ? '' : answer).trim();
    if (!t) return '';
    // 전체가 숫자(쉼표·공백 포함)면 숫자만 남긴다.
    if (/^[\d,\s]+$/.test(t)) return t.replace(/[^\d]/g, '');
    // 설명이 섞였으면 마지막 비어있지 않은 줄부터 훑어 숫자를 찾는다(정답을 끝에 두는 경향).
    var lines = t.split(/\r?\n/);
    for (var i = lines.length - 1; i >= 0; i--) {
      var line = lines[i].trim();
      if (!line) continue;
      var m = line.match(/-?\d[\d,]*/g);
      if (m && m.length) return m[m.length - 1].replace(/,/g, '');
    }
    return t;
  }

  // 정답을 입력창에 넣고 확인 버튼을 클릭한다.
  function fillAndSubmitAnswer(answer) {
    var value = extractAnswerValue(answer);
    if (!value) return false;

    var input = findAnswerInput();
    if (!input) {
      setAnswerStatus('정답: ' + value + ' (입력창을 찾지 못함)', false);
      return false;
    }

    var lastChar = value.slice(-1);
    try { input.focus(); } catch (e) { /* noop */ }
    setNativeInputValue(input, value);
    try {
      input.dispatchEvent(new KeyboardEvent('keydown', { bubbles: true, key: lastChar }));
      try {
        input.dispatchEvent(new InputEvent('input', { bubbles: true, data: value, inputType: 'insertText' }));
      } catch (e2) {
        input.dispatchEvent(new Event('input', { bubbles: true }));
      }
      input.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true, key: lastChar }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    } catch (e) { /* noop */ }

    // React 상태 반영(버튼 활성화 포함) 후 제출.
    setTimeout(function () {
      var submit = findSubmitButton();
      if (!submit) {
        setAnswerStatus('정답 입력됨: ' + value + ' (확인 버튼을 찾지 못함)', false);
        return;
      }
      try { input.focus(); } catch (e) { /* noop */ }

      // 버튼이 아직 비활성이면(=React가 입력을 아직 못 받음) Enter 제출 + form.requestSubmit 폴백.
      if (submit.disabled) {
        try {
          input.dispatchEvent(new KeyboardEvent('keydown', { bubbles: true, key: 'Enter', code: 'Enter', keyCode: 13, which: 13 }));
          input.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true, key: 'Enter', code: 'Enter', keyCode: 13, which: 13 }));
        } catch (e) { /* noop */ }
        var form = submit.form || (input.closest && input.closest('form'));
        if (form) {
          try { form.requestSubmit ? form.requestSubmit(submit) : form.submit(); } catch (e) { /* noop */ }
        }
        // 잠깐 뒤 다시 활성화됐으면 클릭.
        setTimeout(function () {
          var s2 = findSubmitButton();
          if (s2 && !s2.disabled) fireClick(s2);
        }, 200);
        setAnswerStatus('정답 "' + value + '" 입력(버튼 비활성 → Enter 제출 시도)', true);
        checkAnswerResult();
        return;
      }

      fireClick(submit);
      setAnswerStatus('정답 "' + value + '" 입력 후 확인 클릭', true);
      checkAnswerResult();
    }, 300);
    return true;
  }

  // 서버 throttle·과다호출 방지: 최소 호출 간격.
  var lastSolveAt = 0;
  var solveTimeoutMs = 10000; // 정답 대기 시간(환경설정에서 받아 덮어씀)

  // 저장 완료 후 질문+이미지를 서버로 보내 정답을 받아 입력·제출한다.
  function requestQuizSolve(question, imageData) {
    if (!question && !imageData) return;
    if (givenUp) return;                              // 재시도 3회 소진 → 중단
    var now = Date.now();
    if (now - lastSolveAt < 3000) return;           // 최소 3초 간격
    lastSolveAt = now;
    setAnswerStatus('정답 풀이 중...', true);

    // 대기 시간 안에 응답이 없으면 이 요청은 버리고(늦게 오면 무시) 새 캡차로 교체(재시도).
    var settled = false;
    var waitSec = Math.round(solveTimeoutMs / 1000);
    var timer = setTimeout(function () {
      if (settled) return;
      settled = true;
      tryRefresh('풀이 지연(' + waitSec + '초)');
    }, solveTimeoutMs);

    try {
      chrome.runtime.sendMessage({
        type: 'solveQuiz',
        payload: { question: question || '', image_data: imageData || '' },
      }, function (res) {
        if (settled) return;              // 이미 타임아웃 처리됨 — 늦게 온 응답 무시
        settled = true;
        clearTimeout(timer);
        if (chrome.runtime.lastError) {
          setAnswerStatus('정답 풀이 실패: ' + chrome.runtime.lastError.message, false);
          return;
        }
        if (res && res.ok && res.data && res.data.answer) {
          setAnswerStatus('정답: ' + extractAnswerValue(res.data.answer), true);
          fillAndSubmitAnswer(res.data.answer);
        } else {
          setAnswerStatus('정답 풀이 실패: ' + ((res && res.message) || 'unknown error'), false);
        }
      });
    } catch (e) {
      if (!settled) {
        settled = true;
        clearTimeout(timer);
        setAnswerStatus('정답 풀이 실패: ' + String((e && e.message) || e), false);
      }
    }
  }

  function showSavedStatus(data, apiBase, fallbackQuestion) {
    data = data || {};
    var box = statusBox(true);
    box.textContent = '';

    if (data.image_url) {
      var linkLine = document.createElement('div');
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

    positionCardAboveSubmit(box);
  }

  // OCR 정확도 개선: 캡차 이미지를 factor 배로 확대(고품질 스무딩)해 새 dataURL 로 반환.
  function upscaleDataUrl(dataUrl, factor) {
    return new Promise(function (resolve) {
      try {
        var img = new Image();
        img.onload = function () {
          try {
            var w = Math.max(1, Math.round((img.naturalWidth || img.width) * factor));
            var h = Math.max(1, Math.round((img.naturalHeight || img.height) * factor));
            var canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = h;
            var ctx = canvas.getContext('2d');
            ctx.imageSmoothingEnabled = true;
            ctx.imageSmoothingQuality = 'high';
            ctx.drawImage(img, 0, 0, w, h);
            resolve(canvas.toDataURL('image/png'));
          } catch (e) { resolve(dataUrl); }
        };
        img.onerror = function () { resolve(dataUrl); };
        img.src = dataUrl;
      } catch (e) { resolve(dataUrl); }
    });
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

    // 질문 유형(개수/금액)이 명확히 안 보이면 새 캡차로 교체한다(불명확한 문제는 오답 위험).
    if (!questionIsRecognized(question)) {
      tryRefresh('질문 불명확');
      return;
    }

    // 캡차 질문/이미지는 서버에 기록하지 않는다.
    // OCR 정확도 위해 이미지를 2배 확대해 풀이 API로 전달한다.
    var solveImage = await upscaleDataUrl(image.dataUrl, 2);
    requestQuizSolve(question, solveImage);
  }

  // ── 판매자(사업자) 정보 파싱·저장 — 캡차 통과 후 표시되는 정보를 업체별 저장 ──
  var sentSellerInfoSignatures = new Set();

  // 요소의 '직접' 텍스트(자식 요소 제외).
  function directText(el) {
    if (!el) return '';
    var s = '';
    for (var i = 0; i < el.childNodes.length; i++) {
      if (el.childNodes[i].nodeType === 3) s += el.childNodes[i].nodeValue;
    }
    return s.replace(/\s+/g, ' ').trim();
  }

  // 값에 섞이는 버튼/부가 텍스트 제거.
  function sellerNoise(v) {
    return String(v || '')
      .replace(/인증완료|인증|잘못된\s*번호\s*신고|번호\s*신고|신고하기|복사하기|복사|자세히\s*보기|자세히/g, '')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function pickPhone(v) {
    var m = String(v || '').match(/0\d{1,2}[-.\s]?\d{3,4}[-.\s]?\d{4}/);
    return m ? m[0].replace(/[.\s]/g, '-') : '';
  }
  function pickEmail(v) {
    // \w 로 밑줄 포함(bennyong_store 같은 로컬파트)
    var m = String(v || '').match(/[\w.%+-]+@[\w.-]+\.[A-Za-z]{2,}/);
    return m ? m[0] : '';
  }
  function pickBizNo(v) {
    // 하이픈 표기(546-07-01862)·무하이픈 모두 10자리 숫자로 정규화
    var digits = String(v || '').replace(/\D/g, '');
    return digits.length >= 10 ? digits.slice(0, 10) : '';
  }

  function readValueNode(node, labelEl) {
    if (!node || node === labelEl) return '';
    return sellerNoise(textOf(node));
  }

  // 라벨 텍스트에 해당하는 값 추출: 형제 → 부모형제 → 같은 행 → 부모텍스트 순.
  function valueForLabel(label) {
    var candidates = document.querySelectorAll('th, dt, td, dd, span, div, strong, b, li, p');
    for (var i = 0; i < candidates.length; i++) {
      var el = candidates[i];
      if (directText(el) !== label && textOf(el) !== label) continue;

      var v = readValueNode(el.nextElementSibling, el);
      if (v) return v;

      if (el.parentElement) {
        v = readValueNode(el.parentElement.nextElementSibling, el);
        if (v) return v;
      }

      var row = el.closest('tr, li, div');
      if (row) {
        var cells = row.querySelectorAll('td, dd, span, div, p');
        for (var j = 0; j < cells.length; j++) {
          if (cells[j] === el || cells[j].contains(el) || el.contains(cells[j])) continue;
          v = readValueNode(cells[j], el);
          if (v) return v;
        }
      }

      if (el.parentElement) {
        var rest = sellerNoise(textOf(el.parentElement).replace(label, ''));
        if (rest) return rest;
      }
    }
    return '';
  }

  function firstValue(labels) {
    for (var i = 0; i < labels.length; i++) {
      var v = valueForLabel(labels[i]);
      if (v) return v;
    }
    return '';
  }

  function collectSellerRaw() {
    var labels = ['상호명', '상호', '대표자', '대표자명', '고객센터', '전화번호', '연락처',
      '사업자등록번호', '사업장 소재지', '사업장소재지', '영업소재지', '통신판매업번호',
      '통신판매업신고번호', 'e-mail', 'E-mail', '이메일'];
    var out = {};
    for (var i = 0; i < labels.length; i++) {
      var v = valueForLabel(labels[i]);
      if (v && !out[labels[i]]) out[labels[i]] = v.slice(0, 300);
    }
    return out;
  }

  function setSellerInfoStatus(message, ok) {
    var box = statusBox(true);
    var line = document.getElementById('rankfree-seller-info');
    if (!line) {
      line = document.createElement('div');
      line.id = 'rankfree-seller-info';
      line.style.marginTop = '6px';
      line.style.fontWeight = '700';
      box.appendChild(line);
    }
    line.style.color = ok === false ? '#9f1239' : '#047857';
    line.textContent = message;
  }

  function scanSellerInfo() {
    var info = popupInfo();
    if (!info.channelUid) return;

    var pageText = textOf(document.body);
    // 판매자정보가 '완전히' 렌더된 뒤에만 저장 — 사업자등록번호가 나와야 전체 블록 로드로 본다.
    // (상호명만 먼저 뜬 시점에 조기 저장돼 나머지가 비던 문제 방지)
    if (pageText.indexOf('사업자등록번호') === -1) return;

    var fields = {
      biz_name: sellerNoise(firstValue(['상호명', '상호'])),
      representative: sellerNoise(firstValue(['대표자', '대표자명'])),
      customer_phone: pickPhone(firstValue(['고객센터', '전화번호', '연락처'])),
      biz_reg_no: pickBizNo(firstValue(['사업자등록번호'])),
      address: sellerNoise(firstValue(['사업장 소재지', '사업장소재지', '영업소재지'])),
      mail_order_no: sellerNoise(firstValue(['통신판매업번호', '통신판매업신고번호'])),
      email: pickEmail(firstValue(['e-mail', 'E-mail', '이메일'])),
    };

    // 완전한 정보(사업자등록번호 + 상호명)가 있을 때만 저장한다.
    if (!fields.biz_reg_no || !fields.biz_name) return;

    var sig = [info.channelUid, fields.biz_reg_no, fields.biz_name].join('|');
    if (sentSellerInfoSignatures.has(sig)) return;
    sentSellerInfoSignatures.add(sig);

    var payload = Object.assign({
      store_id: info.storeId,
      channel_uid: info.channelUid,
      channel_id: (latestMeta && latestMeta.channelId) || '',
      seller_info_url: location.href,
      raw: collectSellerRaw(),
    }, fields);

    setSellerInfoStatus('판매자정보 저장 중...', true);
    chrome.runtime.sendMessage({ type: 'saveSellerInfo', payload: payload }, function (res) {
      if (chrome.runtime.lastError) {
        setSellerInfoStatus('판매자정보 저장 실패: ' + chrome.runtime.lastError.message, false);
        sentSellerInfoSignatures.delete(sig);
        return;
      }
      if (res && res.ok) {
        setSellerInfoStatus('판매자정보 저장됨: ' + (fields.biz_name || fields.biz_reg_no || '완료'), true);
        // 판매자정보 확보 = 이 상품 수집 완료 → 대량수집이 다음 상품으로 넘어가도록 신호.
        try {
          chrome.runtime.sendMessage({ type: '__sellerCaptchaCaptured', ok: true, channelUid: info.channelUid, data: res.data || null });
        } catch (e) { /* noop */ }
        // 저장 완료 → 잠깐 상태 보여준 뒤 이 탭을 닫는다.
        setTimeout(function () {
          try { chrome.runtime.sendMessage({ type: 'closeSellerTab' }); } catch (e) { /* noop */ }
        }, 1500);
      } else {
        setSellerInfoStatus('판매자정보 저장 실패: ' + ((res && res.message) || 'unknown error'), false);
        sentSellerInfoSignatures.delete(sig); // 실패 시 재시도 허용
      }
    });
  }

  function scheduleScan(reason) {
    clearTimeout(scanTimer);
    scanTimer = setTimeout(function () { scanAndUpload(reason); scanSellerInfo(); }, 250);
  }

  function startScanning() {
    scheduleScan('load');
    setTimeout(function () { scheduleScan('late-load'); }, 1200);
    setTimeout(function () { scheduleScan('late-load-2'); }, 3000);
    try {
      var observer = new MutationObserver(function () { scheduleScan('mutation'); });
      observer.observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['src', 'style', 'class'] });
    } catch (e) { /* noop */ }
  }

  // 관리자(슈퍼) 수집이 연 탭에서만 동작한다. 일반 방문이면 아무 동작·경고도 하지 않는다.
  try {
    chrome.runtime.sendMessage({ type: 'isSellerCaptchaTab' }, function (res) {
      if (chrome.runtime.lastError || !res || !res.allowed) return;
      if (res.solveTimeout && Number(res.solveTimeout) > 0) solveTimeoutMs = Number(res.solveTimeout) * 1000;
      startScanning();
    });
  } catch (e) { /* noop */ }
})();
