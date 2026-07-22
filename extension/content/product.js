/**
 * RankFree 상품 분석 — 스마트스토어/브랜드스토어 상품 페이지 content script.
 *
 * 리뷰 API(/i/v1/contents/reviews/query-pages)를 사용자 세션으로 호출해:
 *  - pageSize 프로브(20 기본 → 100/50/30 순 시도, 실제 최대치 측정)
 *  - 최신순 리뷰 병렬 수집(동시 4요청) → 최근 7일/1개월/3개월 리뷰수, 재구매율, 옵션별 판매 비중, 평점 분포
 *  - 평점 낮은순 리뷰 수집 → 빈출 단어 기반 약점 분석
 * rankfree 로그인 게이트는 검색 패널과 동일(background 공유).
 */
(function () {
  'use strict';

  const state = {
    loggedIn: false,
    user: null,
    storeHeaders: null, // 페이지가 쓰는 x-client-* 헤더 (injected-store.js 캡처)
    capturedParams: null, // 페이지 리뷰 요청에서 캡처한 merchantNo/originProductNo
    reviewReq: null, // 페이지 실제 리뷰 요청 descriptor {url, method, body, headers} — 그대로 재현
    qnaRequest: null, // 페이지 문의 요청 descriptor {method, url, body}
    target: 500, // 분석할 리뷰 수
    loading: false,
    progress: null,
    error: null,
    result: null, // 분석 결과
    price: null, // 페이지에서 추출한 판매가
    sales6m: '', // 사용자 입력 6개월 판매량(옵션별 예상 판매·매출용)
    savedId: null,
    saveLimitMsg: null,
    tab: 'analyze', // analyze | seller | history
    history: undefined,
    historyLoading: false,
    snapshot: null, // 저장본 열람
    sp: { keyword: '', loading: false, step: '', result: null, error: null, savedId: null }, // 셀러력
  };

  document.addEventListener('rankfree:store-headers', (e) => {
    try {
      state.storeHeaders = JSON.parse(String(e.detail));
    } catch (err) {
      /* noop */
    }
  });

  // 페이지가 직접 보낸 리뷰 요청 body — 가장 정확한 파라미터 소스
  document.addEventListener('rankfree:store-params', (e) => {
    try {
      const p = JSON.parse(String(e.detail));
      if (p && p.originProductNo) {
        state.capturedParams = {
          merchantNo: Number(p.checkoutMerchantNo) || null,
          originProductNo: Number(p.originProductNo),
        };
        console.debug('[RankFree] 리뷰 파라미터 캡처:', state.capturedParams);
      }
    } catch (err) {
      /* noop */
    }
  });

  // 페이지가 보낸 리뷰 요청 — url/body/headers 통째로 저장해 page/정렬만 바꿔 재현.
  // 리뷰 '목록' 요청(query-pages)만 저장한다. summary-tag/evaluations 등 부가 요청은 무시
  // (그걸로 덮어쓰면 재현 대상이 리뷰 목록이 아니게 됨).
  document.addEventListener('rankfree:review-req', (e) => {
    try {
      const q = JSON.parse(String(e.detail));
      const url = String(q.url || '');
      if (!/\/reviews\/[^?]*query-pages/.test(url)) return; // 리뷰 목록 요청만
      let body = null;
      try { body = q.body ? JSON.parse(q.body) : null; } catch (er) { body = null; }
      // 리뷰 목록 요청은 page/pageSize를 body에 가진다. 없으면(잘못된 후보) 무시.
      if (body && typeof body === 'object' && ('page' in body || 'pageSize' in body)) {
        state.reviewReq = { url: url.split('#')[0], method: q.method || 'POST', body, headers: q.headers || null };
        console.log('[RankFree] 리뷰 목록 요청 캡처:', state.reviewReq.url, '| body keys:', Object.keys(body).join(','));
      }
    } catch (err) {
      /* noop */
    }
  });

  // 페이지가 보낸 상품 문의(QnA) 요청 — 엔드포인트 재사용용
  document.addEventListener('rankfree:store-qna', (e) => {
    try {
      const q = JSON.parse(String(e.detail));
      if (q && q.url) {
        state.qnaRequest = q;
        console.debug('[RankFree] QnA 요청 캡처:', q.method, q.url);
      }
    } catch (err) {
      /* noop */
    }
  });

  // ------------------------------------------------------------------
  // 유틸 (검색 패널과 동일 규약)
  // ------------------------------------------------------------------
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function num(v) {
    if (v == null || v === '') return 0;
    const n = Number(String(v).replace(/[^0-9.\-]/g, ''));
    return isFinite(n) ? n : 0;
  }

  function comma(n) {
    return Math.round(n).toLocaleString('ko-KR');
  }

  function krw(n) {
    n = Math.round(n);
    const abs = Math.abs(n);
    if (abs >= 1e8) return (n / 1e8).toFixed(abs >= 1e9 ? 0 : 1).replace(/\.0$/, '') + '억';
    if (abs >= 1e4) return comma(n / 1e4) + '만';
    return comma(n);
  }

  function h(html) {
    const t = document.createElement('template');
    t.innerHTML = html.trim();
    return t.content.firstElementChild;
  }

  function sendBg(type, payload) {
    return new Promise((resolve) => {
      try {
        chrome.runtime.sendMessage({ type, payload }, (res) => {
          if (chrome.runtime.lastError) resolve({ ok: false, message: chrome.runtime.lastError.message });
          else resolve(res || { ok: false, message: '응답 없음' });
        });
      } catch (e) {
        resolve({ ok: false, message: String(e.message || e) });
      }
    });
  }

  // ------------------------------------------------------------------
  // 상품 파라미터 (checkoutMerchantNo / originProductNo)
  // ------------------------------------------------------------------
  function getProductParams() {
    // 1) 페이지가 직접 보낸 리뷰 요청에서 캡처한 값 (가장 정확)
    if (state.capturedParams && state.capturedParams.merchantNo && state.capturedParams.originProductNo) {
      return state.capturedParams;
    }

    // 2) 페이지 스크립트(__PRELOADED_STATE__ 등)에서 정규식 추출 — 따옴표 유무 모두 허용
    let merchantNo = null;
    let originProductNo = null;

    for (const s of document.querySelectorAll('script')) {
      const t = s.textContent;
      if (!t || t.length < 1000) continue;
      if (merchantNo === null) {
        const m =
          t.match(/"checkoutMerchantNo"\s*:\s*"?(\d+)"?/) ||
          t.match(/"payReferenceKey"\s*:\s*"?(\d+)"?/);
        if (m) merchantNo = Number(m[1]);
      }
      if (originProductNo === null) {
        const m = t.match(/"originProductNo"\s*:\s*"?(\d+)"?/);
        if (m) originProductNo = Number(m[1]);
      }
      if (merchantNo !== null && originProductNo !== null) break;
    }

    // 3) 최후 폴백 — URL 상품번호(채널상품번호라 originProductNo 와 다를 수 있음)
    let urlFallback = false;
    if (originProductNo === null) {
      const m = location.pathname.match(/\/products\/(\d+)/);
      if (m) {
        originProductNo = Number(m[1]);
        urlFallback = true;
      }
    }

    if (merchantNo === null || originProductNo === null) {
      console.warn('[RankFree] 상품 파라미터 추출 실패(동기):', { merchantNo, originProductNo });
      return null;
    }
    console.debug('[RankFree] 상품 파라미터:', { merchantNo, originProductNo, urlFallback });
    return { merchantNo, originProductNo, urlFallback };
  }

  /**
   * 상품 파라미터 async 확보 — 동기 실패 시 MAIN world의 __PRELOADED_STATE__ 폴백.
   * (SPA hydration으로 script 태그가 제거된 경우 checkoutMerchantNo를 여기서만 얻을 수 있음)
   */
  async function getProductParamsAsync() {
    const sync = getProductParams();
    if (sync) return sync;
    const pre = await getMyPreloadedAsync();
    if (!pre) return null;
    const A = (pre.product && (pre.product.A || pre.product)) || {};
    const ch = (pre.smartStoreV2 && pre.smartStoreV2.channel) || {};
    const origin = Number(A.originProductNo || A.productNo || A.id) || null;
    const merchant = Number(ch.naverPaySellerNo || ch.payReferenceKey || A.checkoutMerchantNo) || null;
    if (!origin || !merchant) {
      console.warn('[RankFree] 상품 파라미터 추출 실패(async):', { merchant, origin });
      return null;
    }
    return { merchantNo: merchant, originProductNo: origin, urlFallback: false };
  }

  function getProductName() {
    const og = document.querySelector('meta[property="og:title"]');
    return (og && og.content) || document.title.replace(/\s*:\s*.*스토어.*$/, '') || '';
  }

  function getStoreName() {
    const m = location.pathname.match(/^\/([^/]+)\/products/);
    return m ? m[1] : '';
  }

  /** 채널상품번호 — URL의 /products/{번호} (QnA API channelProductNo) */
  function getChannelProductNo() {
    const m = location.pathname.match(/\/products\/(\d+)/);
    return m ? Number(m[1]) : null;
  }

  function getProductPrice() {
    // og:price / JSON-LD / 페이지 스크립트에서 판매가 추출
    const meta = document.querySelector('meta[property="product:price:amount"], meta[property="og:price:amount"]');
    if (meta && num(meta.content) > 0) return num(meta.content);
    for (const s of document.querySelectorAll('script')) {
      const t = s.textContent;
      if (!t || t.length < 500) continue;
      const m =
        t.match(/"salePrice"\s*:\s*"?(\d{2,})"?/) ||
        t.match(/"discountedSalePrice"\s*:\s*"?(\d{2,})"?/) ||
        t.match(/"price"\s*:\s*"?(\d{3,})"?/);
      if (m) return num(m[1]);
    }
    return null;
  }

  // ------------------------------------------------------------------
  // 리뷰 API
  // ------------------------------------------------------------------
  function normalizeReview(raw) {
    if (!raw || typeof raw !== 'object') return null;
    const dateVal =
      raw.createDate || raw.reviewCreateDate || raw.registerDate || raw.createdDate || raw.writeDate || null;
    let ts = null;
    if (typeof dateVal === 'number') ts = dateVal;
    else if (dateVal) {
      const p = Date.parse(dateVal);
      if (isFinite(p)) ts = p;
    }
    return {
      id: raw.id || raw.reviewId || null,
      score: num(raw.reviewScore != null ? raw.reviewScore : raw.score != null ? raw.score : raw.starScore),
      ts,
      option: String(raw.productOptionContent || raw.productOptionContents || raw.optionContent || '').trim(),
      repurchase: Boolean(
        raw.repurchase === true || raw.repurchase === 'true' || raw.isRepurchase || raw.repeatPurchase
      ),
      text: String(raw.reviewContent || raw.content || '').slice(0, 600),
    };
  }

  /**
   * 리뷰 API는 페이지가 생성한 서명 헤더(x-client-rtk/rts)를 요구한다(없으면 401 "비정상적인 접근").
   * 사용자가 리뷰 영역을 아직 안 봤으면 페이지가 그 요청을 한 적이 없어 헤더가 캡처되지 않는다.
   * → 페이지가 스스로 리뷰를 불러오도록 유도(리뷰 탭 클릭 / 리뷰 섹션으로 스크롤)한 뒤,
   *   injected-store.js 훅이 헤더를 잡을 때까지 폴링한다.
   */
  function hasSignedHeaders() {
    return Boolean(
      state.storeHeaders && state.storeHeaders['x-client-rtk'] && state.storeHeaders['x-client-rts']
    );
  }

  function triggerPageReviewLoad(withClick) {
    // 1) (최후 수단) 리뷰 탭 클릭 — 페이지 내 이동(#앵커)만.
    //    실제 경로 링크(/review-event/list 등)는 페이지 이탈·모달을 유발하므로 절대 클릭하지 않는다.
    if (withClick) {
      const clickTargets = [];
      document.querySelectorAll('a, button, li, span').forEach((el) => {
        if (clickTargets.length >= 2) return;
        const t = (el.textContent || '').trim();
        if (/이벤트/.test(t)) return;
        const href = el.getAttribute && el.getAttribute('href');
        if (href && href.charAt(0) !== '#') return; // 요소 자신이 경로 링크면 제외
        // 클릭은 조상 <a>로 버블링돼 실제 네비게이션을 일으키므로, 조상 앵커의 href·텍스트도 검사.
        const anchor = el.closest && el.closest('a[href]');
        if (anchor) {
          const ah = anchor.getAttribute('href') || '';
          if (ah.charAt(0) !== '#') return; // 조상이 경로 이동 링크 → 클릭 시 페이지 이탈
          if (/이벤트/.test(anchor.textContent || '')) return;
        }
        if (
          (href && /review/i.test(href)) ||
          (t.length <= 8 && /^리뷰/.test(t) && el.querySelectorAll('*').length <= 3)
        ) {
          clickTargets.push(el);
        }
      });
      clickTargets.forEach((el) => {
        try {
          el.click();
        } catch (e) {
          /* noop */
        }
      });
    }

    // 2) 리뷰 섹션으로 스크롤 (지연 로딩 트리거)
    const sec =
      document.getElementById('REVIEW') ||
      document.querySelector('[id*="review" i], [class*="review" i]');
    if (sec && sec.scrollIntoView) {
      try {
        sec.scrollIntoView({ block: 'center' });
      } catch (e) {
        /* noop */
      }
    } else {
      window.scrollTo(0, Math.round(document.body.scrollHeight * 0.6));
    }

    // 3) 지연로딩 발화율 보강 — scroll/resize 합성 이벤트로 IntersectionObserver·scroll 리스너 자극
    try {
      window.dispatchEvent(new Event('scroll'));
      document.dispatchEvent(new Event('scroll'));
      window.dispatchEvent(new Event('resize'));
    } catch (e) {
      /* noop */
    }
  }

  /** MAIN world(injected-store.js)에 보관된 마지막 캡처 헤더를 재조회(이벤트 유실 대비). */
  async function requestStoredHeaders() {
    if (hasSignedHeaders()) return true;
    try {
      document.dispatchEvent(new CustomEvent('rankfree:get-headers'));
    } catch (e) {
      /* noop */
    }
    for (let i = 0; i < 6 && !hasSignedHeaders(); i++) {
      await new Promise((r) => setTimeout(r, 100));
    }
    return hasSignedHeaders();
  }

  /** 리뷰 모달/오버레이 닫기 — 클릭 유도로 모달이 열렸을 때 원상복구. */
  function closeReviewOverlay() {
    try {
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', code: 'Escape', keyCode: 27, which: 27, bubbles: true }));
    } catch (e) { /* noop */ }
    try {
      const btn = document.querySelector('[role="dialog"] button[aria-label*="닫기"], [class*="modal" i] button[class*="close" i], [class*="dialog" i] button[class*="close" i], button[class*="_close" i]');
      if (btn) btn.click();
    } catch (e) { /* noop */ }
  }

  async function ensureSignedHeaders() {
    await requestStoredHeaders();
    if (hasReviewReq()) return true; // 이미 페이지 리뷰 요청을 캡처함

    // 1) 대부분 페이지 로드 시 스스로 리뷰 요청을 보내므로, 클릭 전 잠깐 대기(모달 방지)
    for (let i = 0; i < 12 && !hasReviewReq(); i++) {
      await new Promise((r) => setTimeout(r, 150));
    }
    if (hasReviewReq()) return true;

    const scrollY = window.scrollY;
    // 1.5) 클릭 없이 리뷰 섹션으로 스크롤만 — 지연 로딩으로 페이지가 스스로 리뷰 요청(모달 안 열림)
    state.progress = '리뷰 불러오는 중…';
    render();
    triggerPageReviewLoad(false);
    for (let i = 0; i < 16 && !hasReviewReq(); i++) {
      await new Promise((r) => setTimeout(r, 150));
    }

    // 2) 스크롤로도 안 되면 리뷰 탭 클릭으로 유도(최후 수단·모달 열릴 수 있음 → 캡처되면 즉시 닫음)
    //    수집 모드(#rfreviewcollect)에서는 클릭/모달을 아예 쓰지 않는다 — SPA 이동 위험 제거,
    //    캡처 실패 시 서명 불필요 구형 paged-reviews(page 파라미터 GET)로 직행하므로 불필요.
    if (!collectHash()) {
      for (let attempt = 0; attempt < 3 && !hasReviewReq(); attempt++) {
        state.progress = '리뷰 불러오는 중… (' + (attempt + 1) + '/3)';
        render();
        triggerPageReviewLoad(true);
        for (let i = 0; i < 20 && !hasReviewReq(); i++) {
          await new Promise((r) => setTimeout(r, 150));
        }
      }
      if (hasReviewReq()) closeReviewOverlay();
    }
    try {
      window.scrollTo(0, scrollY);
    } catch (e) {
      /* noop */
    }
    return hasReviewReq() || hasSignedHeaders();
  }

  function hasReviewReq() {
    // 리뷰 '목록' 요청(query-pages)만 재현 대상으로 인정 — summary-tag 등 부가 요청 배제
    return Boolean(
      state.reviewReq && state.reviewReq.body && state.reviewReq.url && /query-pages/.test(state.reviewReq.url)
    );
  }

  async function fetchReviewPageOnce(params, page, pageSize, sort) {
    // 1순위: 페이지가 실제로 보낸 요청 재현(경로·originProductNos·서명 그대로, page/정렬만 교체).
    //         네이버가 스키마를 바꿔도(group-products, 복수 originProductNos 등) 그대로 통과.
    if (hasReviewReq()) {
      const rq = state.reviewReq;
      const body = Object.assign({}, rq.body);
      // 서명(x-client-rtk/rts)은 캡처한 요청 body에 묶여 있다. 실측(사용자 데이터)상 page1/page2가
      // '동일 서명'으로 통과 → page만 바꾸면 서명 유지, pageSize/sort를 바꾸면 서명이 깨져 401.
      // 따라서 신형 재현은 page만 교체하고 pageSize/sort는 캡처값을 그대로 둔다.
      if ('page' in body) body.page = page;
      // (pageSize/reviewSearchSortType는 절대 덮어쓰지 않는다)
      // x-client-rtk는 요청마다 다른 전용 서명 → 캡처된 그 요청의 헤더를 그대로 사용(덮어쓰지 않음)
      const headers = Object.assign(
        { accept: 'application/json, text/plain, */*', 'content-type': 'application/json' },
        rq.headers || {}
      );
      const res = await fetch(rq.url, { method: rq.method || 'POST', credentials: 'include', headers, body: JSON.stringify(body) });
      if (!res.ok) {
        let head = '';
        try { head = (await res.text()).slice(0, 200); } catch (e2) { /* noop */ }
        console.warn('[RankFree] 리뷰 API(재현) 실패:', res.status, head);
        const e = new Error('리뷰 API ' + res.status);
        e.status = res.status;
        throw e;
      }
      return res;
    }

    // 폴백: 구(舊) 단수 스키마 (캡처 실패 시)
    const headers = { accept: 'application/json, text/plain, */*', 'content-type': 'application/json' };
    if (state.storeHeaders) Object.assign(headers, state.storeHeaders);
    const res = await fetch(location.origin + '/i/v1/contents/reviews/query-pages', {
      method: 'POST',
      credentials: 'include',
      headers,
      body: JSON.stringify({
        checkoutMerchantNo: params.merchantNo,
        originProductNo: params.originProductNo,
        page,
        pageSize,
        reviewSearchSortType: sort,
      }),
    });
    if (!res.ok) {
      let head = '';
      try { head = (await res.text()).slice(0, 200); } catch (e2) { /* noop */ }
      console.warn('[RankFree] 리뷰 API 실패:', res.status, head);
      const e = new Error('리뷰 API ' + res.status);
      e.status = res.status;
      throw e;
    }
    return res;
  }

  /** 페이지가 새 서명 토큰(rts 변경)을 만들 때까지 대기 — 기존 토큰은 유지, 스크롤 위치 복원 */
  async function refreshSignedHeaders() {
    const prev = state.storeHeaders && state.storeHeaders['x-client-rts'];
    const scrollY = window.scrollY;
    triggerPageReviewLoad(!collectHash()); // 수집 모드에선 클릭 없이 스크롤만(SPA 이동 방지)
    let ok = false;
    for (let i = 0; i < 24; i++) {
      const cur = state.storeHeaders && state.storeHeaders['x-client-rts'];
      if (cur && cur !== prev) { ok = true; break; } // 새 토큰 확보
      await new Promise((r) => setTimeout(r, 150));
    }
    try { window.scrollTo(0, scrollY); } catch (e) { /* noop */ }
    return ok || hasSignedHeaders();
  }

  // ---------- 구형 리뷰 API 폴백 (서명 불필요) ----------
  // 신형 query-pages가 401(서명 강화)일 때 구형 /i/v1/reviews/paged-reviews 로 대체.
  // 구형은 merchantNo=naverPaySellerNo, pageSize 최대 30.
  let useLegacyReviews = false; // 한 번 폴백되면 이후 페이지는 바로 구형 사용
  let legacyMerchantNo = null;

  async function getLegacyMerchantNo(params) {
    for (const s of document.querySelectorAll('script')) {
      const t = s.textContent;
      if (!t || t.length < 1000) continue;
      const m = t.match(/"naverPaySellerNo"\s*:\s*"?(\d+)"?/);
      if (m) return Number(m[1]);
    }
    const pre = await getMyPreloadedAsync();
    const ch = pre && pre.smartStoreV2 && pre.smartStoreV2.channel;
    const n = ch && (ch.naverPaySellerNo || ch.payReferenceKey);
    return n ? Number(n) : params.merchantNo;
  }

  async function fetchReviewPageLegacyOnce(merchantNo, params, page, pageSize, sort) {
    const qs =
      'page=' + page + '&pageSize=' + Math.min(pageSize, 30) + '&merchantNo=' + merchantNo +
      '&originProductNo=' + params.originProductNo + '&sortType=' + encodeURIComponent(sort);
    const url = location.origin + '/i/v1/reviews/paged-reviews?' + qs;
    const res = await fetch(url, {
      method: 'GET',
      credentials: 'include',
      headers: { accept: 'application/json, text/plain, */*' },
    });
    if (!res.ok) {
      let head = '';
      try { head = (await res.text()).slice(0, 200); } catch (e2) { /* noop */ }
      console.warn('[RankFree] 리뷰 API(구형) 실패:', res.status, 'merchantNo=' + merchantNo, head);
      const e = new Error('리뷰 API(구형) ' + res.status);
      e.status = res.status;
      throw e;
    }
    return res;
  }

  async function fetchReviewPageLegacy(params, page, pageSize, sort) {
    if (legacyMerchantNo == null) legacyMerchantNo = await getLegacyMerchantNo(params);
    try {
      const res = await fetchReviewPageLegacyOnce(legacyMerchantNo, params, page, pageSize, sort);
      useLegacyReviews = true;
      return res;
    } catch (e) {
      // merchantNo 후보가 틀렸으면 checkoutMerchantNo 로 1회 재시도
      if (legacyMerchantNo !== params.merchantNo) {
        legacyMerchantNo = params.merchantNo;
        const res = await fetchReviewPageLegacyOnce(legacyMerchantNo, params, page, pageSize, sort);
        useLegacyReviews = true;
        return res;
      }
      throw e;
    }
  }

  /** 응답 본문을 JSON으로 — content-type이 아니라 본문 형태로 판정(헤더 누락 오탐 방지).
   *  HTML(로그인·차단 페이지)이면 status:'html' 클린 에러. */
  async function readReviewJson(res) {
    const txt = await res.text();
    const t = txt.trim();
    if (t.charAt(0) === '{' || t.charAt(0) === '[') {
      try { return JSON.parse(t); } catch (e) { /* fallthrough */ }
    }
    const e = new Error('리뷰 데이터를 불러오지 못했습니다(로그인·차단 페이지 응답).');
    e.status = 'html';
    throw e;
  }

  async function fetchReviewPage(params, page, pageSize, sort) {
    let json;
    if (useLegacyReviews) {
      json = await readReviewJson(await fetchReviewPageLegacy(params, page, pageSize, sort));
    } else {
      try {
        json = await readReviewJson(await fetchReviewPageOnce(params, page, pageSize, sort));
      } catch (e) {
        // 401/HTML(로그인·차단 응답): 새 토큰 재시도 → 그래도 막히면 구형 API 폴백
        if (e && (e.status === 401 || e.status === 'html')) {
          try {
            await refreshSignedHeaders();
            json = await readReviewJson(await fetchReviewPageOnce(params, page, pageSize, sort));
          } catch (e2) {
            if (e2 && (e2.status === 401 || e2.status === 403 || e2.status === 'html')) {
              console.warn('[RankFree] 서명 리뷰 API 차단 — 구형 paged-reviews 폴백');
              json = await readReviewJson(await fetchReviewPageLegacy(params, page, pageSize, sort));
            } else {
              throw e2;
            }
          }
        } else {
          throw e;
        }
      }
    }
    const root = json && json.data ? json.data : json || {};
    const contents = Array.isArray(root.contents)
      ? root.contents
      : Array.isArray(root.reviews)
        ? root.reviews
        : Array.isArray(root.list)
          ? root.list
          : Array.isArray(root.items)
            ? root.items
            : Array.isArray(root.reviewList)
              ? root.reviewList
              : [];
    if (page === 1) {
      console.log('[RankFree] 리뷰 응답 키:', Object.keys(json || {}).join(',') +
        (json && json.data ? ' | data:' + Object.keys(json.data).join(',') : '') +
        ' | 추출 ' + contents.length + '개');
      if (contents.length) console.log('[RankFree] 리뷰 샘플:', contents[0]);
    }
    return {
      total: num(root.totalElements != null ? root.totalElements : root.totalCount != null ? root.totalCount : root.total),
      reviews: contents.map(normalizeReview).filter(Boolean),
    };
  }

  /**
   * 페이지당 최대 리뷰 수 측정 — 기본 20 이지만 더 받아주는지 100→50→30 순 시도.
   * 반환 개수 < 요청 개수라도 그것이 전체 리뷰수와 같으면(리뷰가 그만큼뿐) 요청 크기를 인정.
   */
  async function probePageSize(params) {
    // 신형(페이지가 쏜 요청 캡처됨): 서명이 캡처 body에 묶여 pageSize/sort를 바꾸면 401.
    // 캡처된 pageSize를 그대로 쓰고 page만 바꿔 수집(프로브 없이) — page1 씨앗을 안전하게 확보.
    if (hasReviewReq()) {
      const capSize = num(state.reviewReq.body && state.reviewReq.body.pageSize) || 20;
      const r = await fetchReviewPage(params, 1, capSize, null);
      console.log('[RankFree] 신형 캡처 재현 · pageSize ' + capSize + '(캡처값 고정) · page1 ' + r.reviews.length + '개');
      return { size: capSize, first: r };
    }
    for (const size of [100, 50, 30]) {
      try {
        const r = await fetchReviewPage(params, 1, size, 'REVIEW_CREATE_DATE_DESC');
        const got = r.reviews.length;
        if (!got) continue;
        const effective = got >= size || got >= r.total ? size : got;
        if (effective > 20) {
          console.debug('[RankFree] pageSize 프로브: ' + size + ' 요청 → ' + got + '개 수신, 사용값 ' + effective);
          return { size: effective, first: r };
        }
      } catch (e) {
        /* 다음 크기로 */
      }
    }
    const r = await fetchReviewPage(params, 1, 20, 'REVIEW_CREATE_DATE_DESC');
    console.debug('[RankFree] pageSize 프로브: 20개 기본값 사용');
    return { size: 20, first: r };
  }

  /** 병렬 수집 — 동시 4요청, id 기준 중복 제거 */
  async function collectReviews(params, sort, want, pageSize, firstPage, label) {
    const seen = new Set();
    const all = [];
    const push = (r) => {
      const key = r.id || r.ts + '|' + r.text.slice(0, 30);
      if (seen.has(key)) return;
      seen.add(key);
      all.push(r);
    };

    let total;
    let startPage = 1;
    if (firstPage) {
      total = firstPage.total;
      firstPage.reviews.forEach(push);
      startPage = 2;
    } else {
      const first = await fetchReviewPage(params, 1, pageSize, sort);
      total = first.total;
      first.reviews.forEach(push);
      startPage = 2;
    }

    const target = Math.min(want, total || want);
    const lastPage = Math.max(1, Math.ceil(target / pageSize));
    const queue = [];
    for (let p = startPage; p <= lastPage; p++) queue.push(p);

    let done = lastPage - queue.length;
    const worker = async () => {
      while (queue.length) {
        const p = queue.shift();
        try {
          const r = await fetchReviewPage(params, p, pageSize, sort);
          r.reviews.forEach(push);
        } catch (e) {
          /* 개별 페이지 실패 무시 */
        }
        done++;
        const pct = lastPage > 0 ? Math.min(100, Math.round((done / lastPage) * 100)) : 0;
        state.progress = label + ' 수집 중… ' + pct + '% (' + done + '/' + lastPage + '페이지)';
        render();
      }
    };
    await Promise.all(Array.from({ length: Math.min(4, Math.max(1, queue.length)) }, worker));

    return { total, reviews: all.slice(0, target) };
  }

  // ------------------------------------------------------------------
  // 상품 문의(QnA) 수집 — /i/v1/qna/pages 직접 호출(파라미터 구성), 비밀글 제외
  // 리뷰와 동일한 서명 헤더(x-client-*) 사용 → 문의 탭을 안 열어도 자동 수집
  // ------------------------------------------------------------------
  const QNA_TEXT_KEYS = [
    'question', 'questionContent', 'inquiryContent', 'inquiry', 'content', 'contents',
    'title', 'body', 'text', 'comment',
  ];
  function normalizeQna(raw) {
    if (!raw || typeof raw !== 'object') return null;
    const secret = Boolean(
      raw.secret === true || raw.secret === 'true' || raw.isSecret || raw.secretYn === 'Y' ||
      raw.privated || raw.private || raw.secretYn === true
    );
    // 알려진 키 우선, 없으면 객체 내 "가장 긴 한글 문자열"을 질문 본문으로 추정
    let text = '';
    for (const k of QNA_TEXT_KEYS) {
      if (typeof raw[k] === 'string' && raw[k].trim()) {
        text = raw[k].trim();
        break;
      }
    }
    if (!text) {
      for (const k in raw) {
        const v = raw[k];
        if (typeof v === 'string' && v.length > text.length && /[가-힣]/.test(v) && v.length <= 500) text = v.trim();
      }
    }
    if (secret) return { secret: true, text: '' };
    if (!text) return null;
    const answered = Boolean(
      raw.answered || raw.answerContent || raw.answer || raw.commentCount > 0 || raw.replied || raw.answerYn === 'Y'
    );
    const category = String(raw.categoryName || raw.qnaTypeName || raw.inquiryType || raw.qnaType || raw.category || '').trim();
    return { secret: false, text, answered, category };
  }

  /** 트리에서 QnA 항목 배열 후보를 모두 모아 가장 큰 것을 선택 */
  function findQnaList(root) {
    const candidates = [];
    (function walk(node, depth) {
      if (depth > 9 || node == null || typeof node !== 'object') return;
      if (Array.isArray(node)) {
        if (node.length && node[0] && typeof node[0] === 'object') {
          const k = Object.keys(node[0]);
          const looksQna =
            QNA_TEXT_KEYS.some((x) => k.indexOf(x) !== -1) ||
            ['secret', 'answered', 'answerContent', 'qnaType', 'writerId', 'writer', 'createDate'].some((x) => k.indexOf(x) !== -1);
          if (looksQna) {
            candidates.push(node);
            return;
          }
        }
        for (const it of node) walk(it, depth + 1);
        return;
      }
      for (const key in node) walk(node[key], depth + 1);
    })(root, 0);
    if (!candidates.length) return [];
    candidates.sort((a, b) => b.length - a.length);
    return candidates[0];
  }

  function qnaTotalOf(root) {
    let t = 0;
    (function walk(node, depth) {
      if (t || depth > 6 || node == null || typeof node !== 'object') return;
      if (Array.isArray(node)) {
        walk(node[0], depth + 1);
        return;
      }
      for (const k in node) {
        if (/^total(Elements|Count|Elem)?$/i.test(k) && num(node[k]) > 0) {
          t = num(node[k]);
          return;
        }
        walk(node[k], depth + 1);
      }
    })(root, 0);
    return t;
  }

  /** QnA 한 페이지 — 파라미터로 직접 구성한 요청(우선), 없으면 캡처된 요청 재사용 */
  async function fetchQnaPage(params, page) {
    const headers = { accept: 'application/json, text/plain, */*' };
    if (state.storeHeaders) Object.assign(headers, state.storeHeaders);

    let url;
    if (params) {
      const channelProductNo = params.channelProductNo || params.originProductNo;
      const channelNo = params.merchantNo;
      url =
        location.origin + '/i/v1/qna/pages?page=' + page +
        '&pageSize=20&isMyQna=false&qnaStatus=ALL&excludeSecret=false' +
        '&channelProductNo=' + channelProductNo +
        (channelNo ? '&channelNo=' + channelNo : '');
    } else if (state.qnaRequest) {
      // 폴백 — 페이지가 보낸 요청 URL의 page 만 교체
      url = state.qnaRequest.url;
      url = /[?&]page=/.test(url) ? url.replace(/([?&]page=)\d+/, '$1' + page) : url + '&page=' + page;
    } else {
      throw new Error('QnA 파라미터 없음');
    }

    const res = await fetch(url, { method: 'GET', credentials: 'include', headers });
    if (!res.ok) {
      let head = '';
      try {
        head = (await res.text()).slice(0, 200);
      } catch (e3) {
        /* noop */
      }
      console.warn('[RankFree] QnA API 실패:', res.status, head, url);
      const e = new Error('QnA API ' + res.status);
      e.status = res.status;
      throw e;
    }
    const json = await res.json();
    const list = findQnaList(json);
    if (page === 1) {
      console.debug('[RankFree] QnA 응답 top-keys:', Object.keys(json || {}), '· 항목수:', list.length, '· 총계:', qnaTotalOf(json));
      if (list.length) console.debug('[RankFree] QnA 항목 샘플:', list[0]);
      else console.warn('[RankFree] QnA 배열 탐색 실패 — 응답 전체:', json);
    }
    return { rows: list.map(normalizeQna).filter(Boolean), total: qnaTotalOf(json) };
  }

  /** 문의 수집 — 최대 maxPages 페이지, 비밀글 제외한 텍스트만 반환 */
  async function collectQna(params, maxPages) {
    const items = [];
    let secret = 0;
    let total = 0;
    for (let p = 1; p <= maxPages; p++) {
      let page;
      try {
        page = await fetchQnaPage(params, p);
      } catch (e) {
        if (p === 1) throw e; // 첫 페이지 실패는 상위로(진단)
        break;
      }
      if (p === 1) total = page.total;
      if (!page.rows.length) break;
      for (const r of page.rows) {
        if (r.secret) secret++;
        else if (r.text) items.push(r);
      }
      if (page.rows.length < 20) break;
      await new Promise((r) => setTimeout(r, 250));
    }
    return { total: total || items.length + secret, secret, items };
  }

  // ------------------------------------------------------------------
  // 경량 형태소·감성 분석 (외부 NLP 라이브러리 없이 휴리스틱)
  // ------------------------------------------------------------------
  // 조사·어미 — 명사 추출 시 말미에서 제거
  const JOSA = [
    '으로써', '으로서', '이라고', '라고', '에서는', '에서', '으로', '로서', '로써', '에게서', '한테서',
    '이라는', '라는', '이라도', '라도', '에게', '한테', '보다', '처럼', '같이', '까지', '부터', '마저', '조차',
    '이며', '이고', '이나', '나마', '이란', '이든', '든지', '이야', '으로', '들이', '들을', '들은', '들의',
    '은', '는', '이', '가', '을', '를', '에', '의', '와', '과', '도', '만', '로', '랑', '이랑', '요', '님',
  ];
  // 감성 사전 (형용사/표현 어간 위주)
  const POS_WORDS = [
    '좋', '만족', '최고', '편하', '편리', '빠르', '부드럽', '부드러', '가볍', '튼튼', '견고', '깔끔', '예쁘', '이쁘',
    '고급', '세련', '아늑', '따뜻', '시원', '쫄깃', '촉촉', '신선', '푸짐', '든든', '저렴', '가성비', '합리',
    '추천', '재구매', '잘맞', '적당', '무난', '탄탄', '넉넉', '선명', '조용', '안정', '정확', 'friendly', '만족스',
    '맛있', '달달', '고소', '진하', '풍부', '깨끗', '청결', '빵빵', '든든', '착하', '훌륭', '완벽', '감동',
  ];
  const NEG_WORDS = [
    '별로', '아쉽', '불편', '느리', '약하', '허술', '부실', '조잡', '불량', '하자', '결함', '실망', '최악', '후회',
    '냄새', '지연', '늦', '깨지', '망가', '헐거', '뻑뻑', '까칠', '거칠', '얇', '싸구려', '조악', '불친절',
    '비싸', '과대포장', '작', '헐렁', '흐물', '눅눅', '비리', '짜', '싱겁', '딱딱', '질기', '터지', '샜', '샘',
    '어색', '불만', '환불', '반품', '교환', '오배송', '누락', '파손', '변색', '이염', '트러블', '가렵', '따갑',
  ];
  const POS_SET = new Set(POS_WORDS);
  const NEG_SET = new Set(NEG_WORDS);

  function stripJosa(token) {
    for (const j of JOSA) {
      if (token.length > j.length + 1 && token.endsWith(j)) return token.slice(0, -j.length);
    }
    return token;
  }

  // 동사/형용사 어미 판정 후 어간 추정
  const PRED_ENDINGS = [
    '습니다', '합니다', '됩니다', '입니다', '했어요', '해요', '하네요', '하고', '하지만', '했는데', '였어요', '이에요',
    '았어요', '었어요', '아요', '어요', '네요', '더라고요', '더라구요', '군요', '구요', '겠어요',
    '았고', '었고', '아서', '어서', '았는데', '었는데', '지만', '으며', '으면', '다',
  ];
  function predicateStem(token) {
    for (const e of PRED_ENDINGS) {
      if (token.length > e.length && token.endsWith(e)) {
        let stem = token.slice(0, -e.length);
        // '하다' 계열 정규화
        if (stem.endsWith('하') || stem.endsWith('해')) stem = stem.slice(0, -1);
        return stem;
      }
    }
    return null;
  }

  function sentimentOf(text) {
    let pos = 0;
    let neg = 0;
    for (const w of POS_WORDS) if (text.indexOf(w) !== -1) pos++;
    for (const w of NEG_WORDS) if (text.indexOf(w) !== -1) neg++;
    // 부정어 반전(간단): "안 좋", "좋지 않", "별로 안" 등 대략 처리
    if (/안\s*좋|좋지\s*않|별로|그다지|생각보다\s*별/.test(text)) neg++;
    return { pos, neg };
  }

  // ------------------------------------------------------------------
  // 분석
  // ------------------------------------------------------------------
  const STOPWORDS = new Set([
    '너무', '정말', '진짜', '그냥', '조금', '아주', '완전', '많이', '살짝', '약간', '매우', '엄청',
    '그리고', '그런데', '근데', '하지만', '그래서', '해서', '해도', '했는데', '인데', '이라',
    '같아요', '같습니다', '같아서', '같은', '있어요', '있습니다', '있는', '있어서', '없어요', '없습니다', '없는',
    '합니다', '했어요', '해요', '하는', '하고', '하니', '하면', '됩니다', '되는', '돼요', '됐어요',
    '입니다', '이에요', '예요', '거예요', '건데', '것이', '것을', '것도',
    '저는', '제가', '내가', '우리', '이거', '그거', '저거', '이건', '그게', '뭔가',
    '주문', '구매', '샀는데', '시켰는데', '받았어요', '받았는데', '왔어요', '왔는데',
    '좋아요', '좋았어요', '좋습니다', '좋은', '좋고', '좋네요', '잘', '잘못',
  ]);

  function topWords(reviews, limit) {
    const docFreq = new Map();
    for (const r of reviews) {
      const tokens = new Set(
        r.text
          .replace(/[^가-힣a-zA-Z0-9\s]/g, ' ')
          .split(/\s+/)
          .map((w) => w.trim())
          .filter((w) => w.length >= 2 && w.length <= 10 && !STOPWORDS.has(w) && !/^\d+$/.test(w))
      );
      for (const w of tokens) docFreq.set(w, (docFreq.get(w) || 0) + 1);
    }
    return Array.from(docFreq.entries())
      .filter(([, c]) => c >= 2)
      .sort((a, b) => b[1] - a[1])
      .slice(0, limit);
  }

  /**
   * 텍스트 묶음 형태소·감성 분석 — 명사(주로 언급하는 것)/장점/단점/감정 비율.
   * 정식 형태소 분석기 대신 조사·어미 휴리스틱 + 감성 사전(문서 빈도 기준).
   */
  function analyzeTexts(texts) {
    const nounFreq = new Map();
    const predFreq = new Map(); // 서술어(동사·형용사) 어간
    const posFreq = new Map();
    const negFreq = new Map();
    // 어간별 실제 등장 어절 빈도 — 한 글자 어간('좋','작') 대신 대표 어절('좋아요')로 표시하기 위함
    const posDisp = new Map();
    const negDisp = new Map();
    let posDocs = 0;
    let negDocs = 0;
    let neuDocs = 0;

    for (const text of texts) {
      if (!text) continue;
      const clean = text.replace(/[^가-힣a-zA-Z0-9\s]/g, ' ');
      const words = clean.split(/\s+/).map((w) => w.trim()).filter(Boolean);

      const nounsInDoc = new Set();
      const predsInDoc = new Set();
      const posInDoc = new Set();
      const negInDoc = new Set();

      for (const w of words) {
        // 서술어(동사/형용사) 어간
        const stem = predicateStem(w);
        if (stem && stem.length >= 1 && stem.length <= 8 && !STOPWORDS.has(stem) && !/^\d+$/.test(stem)) {
          predsInDoc.add(stem + '다'); // 원형으로 표기
        }
        const cand = stem || stripJosa(w);
        if (!stem && cand && cand.length >= 2 && cand.length <= 10 && !STOPWORDS.has(cand) && !/^\d+$/.test(cand)) {
          nounsInDoc.add(cand); // 명사 후보(서술어가 아님)
        }
        // 감성 사전 매칭 (어간/원형 부분일치) — 집계는 어간, 표시용 실제 어절은 따로 기록
        for (const p of POS_WORDS) {
          if (w.indexOf(p) !== -1) {
            posInDoc.add(p);
            if (!posDisp.has(p)) posDisp.set(p, new Map());
            const m = posDisp.get(p); m.set(w, (m.get(w) || 0) + 1);
          }
        }
        for (const n of NEG_WORDS) {
          if (w.indexOf(n) !== -1) {
            negInDoc.add(n);
            if (!negDisp.has(n)) negDisp.set(n, new Map());
            const m = negDisp.get(n); m.set(w, (m.get(w) || 0) + 1);
          }
        }
      }

      nounsInDoc.forEach((n) => nounFreq.set(n, (nounFreq.get(n) || 0) + 1));
      predsInDoc.forEach((p) => predFreq.set(p, (predFreq.get(p) || 0) + 1));
      posInDoc.forEach((p) => posFreq.set(p, (posFreq.get(p) || 0) + 1));
      negInDoc.forEach((n) => negFreq.set(n, (negFreq.get(n) || 0) + 1));

      const s = sentimentOf(text);
      if (s.pos > s.neg) posDocs++;
      else if (s.neg > s.pos) negDocs++;
      else neuDocs++;
    }

    const top = (map, k) =>
      Array.from(map.entries()).filter(([, c]) => c >= 2).sort((a, b) => b[1] - a[1]).slice(0, k);
    // 어간을 '가장 많이 쓰인 실제 어절'로 치환(12자 이내). 어절이 없거나 짧으면 어간 유지.
    const repWord = (dispMap, stem) => {
      const m = dispMap.get(stem);
      if (!m) return stem;
      let best = stem, bc = 0;
      m.forEach((c, w) => { if (c > bc && w.length <= 12) { bc = c; best = w; } });
      return best.length >= 2 ? best : stem;
    };
    const withRep = (freqMap, dispMap, k) => top(freqMap, k).map(([stem, c]) => [repWord(dispMap, stem), c]);

    const totalDocs = posDocs + negDocs + neuDocs || 1;
    return {
      docs: posDocs + negDocs + neuDocs,
      keywords: top(nounFreq, 15),
      predicates: top(predFreq, 12),
      pros: withRep(posFreq, posDisp, 12),
      cons: withRep(negFreq, negDisp, 12),
      sentiment: {
        pos: posDocs,
        neg: negDocs,
        neu: neuDocs,
        posPct: (posDocs / totalDocs) * 100,
        negPct: (negDocs / totalDocs) * 100,
        neuPct: (neuDocs / totalDocs) * 100,
      },
    };
  }

  function analyzeReviews(newest, worst, totals) {
    const now = Date.now();
    const DAY = 24 * 3600 * 1000;
    const withTs = newest.filter((r) => r.ts);

    const recent = { d7: 0, m1: 0, m3: 0 };
    for (const r of withTs) {
      const age = now - r.ts;
      if (age <= 7 * DAY) recent.d7++;
      if (age <= 30 * DAY) recent.m1++;
      if (age <= 90 * DAY) recent.m3++;
    }
    // 수집분이 3개월을 다 못 덮으면(가장 오래된 수집 리뷰가 90일 이내) 최소치로 표기
    const oldest = withTs.length ? withTs[withTs.length - 1].ts : null;
    const covered90 = oldest !== null && now - oldest > 90 * DAY;

    const repurchaseCnt = newest.filter((r) => r.repurchase).length;
    const scores = newest.filter((r) => r.score > 0);
    const avgScore = scores.length ? scores.reduce((a, r) => a + r.score, 0) / scores.length : 0;
    const dist = { 5: 0, 4: 0, 3: 0, 2: 0, 1: 0 };
    for (const r of scores) {
      const s = Math.min(5, Math.max(1, Math.round(r.score)));
      dist[s]++;
    }

    // 옵션별 판매 비중 (리뷰 기준 추정)
    const optCount = new Map();
    let optTotal = 0;
    for (const r of newest) {
      if (!r.option) continue;
      optTotal++;
      optCount.set(r.option, (optCount.get(r.option) || 0) + 1);
    }
    const topOptions = Array.from(optCount.entries())
      .sort((a, b) => b[1] - a[1])
      .slice(0, 6);

    // 약점 — 평점 낮은순 수집분에서 3점 이하만
    const lowReviews = worst.filter((r) => r.score > 0 && r.score <= 3);
    const weakWords = topWords(lowReviews, 12);
    const lowShare = totals.total > 0 ? ((dist[1] + dist[2] + dist[3]) / scores.length) * 100 : 0;

    // 형태소·감성 분석 (전체 리뷰 텍스트)
    const nlp = analyzeTexts(newest.map((r) => r.text));

    // 장·단점 리뷰 예시 — 감성 신호가 가장 뚜렷한 원문 2개씩 (약점 카드의 worstSamples 미러)
    const sentimented = newest
      .filter((r) => r.text && String(r.text).trim().length >= 15)
      .map((r) => ({ r, s: sentimentOf(r.text) }));
    const posSamples = sentimented
      .filter((x) => x.s.pos > x.s.neg)
      .sort((a, b) => (b.s.pos - b.s.neg) - (a.s.pos - a.s.neg) || (b.r.score || 0) - (a.r.score || 0))
      .slice(0, 2)
      .map((x) => x.r);
    const negSamples = sentimented
      .filter((x) => x.s.neg > x.s.pos)
      .sort((a, b) => (b.s.neg - b.s.pos) - (a.s.neg - a.s.pos) || (a.r.score || 0) - (b.r.score || 0))
      .slice(0, 2)
      .map((x) => x.r);

    return {
      posSamples,
      negSamples,
      totalReviews: totals.total,
      analyzed: newest.length,
      avgScore,
      dist,
      repurchaseCnt,
      repurchasePct: newest.length ? (repurchaseCnt / newest.length) * 100 : 0,
      recent,
      covered90,
      topOptions,
      optTotal,
      lowReviews: lowReviews.length,
      weakWords,
      worstSamples: lowReviews.slice(0, 2),
      nlp,
      qna: null, // analyze() 에서 채움
      pageSize: totals.pageSize,
    };
  }

  // ------------------------------------------------------------------
  // 실행
  // ------------------------------------------------------------------
  async function analyze() {
    if (state.loading) return;
    if (!state.loggedIn) { state.error = 'rankfree 로그인이 필요합니다(세션 만료). 확장에서 다시 로그인 후 시도해 주세요.'; return; }

    state.loading = true;
    state.error = null;
    state.snapshot = null;
    state.savedId = null;
    state.saveLimitMsg = null;
    state.progress = '상품 정보 확인 중…';
    render();
    console.log('[RankFree] 상품 분석 시작 · 빌드 v2(리뷰 캡처-재현) · 리뷰요청캡처=' + (hasReviewReq() ? 'Y' : 'N'));

    let params = null; // try 밖에서도 saveAnalysis(params)에 필요
    try {
      // 상품 파라미터 — 동기 실패 시 리뷰 로드 유도(capturedParams 캡처) + MAIN world 폴백
      params = getProductParams();
      if (!params) {
        await ensureSignedHeaders();
        params = await getProductParamsAsync();
      }
      if (!params) {
        throw new Error('상품 정보를 찾지 못했습니다. 페이지를 아래로 스크롤해 리뷰 영역을 한 번 표시한 뒤 다시 시도해 주세요.');
      }

      // 리뷰 요청/서명 확보(위에서 이미 됐으면 즉시 반환) — 리뷰 API 재현에 필요
      const ready = await ensureSignedHeaders();
      if (!ready) {
        // 페이지가 끝내 리뷰 요청을 안 쏨(캡처 실패) → 서명·클릭·이동 없이 되는 구형 paged-reviews 경로로 직행
        console.warn('[RankFree] 리뷰 요청 미캡처 — 구형(paged-reviews) 경로로 수집');
        useLegacyReviews = true;
      }

      if (state.price == null) state.price = getProductPrice();
      state.progress = '페이지당 리뷰 수 측정 중…';
      render();

      const probe = await probePageSize(params);

      const newestJob = collectReviews(
        params, 'REVIEW_CREATE_DATE_DESC', state.target, probe.size, probe.first, '최신순 리뷰'
      );
      const worstJob = collectReviews(
        params, 'REVIEW_SCORE_ASC', 200, probe.size, null, '저평점 리뷰'
      ).catch((e2) => {
        // 저평점 정렬 미지원(구형 API 폴백 등) — 약점 분석만 생략하고 계속
        console.warn('[RankFree] 저평점 리뷰 수집 실패(생략):', e2.status || '', e2.message);
        return { total: 0, reviews: [] };
      });
      const [newest, worst] = await Promise.all([newestJob, worstJob]);

      if (!newest.reviews.length) {
        // 화면만 보고 원인을 알 수 있게 진단 정보 포함
        if (!hasReviewReq()) {
          throw new Error('페이지에서 리뷰 요청을 감지하지 못했습니다. 페이지를 아래로 스크롤해 리뷰 영역을 한 번 표시하고, 확장을 새로고침(⟳)한 뒤 다시 시도해 주세요.');
        }
        throw new Error('리뷰를 불러오지 못했습니다(응답 비어있음). 확장을 새로고침(⟳)한 뒤 다시 시도해 주세요.');
      }

      // 상품 문의(QnA) — /i/v1/qna/pages 직접 호출(비밀글 제외). 문의 탭을 안 열어도 자동 수집
      let qna = null;
      state.progress = '상품 문의 수집 중…';
      render();
      try {
        const qnaParams = {
          channelProductNo: getChannelProductNo() || params.originProductNo,
          originProductNo: params.originProductNo,
          merchantNo: params.merchantNo,
        };
        const collected = await collectQna(qnaParams, 20);
        const qnaNlp = analyzeTexts(collected.items.map((q) => q.text));
        qna = {
          total: collected.total,
          secret: collected.secret,
          open: collected.items.length,
          nlp: {
            docs: qnaNlp.docs,
            keywords: qnaNlp.keywords,
            predicates: qnaNlp.predicates,
            pros: qnaNlp.pros,
            cons: qnaNlp.cons,
            sentiment: qnaNlp.sentiment,
          },
          samples: collected.items.slice(0, 3).map((q) => q.text.slice(0, 80)),
        };
      } catch (e2) {
        console.warn('[RankFree] QnA 수집 실패:', e2.status || '', e2.message);
      }

      state.result = analyzeReviews(newest.reviews, worst.reviews, {
        total: newest.total,
        pageSize: probe.size,
      });
      state.result.qna = qna;
    } catch (e) {
      const is401 = e && e.status === 401;
      state.error = is401
        ? '리뷰 인증에 실패했습니다(401). 페이지에서 리뷰 영역을 한 번 열어(스크롤/리뷰 탭 클릭) 리뷰가 보이게 한 뒤 다시 시도해 주세요.'
        : String((e && e.message) || e) + ' — 페이지 새로고침 후 다시 시도해 주세요.';
      state.result = null;
    }

    state.loading = false;
    state.progress = null;
    if (state.result) state.analyzedAt = Date.now(); // 리포트 메타 라인용 분석시각(재렌더에도 고정)
    render();

    // 성공 분석은 서버에 자동 저장
    if (state.result) await saveAnalysis(params);
    render();
  }

  const productParamsCache = { v: null };

  /** 분석 결과 서버 자동 저장 */
  async function saveAnalysis(params) {
    try {
      const r = state.result;
      const res = await sendBg('saveProductAnalysis', {
        origin_product_no: params.originProductNo,
        merchant_no: params.merchantNo || null,
        name: String(getProductName()).slice(0, 200),
        url: location.origin + location.pathname,
        store: getStoreName() || null,
        total_reviews: r.totalReviews,
        analyzed_reviews: r.analyzed,
        avg_score: Number(r.avgScore.toFixed(2)),
        repurchase_pct: Number(r.repurchasePct.toFixed(1)),
        recent_7d: r.recent.d7,
        recent_1m: r.recent.m1,
        recent_3m: r.recent.m3,
        sales_6m: num(state.sales6m) > 0 ? num(state.sales6m) : null,
        price: state.price || null,
        snapshot: {
          dist: r.dist,
          options: r.topOptions, // [[name, count], ...]
          opt_total: r.optTotal,
          weak_words: r.weakWords,
          worst_samples: r.worstSamples.map((s) => ({ text: String(s.text).slice(0, 160), score: s.score })),
          low_reviews: r.lowReviews,
          covered90: r.covered90,
          page_size: r.pageSize,
          nlp: r.nlp
            ? {
                keywords: r.nlp.keywords,
                predicates: r.nlp.predicates,
                pros: r.nlp.pros,
                cons: r.nlp.cons,
                sentiment: r.nlp.sentiment,
                docs: r.nlp.docs,
                // 장·단점 카드 리뷰 예시 (웹 콘솔 표시용)
                pos_samples: (r.posSamples || []).map((s) => ({ text: String(s.text).slice(0, 160), score: s.score })),
                neg_samples: (r.negSamples || []).map((s) => ({ text: String(s.text).slice(0, 160), score: s.score })),
              }
            : null,
          qna: r.qna || null,
        },
        report_html: String(bodyHtml() || '').slice(0, 380000), // 내역에서 재수집 없이 재생용
      });
      state.savedId = res && res.ok ? res.id : null;
      state.shareToken = res && res.ok ? (res.share_token || null) : null;
      state.saveLimitMsg = res && res.status === 429 ? (res.message || '이번 달 분석 저장 횟수를 모두 사용했습니다.') : null;
      state.history = undefined;
    } catch (e) {
      state.savedId = null;
    }
  }

  // ------------------------------------------------------------------
  // 렌더링
  // ------------------------------------------------------------------
  const PANEL_ID = 'rankfree-panel';
  const FAB_ID = 'rankfree-fab';

  function statTile(label, value, sub) {
    return (
      '<div class="rf-stat"><div class="rf-stat-label">' + esc(label) + '</div>' +
      '<div class="rf-stat-value">' + value + '</div>' +
      (sub ? '<div class="rf-stat-sub">' + sub + '</div>' : '') +
      '</div>'
    );
  }

  function barRow(label, widthPct, valueText, title) {
    return (
      '<div class="rf-age-row" title="' + esc(title || '') + '">' +
      '<span class="rf-age-lab">' + esc(label) + '</span>' +
      '<span class="rf-age-track"><span class="rf-age-bar" style="width:' + widthPct + '%"></span></span>' +
      '<span class="rf-age-pct">' + valueText + '</span></div>'
    );
  }

  function loginHtml() {
    return (
      '<div class="rf-login">' +
      '<h3>RankFree 로그인</h3>' +
      '<p class="rf-muted">상품 분석은 rankfree.kr 회원만 이용할 수 있습니다.</p>' +
      '<label class="rf-label">이메일</label>' +
      '<input type="email" class="rf-input" name="email" autocomplete="username" placeholder="you@example.com">' +
      '<label class="rf-label">비밀번호</label>' +
      '<input type="password" class="rf-input" name="password" autocomplete="current-password" placeholder="••••••••">' +
      '<div class="rf-login-err" hidden></div>' +
      '<button type="button" class="rf-btn-primary" data-act="login">로그인</button>' +
      '<details class="rf-adv"><summary>고급 설정</summary>' +
      '<label class="rf-label">서버 주소</label>' +
      '<input type="url" class="rf-input" name="apiBase" placeholder="https://rankfree.kr">' +
      '</details>' +
      '</div>'
    );
  }

  function bodyHtml() {
    if (state.loading) {
      return (
        '<div class="rf-loading"><div class="rf-spinner"></div>' +
        esc(state.progress || '리뷰를 수집하고 있습니다…') + '</div>'
      );
    }
    if (state.error) {
      return (
        '<div class="rf-error">' + esc(state.error) + '</div>' +
        '<button type="button" class="rf-btn-primary" data-act="run">다시 시도</button>'
      );
    }
    if (!state.result) {
      return (
        '<div class="rf-collect">' +
        '<p class="rf-muted">리뷰를 수집해 인기 옵션·재구매율·최근 판매 추세·약점을 분석합니다.</p>' +
        '<label class="rf-scope">분석 리뷰 수 <select class="rf-select" data-ctl="target">' +
        [300, 500, 1000, 2000, 3000]
          .map((t) => '<option value="' + t + '"' + (state.target === t ? ' selected' : '') + '>최신 ' + t + '개</option>')
          .join('') +
        '</select></label>' +
        '<button type="button" class="rf-btn-primary" data-act="run">리뷰 분석 시작</button>' +
        '</div>'
      );
    }

    const r = state.result;

    // 상품명 아래 메타 라인 — 상품번호(URL) · 판매가 · 분석시각. 저장본은 report_html에 그대로 보존됨.
    const metaLine = (() => {
      const pno = (location.pathname.match(/\/products\/(\d+)/) || [])[1] || '';
      const parts = [];
      if (pno) parts.push('상품번호 ' + pno);
      if (state.price) parts.push('판매가 ' + comma(state.price) + '원');
      if (state.analyzedAt) {
        const d = new Date(state.analyzedAt);
        const z = (n) => String(n).padStart(2, '0');
        parts.push(d.getFullYear() + '-' + z(d.getMonth() + 1) + '-' + z(d.getDate()) + ' ' + z(d.getHours()) + ':' + z(d.getMinutes()) + ' 분석');
      }
      return parts.length ? '<div class="rf-prod-meta">' + parts.join(' · ') + '</div>' : '';
    })();

    const summary =
      '<div class="rf-card"><div class="rf-card-title">리뷰 요약 ' +
      '<span class="rf-chip">최신 ' + comma(r.analyzed) + '개 분석 · 페이지당 ' + r.pageSize + '개</span>' +
      (state.savedId ? '<span class="rf-chip">☁ 저장됨</span>' : '') +
      (state.saveLimitMsg ? '<span class="rf-chip" style="color:var(--rf-error);">저장 한도 초과</span>' : '') + '</div>' +
      (state.saveLimitMsg ? '<p class="rf-note" style="color:var(--rf-error);">' + esc(state.saveLimitMsg) + '</p>' : '') +
      '<div class="rf-stats">' +
      statTile('전체 리뷰', comma(r.totalReviews) + '개') +
      statTile('평균 평점', r.avgScore.toFixed(2) + '점') +
      statTile('재구매 비율', r.repurchasePct.toFixed(1) + '%', '분석분 중 ' + comma(r.repurchaseCnt) + '개') +
      statTile('최근 7일', comma(r.recent.d7) + '개') +
      statTile('최근 1개월', comma(r.recent.m1) + '개') +
      statTile('최근 3개월', comma(r.recent.m3) + '개' + (r.covered90 ? '' : '+'), r.covered90 ? '' : '수집 범위 초과(최소치)') +
      '</div>' +
      '<p class="rf-note">* 리뷰 작성일 기준 집계 — 실제 판매 시점과 차이가 있을 수 있습니다.</p>' +
      '</div>';

    const distMax = Math.max.apply(null, [5, 4, 3, 2, 1].map((s) => r.dist[s])) || 1;
    const distCard =
      '<div class="rf-card"><div class="rf-card-title">평점 분포</div>' +
      [5, 4, 3, 2, 1]
        .map((s) =>
          barRow(s + '점', Math.round((r.dist[s] / distMax) * 100), comma(r.dist[s]), s + '점 ' + comma(r.dist[s]) + '개')
        )
        .join('') +
      '</div>';

    let optCard = '';
    if (r.topOptions.length) {
      const optMax = r.topOptions[0][1] || 1;
      optCard =
        '<div class="rf-card"><div class="rf-card-title">인기 옵션 TOP' + r.topOptions.length +
        ' <span class="rf-chip">리뷰 ' + comma(r.optTotal) + '개 기준 추정</span></div>' +
        r.topOptions
          .map(([opt, cnt]) => {
            const pct = ((cnt / r.optTotal) * 100).toFixed(1);
            return (
              '<div class="rf-opt" title="' + esc(opt) + ' — ' + comma(cnt) + '개 (' + pct + '%)">' +
              '<div class="rf-opt-name">' + esc(opt) + '</div>' +
              '<div class="rf-opt-line">' +
              '<span class="rf-age-track"><span class="rf-age-bar" style="width:' + Math.round((cnt / optMax) * 100) + '%"></span></span>' +
              '<span class="rf-opt-pct">' + pct + '% · ' + comma(cnt) + '개</span>' +
              '</div></div>'
            );
          })
          .join('') +
        '</div>';
    }

    // 옵션별 예상 판매수량·매출 — 6개월 판매량 × 리뷰 옵션 비율
    let estCard = '';
    if (r.topOptions.length && r.optTotal > 0) {
      const sales = num(state.sales6m);
      const price = num(state.price);
      // 각 행에 data-ratio를 심어, 검색 패널(content.js)에서도 입력값으로 즉시 재계산되게 한다.
      const rows = r.topOptions
        .map(([opt, cnt]) => {
          const ratio = cnt / r.optTotal;
          const qty = sales > 0 ? Math.round(sales * ratio) : null;
          const rev = qty != null && price > 0 ? qty * price : null;
          const label = opt.length > 20 ? opt.slice(0, 20) + '…' : opt;
          return (
            '<tr data-ratio="' + ratio.toFixed(6) + '" title="' + esc(opt) + '">' +
            '<td class="rf-td-title">' + esc(label) + '</td>' +
            '<td class="rf-td-num">' + (ratio * 100).toFixed(1) + '%</td>' +
            '<td class="rf-td-num rf-td-strong rf-est-qty">' + (qty == null ? '-' : comma(qty) + '건') + '</td>' +
            '<td class="rf-td-num rf-est-rev">' + (rev == null ? '-' : krw(rev) + '원') + '</td></tr>'
          );
        })
        .join('');
      estCard =
        '<div class="rf-card"><div class="rf-card-title">옵션별 예상 판매 · 매출 <span class="rf-chip">6개월 판매량 × 옵션 비율</span></div>' +
        '<div class="rf-est-inputs">' +
        '<label>6개월 판매량 <input type="number" class="rf-est-in" data-ctl="sales6m" min="0" placeholder="예: 3000" value="' + esc(state.sales6m) + '"></label>' +
        '<label>판매가 <input type="number" class="rf-est-in" data-ctl="price" min="0" placeholder="원" value="' + (state.price || '') + '"></label>' +
        '</div>' +
        '<div class="rf-table-wrap"><table class="rf-table rf-est-table"><thead><tr><th>옵션</th><th>비율</th><th>예상 판매</th><th>예상 매출</th></tr></thead><tbody>' +
        rows + '</tbody></table></div>' +
        '<p class="rf-note">6개월 판매량·판매가를 입력하면 옵션별 예상 판매수량·매출이 계산됩니다. (판매량은 쇼핑 시장분석의 상품별 구매건수를 참고하세요.)</p>' +
        '</div>';
    }

    let weakCard =
      '<div class="rf-card"><div class="rf-card-title">약점 분석 ' +
      '<span class="rf-chip">평점 낮은순 · 3점 이하 ' + comma(r.lowReviews) + '개</span></div>';
    if (r.weakWords.length) {
      weakCard +=
        '<div class="rf-tags">' +
        r.weakWords
          .map(([w, c]) => '<span class="rf-word">' + esc(w) + '<span class="rf-tag-vol">' + c + '</span></span>')
          .join('') +
        '</div>';
      if (r.worstSamples.length) {
        weakCard +=
          '<div class="rf-worst">' +
          r.worstSamples
            .map((s, i) => '<p><span class="rf-rvnum rf-rvnum-neg">' + (i + 1) + '</span> “' + esc(s.text.slice(0, 90)) + (s.text.length > 90 ? '…' : '') + '” <b>' + s.score + '점</b></p>')
            .join('') +
          '</div>';
      }
    } else {
      weakCard += '<p class="rf-muted">낮은 평점 리뷰가 거의 없습니다. 👍</p>';
    }
    weakCard += '</div>';

    const again =
      '<div class="rf-controls">' +
      '<label>분석 리뷰 수 <select class="rf-select" data-ctl="target">' +
      [300, 500, 1000, 2000, 3000]
        .map((t) => '<option value="' + t + '"' + (state.target === t ? ' selected' : '') + '>최신 ' + t + '개</option>')
        .join('') +
      '</select></label>' +
      '<button type="button" class="rf-btn-ghost" data-act="run" title="다시 분석">↻</button>' +
      '</div>';

    return metaLine + again + summary + sentimentHtml(r) + weakCard + distCard + optCard + estCard + qnaHtml(r.qna);
  }

  /** 감정 분석 · 장단점(리뷰 예시 2개씩) · 명사 — 웹(PC) 구성과 동일. 동사·형용사는 표시하지 않음. */
  function sentimentHtml(r) {
    const nlp = r && r.nlp;
    if (!nlp || !nlp.docs) return '';
    const s = nlp.sentiment;
    const bar =
      '<div class="rf-sent-bar">' +
      '<span class="rf-sent-pos" style="width:' + s.posPct + '%"></span>' +
      '<span class="rf-sent-neu" style="width:' + s.neuPct + '%"></span>' +
      '<span class="rf-sent-neg" style="width:' + s.negPct + '%"></span></div>' +
      '<div class="rf-sent-lab">' +
      '<span><i class="rf-dot rf-dot-pos"></i>긍정 ' + s.posPct.toFixed(0) + '%</span>' +
      '<span><i class="rf-dot rf-dot-neu"></i>중립 ' + s.neuPct.toFixed(0) + '%</span>' +
      '<span><i class="rf-dot rf-dot-neg"></i>부정 ' + s.negPct.toFixed(0) + '%</span></div>';

    const chips = (list, cls) =>
      list && list.length
        ? '<div class="rf-tags">' +
          list.map(([w, c]) => '<span class="rf-word ' + cls + '">' + esc(w) + '<span class="rf-tag-vol">' + c + '</span></span>').join('') +
          '</div>'
        : '<p class="rf-muted">해당 표현이 적습니다.</p>';
    // 리뷰 예시 — 원문 최대 2개(장/단점 공통). numCls로 번호 배지 색 구분(장점=green, 단점=pink)
    const samples = (arr, numCls) =>
      arr && arr.length
        ? '<div class="rf-worst">' +
          arr.slice(0, 2).map((x, i) => '<p><span class="rf-rvnum ' + (numCls || '') + '">' + (i + 1) + '</span> “' + esc(String(x.text || '').slice(0, 90)) + (String(x.text || '').length > 90 ? '…' : '') + '” <b>' + (x.score || '-') + '점</b></p>').join('') +
          '</div>'
        : '';

    return (
      '<div class="rf-card"><div class="rf-card-title">감정 분석 <span class="rf-chip">리뷰 ' + comma(nlp.docs) + '개 · 형태소 기반</span></div>' +
      bar +
      '<div class="rf-kw-sec"><div class="rf-kw-sec-title">👍 장점 (자주 언급된 긍정)</div>' + chips(nlp.pros, 'rf-word-pos') + samples(r.posSamples, 'rf-rvnum-pos') + '</div>' +
      '<div class="rf-kw-sec"><div class="rf-kw-sec-title">👎 단점 (자주 언급된 부정)</div>' + chips(nlp.cons, 'rf-word-neg') + samples(r.negSamples, 'rf-rvnum-neg') + '</div>' +
      '<div class="rf-kw-sec"><div class="rf-kw-sec-title">🔑 주로 이야기하는 것</div>' + chips(nlp.keywords, '') + '</div>' +
      '</div>'
    );
  }

  /** 형태소 분석 공통 섹션 (감정 바 옵션) — 리뷰·QnA 동일 */
  function morphSections(nlp, opts) {
    opts = opts || {};
    const chips = (list, cls) =>
      list && list.length
        ? '<div class="rf-tags">' +
          list.map(([w, c]) => '<span class="rf-word ' + (cls || '') + '">' + esc(w) + '<span class="rf-tag-vol">' + c + '</span></span>').join('') +
          '</div>'
        : '<p class="rf-muted">해당 표현이 적습니다.</p>';

    let html = '';
    if (opts.sentiment && nlp.sentiment) {
      const s = nlp.sentiment;
      html +=
        '<div class="rf-sent-bar">' +
        '<span class="rf-sent-pos" style="width:' + s.posPct + '%"></span>' +
        '<span class="rf-sent-neu" style="width:' + s.neuPct + '%"></span>' +
        '<span class="rf-sent-neg" style="width:' + s.negPct + '%"></span></div>' +
        '<div class="rf-sent-lab">' +
        '<span><i class="rf-dot rf-dot-pos"></i>긍정 ' + s.posPct.toFixed(0) + '%</span>' +
        '<span><i class="rf-dot rf-dot-neu"></i>중립 ' + s.neuPct.toFixed(0) + '%</span>' +
        '<span><i class="rf-dot rf-dot-neg"></i>부정 ' + s.negPct.toFixed(0) + '%</span></div>';
    }
    html +=
      '<div class="rf-kw-sec"><div class="rf-kw-sec-title">🔑 ' + (opts.nounLabel || '주로 이야기하는 것') + '</div>' + chips(nlp.keywords, '') + '</div>' +
      '<div class="rf-kw-sec"><div class="rf-kw-sec-title">🗣 동사·형용사</div>' + chips(nlp.predicates, '') + '</div>' +
      '<div class="rf-kw-sec"><div class="rf-kw-sec-title">👍 긍정 표현</div>' + chips(nlp.pros, 'rf-word-pos') + '</div>' +
      '<div class="rf-kw-sec"><div class="rf-kw-sec-title">👎 부정 표현</div>' + chips(nlp.cons, 'rf-word-neg') + '</div>';
    return html;
  }

  /** QnA 분석 — 비밀글 제외, 리뷰와 동일한 형태소 분석 */
  function qnaHtml(qna) {
    if (!qna) {
      return (
        '<div class="rf-card"><div class="rf-card-title">상품 문의(QnA) 분석</div>' +
        '<p class="rf-muted">문의 데이터를 불러오지 못했습니다(이 상품은 문의가 없거나 비공개일 수 있습니다).</p></div>'
      );
    }
    if (!qna.open && !qna.secret) {
      const parseMiss = num(qna.total) > 0;
      return (
        '<div class="rf-card"><div class="rf-card-title">상품 문의(QnA) 분석</div>' +
        '<p class="rf-muted">' +
        (parseMiss
          ? '문의 ' + comma(qna.total) + '건이 확인되나 형식을 해석하지 못했습니다. (개발자 콘솔의 [RankFree] QnA 로그를 확인해 주세요.)'
          : '공개된 상품 문의가 없습니다.') +
        '</p></div>'
      );
    }
    const nlp = qna.nlp || { keywords: qna.keywords || [], predicates: [], pros: [], cons: [] };
    const samples = qna.samples && qna.samples.length
      ? '<div class="rf-worst">' + qna.samples.map((s) => '<p>“' + esc(s) + '…”</p>').join('') + '</div>'
      : '';
    return (
      '<div class="rf-card"><div class="rf-card-title">상품 문의(QnA) 분석 ' +
      '<span class="rf-chip">공개 ' + comma(qna.open) + ' · 비밀글 ' + comma(qna.secret) + ' 제외 · 형태소 기반</span></div>' +
      morphSections(nlp, { sentiment: true, nounLabel: '자주 묻는 것' }) +
      samples +
      '</div>'
    );
  }

  function render() {
    const panel = document.getElementById(PANEL_ID);
    if (!panel) return;
    const body = panel.querySelector('.rf-body');
    const foot = panel.querySelector('.rf-foot');

    const tabs = panel.querySelector('.rf-tabs');

    if (!state.loggedIn) {
      if (tabs) tabs.style.display = 'none';
      body.innerHTML = loginHtml();
      foot.innerHTML = '';
      bindLogin(body);
      return;
    }

    // 탭 (분석 / 내역)
    if (tabs) {
      tabs.style.display = '';
      tabs.innerHTML = [
        { key: 'analyze', label: '상품 분석' },
        { key: 'seller', label: '셀러력' },
        { key: 'history', label: '내역' },
      ]
        .map(
          (t) =>
            '<button type="button" class="rf-tab' + (state.tab === t.key ? ' is-active' : '') +
            '" data-tab="' + t.key + '">' + t.label + '</button>'
        )
        .join('');
      tabs.querySelectorAll('.rf-tab').forEach((btn) =>
        btn.addEventListener('click', () => {
          state.tab = btn.dataset.tab;
          if (state.tab === 'history') loadHistory();
          render();
        })
      );
    }

    if (state.tab === 'history') {
      body.innerHTML = historyHtml();
    } else if (state.tab === 'seller') {
      body.innerHTML = spBodyHtml();
    } else if (state.snapshot) {
      body.innerHTML = snapshotHtml();
    } else {
      body.innerHTML = bodyHtml();
    }
    bindBody(body);
    if (state.tab === 'seller' && state.sp.result) requestAnimationFrame(spDrawRadar);

    foot.innerHTML =
      '<span class="rf-foot-user">' + esc((state.user && state.user.email) || '') + '</span>' +
      '<button type="button" class="rf-link" data-act="logout">로그아웃</button>';
    foot.querySelector('[data-act="logout"]').addEventListener('click', async () => {
      await sendBg('logout');
      state.loggedIn = false;
      state.user = null;
      render();
    });
  }

  function bindLogin(body) {
    const btn = body.querySelector('[data-act="login"]');
    const errEl = body.querySelector('.rf-login-err');
    const doLogin = async () => {
      const email = body.querySelector('[name="email"]').value.trim();
      const password = body.querySelector('[name="password"]').value;
      const apiBase = body.querySelector('[name="apiBase"]').value.trim();
      if (!email || !password) {
        errEl.hidden = false;
        errEl.textContent = '이메일과 비밀번호를 입력해 주세요.';
        return;
      }
      btn.disabled = true;
      btn.textContent = '로그인 중…';
      const res = await sendBg('login', { email, password, apiBase: apiBase || undefined });
      btn.disabled = false;
      btn.textContent = '로그인';
      if (res.ok) {
        state.loggedIn = true;
        state.user = res.user;
        render();
      } else {
        errEl.hidden = false;
        errEl.textContent = res.message || '로그인에 실패했습니다.';
      }
    };
    btn.addEventListener('click', doLogin);
    body.querySelectorAll('.rf-input').forEach((inp) =>
      inp.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') doLogin();
      })
    );
  }

  function bindBody(body) {
    body.querySelectorAll('[data-act="run"]').forEach((b) => b.addEventListener('click', () => analyze()));
    const sel = body.querySelector('[data-ctl="target"]');
    if (sel)
      sel.addEventListener('change', () => {
        state.target = num(sel.value) || 300;
        if (state.result) analyze();
      });
    // 옵션별 예상 판매·매출 입력 (재계산만, 재수집 X)
    const salesIn = body.querySelector('[data-ctl="sales6m"]');
    if (salesIn)
      salesIn.addEventListener('change', () => {
        state.sales6m = salesIn.value;
        render();
      });
    const priceIn = body.querySelector('[data-ctl="price"]');
    if (priceIn)
      priceIn.addEventListener('change', () => {
        state.price = num(priceIn.value) || null;
        render();
      });
    // 내역
    body.querySelectorAll('.rf-hist-row').forEach((row) =>
      row.addEventListener('click', () => openSaved(row.dataset.id))
    );
    const refresh = body.querySelector('[data-act="hist-refresh"]');
    if (refresh) refresh.addEventListener('click', () => loadHistory(true));
    const snapClose = body.querySelector('[data-act="snap-close"]');
    if (snapClose) snapClose.addEventListener('click', () => { state.snapshot = null; state.tab = 'history'; render(); });
    // 셀러력 탭 입력·실행
    const spKw = body.querySelector('#rfSpKw');
    if (spKw) {
      spKw.addEventListener('input', () => { state.sp.keyword = spKw.value; });
      spKw.addEventListener('keydown', (e) => { if (e.key === 'Enter') spAnalyze(); });
    }
    const spRun = body.querySelector('#rfSpRun');
    if (spRun) spRun.addEventListener('click', () => { state.sp.keyword = (spKw && spKw.value) || state.sp.keyword; spAnalyze(); });
    const spRefresh = body.querySelector('#rfSpRefresh');
    if (spRefresh) spRefresh.addEventListener('click', () => { spAnalyze(); });
  }

  // ---------- 내역 / 저장본 ----------
  async function loadHistory(force) {
    if (state.historyLoading) return;
    if (!force && Array.isArray(state.history)) return;
    state.historyLoading = true;
    render();
    const res = await sendBg('listProductAnalyses', { limit: 30 });
    state.history = res && res.ok ? res.data : [];
    state.historyLoading = false;
    render();
  }

  function historyHtml() {
    if (state.historyLoading) return '<div class="rf-loading"><div class="rf-spinner"></div>내역을 불러오는 중…</div>';
    const list = Array.isArray(state.history) ? state.history : [];
    if (!list.length) return '<div class="rf-empty"><p>저장된 상품 분석이 없습니다.<br>리뷰 분석을 실행하면 자동 저장됩니다.</p></div>';
    return (
      '<div class="rf-card"><div class="rf-card-title">최근 상품 분석 <span class="rf-chip">' + list.length + '건</span>' +
      '<span class="rf-copy-group"><button type="button" class="rf-copy" data-act="hist-refresh">새로고침</button></span></div>' +
      '<div class="rf-hist">' +
      list
        .map(
          (a) =>
            '<button type="button" class="rf-hist-row" data-id="' + a.id + '">' +
            '<span class="rf-hist-kw">' + esc(String(a.name || '').slice(0, 40)) + '</span>' +
            '<span class="rf-hist-meta">' + esc(String(a.created_at || '').slice(0, 10)) +
            ' · 리뷰 ' + comma(num(a.total_reviews)) + '개 · 평점 ' + Number(a.avg_score || 0).toFixed(2) +
            ' · 재구매 ' + Number(a.repurchase_pct || 0).toFixed(0) + '%</span></button>'
        )
        .join('') +
      '</div></div>' +
      '<p class="rf-note">웹 콘솔(쇼핑 → 상품 분석)에서도 확인·옵션별 매출 추정이 가능합니다.</p>'
    );
  }

  async function openSaved(id) {
    state.tab = 'analyze';
    state.loading = true;
    state.snapshot = null;
    render();
    const res = await sendBg('getProductAnalysis', { id });
    state.loading = false;
    if (!res || !res.ok || !res.data) {
      state.error = '저장본을 불러오지 못했습니다.';
      render();
      return;
    }
    state.snapshot = res.data;
    render();
  }

  function snapshotHtml() {
    const a = state.snapshot;
    const snap = a.snapshot || {};
    const dist = snap.dist || {};
    const opts = snap.options || [];
    const optTotal = num(snap.opt_total) || opts.reduce((s, o) => s + num(o[1]), 0);
    const when = String(a.created_at || '').replace('T', ' ').slice(0, 16);

    const banner =
      '<div class="rf-snapbar">📁 저장본 · ' + esc(when) +
      '<span class="rf-copy-group"><button type="button" class="rf-copy" data-act="snap-close">닫기</button></span></div>';

    const summary =
      '<div class="rf-card"><div class="rf-card-title">' + esc(String(a.name || '').slice(0, 40)) + '</div>' +
      '<div class="rf-stats">' +
      statTile('전체 리뷰', comma(num(a.total_reviews)) + '개') +
      statTile('평균 평점', Number(a.avg_score || 0).toFixed(2) + '점') +
      statTile('재구매 비율', Number(a.repurchase_pct || 0).toFixed(1) + '%') +
      statTile('최근 7일', comma(num(a.recent_7d)) + '개') +
      statTile('최근 1개월', comma(num(a.recent_1m)) + '개') +
      statTile('최근 3개월', comma(num(a.recent_3m)) + '개') +
      '</div></div>';

    const optMax = opts.length ? num(opts[0][1]) || 1 : 1;
    const optCard = opts.length
      ? '<div class="rf-card"><div class="rf-card-title">인기 옵션 <span class="rf-chip">리뷰 ' + comma(optTotal) + '개 기준</span></div>' +
        opts
          .map((o) => {
            const cnt = num(o[1]);
            const pct = optTotal > 0 ? ((cnt / optTotal) * 100).toFixed(1) : '0';
            return (
              '<div class="rf-opt"><div class="rf-opt-name">' + esc(o[0]) + '</div>' +
              '<div class="rf-opt-line"><span class="rf-age-track"><span class="rf-age-bar" style="width:' +
              Math.round((cnt / optMax) * 100) + '%"></span></span>' +
              '<span class="rf-opt-pct">' + pct + '% · ' + comma(cnt) + '개</span></div></div>'
            );
          })
          .join('') +
        '</div>'
      : '';

    const weak = snap.weak_words || [];
    const weakCard =
      '<div class="rf-card"><div class="rf-card-title">약점 분석 <span class="rf-chip">3점 이하 ' + comma(num(snap.low_reviews)) + '개</span></div>' +
      (weak.length
        ? '<div class="rf-tags">' +
          weak.map((w) => '<span class="rf-word">' + esc(w[0]) + '<span class="rf-tag-vol">' + num(w[1]) + '</span></span>').join('') +
          '</div>'
        : '<p class="rf-muted">낮은 평점 리뷰가 거의 없습니다. 👍</p>') +
      '</div>';

    const sent = snap.nlp
      ? sentimentHtml({
          docs: snap.nlp.docs,
          sentiment: snap.nlp.sentiment,
          pros: snap.nlp.pros || [],
          cons: snap.nlp.cons || [],
          keywords: snap.nlp.keywords || [],
        })
      : '';
    const qna = snap.qna ? qnaHtml(snap.qna) : '';

    return banner + summary + sent + optCard + weakCard + qna;
  }

  // ==================================================================
  // 셀러력 (같은 패널의 '셀러력' 탭) — 내 상품 + 검색 상위 10 경쟁 비교
  // ==================================================================
  // script textContent엔 </script>가 없으므로 괄호 균형으로 JSON 끝을 찾는다.
  function extractBalancedJson(text, fromIdx) {
    const start = text.indexOf('{', fromIdx);
    if (start < 0) return null;
    let depth = 0, inStr = false, escp = false;
    for (let i = start; i < text.length; i++) {
      const ch = text[i];
      if (escp) { escp = false; continue; }
      if (ch === '\\') { escp = true; continue; }
      if (ch === '"') { inStr = !inStr; continue; }
      if (inStr) continue;
      if (ch === '{') depth++;
      else if (ch === '}') { depth--; if (depth === 0) return text.slice(start, i + 1); }
    }
    return null;
  }
  function parsePreloaded(text) {
    const idx = text.indexOf('__PRELOADED_STATE__');
    if (idx < 0) return null;
    const eq = text.indexOf('=', idx);
    if (eq < 0) return null;
    const json = extractBalancedJson(text, eq);
    if (!json) return null;
    try { return JSON.parse(json); } catch (e) { return null; }
  }
  function getMyPreloaded() {
    const sc = document.querySelectorAll('script');
    for (let i = 0; i < sc.length; i++) {
      const t = sc[i].textContent || '';
      if (t.indexOf('__PRELOADED_STATE__') >= 0) { const p = parsePreloaded(t); if (p) return p; }
    }
    return null;
  }
  /**
   * SPA hydration 후 script 태그가 제거되면 위 방식이 실패한다.
   * → MAIN world(injected-store.js)에 window.__PRELOADED_STATE__를 요청해 받는 폴백.
   */
  function getMyPreloadedAsync(timeoutMs) {
    timeoutMs = Number(timeoutMs) || 1500;
    const fromScript = getMyPreloaded();
    if (fromScript) return Promise.resolve(fromScript);
    return new Promise((resolve) => {
      let done = false;
      const finish = (v) => {
        if (done) return;
        done = true;
        document.removeEventListener('rankfree:preloaded', onEv);
        resolve(v);
      };
      const onEv = (e) => {
        try { finish(JSON.parse(String(e.detail))); } catch (err) { finish(null); }
      };
      document.addEventListener('rankfree:preloaded', onEv);
      try { document.dispatchEvent(new CustomEvent('rankfree:get-preloaded')); } catch (e) { /* noop */ }
      setTimeout(() => finish(null), timeoutMs);
    });
  }
  function spNodeFrom(pre, extra) {
    if (!pre) return null;
    // 신구조: st.simpleProductForDetailPage.A + st.channel / 구구조: product.A + smartStoreV2.channel
    const A =
      (pre.product && pre.product.A) ? pre.product.A
      : (pre.simpleProductForDetailPage && pre.simpleProductForDetailPage.A) ? pre.simpleProductForDetailPage.A
      : (pre.product || {});
    const channel =
      (pre.smartStoreV2 && pre.smartStoreV2.channel) ? pre.smartStoreV2.channel
      : (pre.channel || (A && A.channel) || {});
    const node = { product: A, smartStoreV2: { channel: channel }, mallInfoCache: pre.mallInfoCache || {} };
    if (extra) for (const k in extra) node[k] = extra[k];
    return node;
  }
  // 상품정보(제목·업체명·가격·SEO태그·브랜드) 수집 → 서버 저장. 노출 키워드 분석(25) 조합 재료.
  async function collectProductInfo() {
    try {
      const cpid = getChannelProductNo();
      if (!cpid) return;
      const node = spNodeFrom(await getMyPreloadedAsync(2500));
      const A = (node && node.product) || {};
      const channel = (node && node.smartStoreV2 && node.smartStoreV2.channel) || {};
      let tags = (((A.seoInfo && A.seoInfo.sellerTags) || []).map((t) => (t && t.text) || '')).filter(Boolean);
      // 태그 폴백(2026-07-22) — 상태 JSON에 없으면 상세 하단 관련 태그 링크(#…)에서 추출
      if (!tags.length) {
        tags = Array.prototype.map.call(
          document.querySelectorAll('a[href*="search?q=%23"]'),
          (a) => (a.textContent || '').trim().replace(/^#/, '')
        ).filter(Boolean);
      }
      // 대표이미지(썸네일) 1개 — 쇼핑 유입 발주에 필요(2026-07-22). 상태 JSON → og:image → 첫 상품 이미지 순
      const thumb = String(
        (A.representImage && A.representImage.url)
        || ((document.querySelector('meta[property="og:image"]') || {}).content || '')
        || ((document.querySelector('img[src*="shop-phinf.pstatic.net"]') || {}).src || '')
      ).slice(0, 500);
      const nss = A.naverShoppingSearchInfo || {};
      const payload = {
        channel_product_id: String(cpid),
        title: String(A.name || A.dispName || getProductName() || '').slice(0, 300),
        brand: String(nss.brandName || nss.manufacturerName || '').slice(0, 120),
        mall_name: String(channel.channelName || getStoreName() || '').slice(0, 150),
        price: num(A.salePrice || getProductPrice() || 0) || null,
        seller_tags: tags.slice(0, 60),
        category: String((A.category && A.category.wholeCategoryName) || '').slice(0, 191),
        thumbnail_url: thumb || null,
      };
      if (!payload.title && !payload.seller_tags.length) return;   // 아무것도 못 뽑으면 스킵
      await sendBg('saveProductInfo', payload);
    } catch (e) { /* noop */ }
  }

  async function spFetchTop(keyword) {
    // 검색 API(search.shopping)는 봇 차단(418) → 서버가 openapi shop.json으로 대신 검색.
    const r = await sendBg('sellerCompetitors', { keyword });
    if (!r || !r.ok || !Array.isArray(r.products)) {
      return { products: [], terms: [], status: (r && r.status) || 0 };
    }
    const out = [], myUrl = location.href.split('?')[0].replace(/\/main\/products\//, '/products/');
    for (const p of r.products) {
      if (out.length >= 10) break;
      const u = String(p.url || '');
      if (!u) continue;
      if (u.split('?')[0] === myUrl) continue; // 내 상품 제외(main/products 정규화 후 비교)
      out.push({ url: u, keepCnt: 0, rank: out.length + 1 });
    }
    return { products: out, terms: [], status: 200 };
  }
  async function spFetchNode(url, extra) {
    // 경쟁 상세는 봇 차단(429)으로 fetch 불가 → 비활성 탭으로 렌더해 수집
    const r = await sendBg('sellerCollectDetail', { url });
    if (!r || !r.ok || !r.data) return null;
    return spNodeFrom(r.data, extra);
  }
  async function spFetchNodes(list) {
    const results = []; let idx = 0; let done = 0;
    async function worker() {
      while (idx < list.length) {
        const it = list[idx++];
        const node = await spFetchNode(it.url, { keepCnt: it.keepCnt, rank: it.rank });
        if (node && node.product && node.product.name) results.push(node);
        done++;
        state.sp.step = '경쟁 상품 분석 중… (' + done + '/' + list.length + ')';
        render();
      }
    }
    // 비활성 백그라운드 탭 — 동시 5탭 병렬(화면엔 안 보임)
    const CONC = Math.min(5, list.length);
    const workers = [];
    for (let i = 0; i < CONC; i++) workers.push(worker());
    await Promise.all(workers);
    return results;
  }
  async function spAnalyze() {
    const sp = state.sp;
    const keyword = (sp.keyword || '').trim();
    if (!keyword) { sp.error = '경쟁을 비교할 키워드를 입력하세요.'; render(); return; }
    sp.loading = true; sp.error = null; sp.result = null; sp.step = '내 상품 정보 읽는 중…'; render();

    const my = spNodeFrom(await getMyPreloadedAsync());
    if (!my || !my.product || !my.product.name) {
      sp.loading = false; sp.error = '이 페이지에서 상품 정보를 읽지 못했습니다. 페이지를 새로고침(F5)한 뒤 다시 시도하세요.'; render(); return;
    }
    sp.step = '‘' + keyword + '’ 경쟁 상품 검색 중…'; render();
    const top = await spFetchTop(keyword);
    if (!top.products.length) {
      sp.loading = false;
      sp.error = '검색 상위 경쟁 상품을 찾지 못했습니다. 키워드를 바꿔 보세요.' + (top.status && top.status !== 200 ? ' (HTTP ' + top.status + ')' : '');
      render(); return;
    }
    sp.step = top.products.length + '개 경쟁 상품 분석 중…'; render();
    const comps = await spFetchNodes(top.products);
    if (!comps.length) { sp.loading = false; sp.error = '경쟁 상품 데이터를 가져오지 못했습니다. 잠시 후 다시 시도하세요.'; render(); return; }
    sp.step = '셀러력 계산 중…'; render();
    const r = await sendBg('saveSellerPower', {
      keyword, terms: top.terms, my, competitors: comps, product_url: location.href.split('?')[0],
    });
    sp.loading = false;
    if (!r || !r.ok) {
      if (r && r.loggedIn === false) { state.loggedIn = false; render(); return; }
      sp.error = (r && r.message) || '셀러력 저장에 실패했습니다.'; render(); return;
    }
    sp.result = r.result; sp.savedId = r.id; render();
    requestAnimationFrame(spDrawRadar);
  }

  const SP_SEM = { ok: '#05b169', warn: '#f4b000', bad: '#cf202f' };
  function spGc(g) { return ({ S: '#05b169', A: '#0052ff', B: '#8b5cf6', C: '#f4b000', D: '#a8acb3' })[g] || '#a8acb3'; }
  function spDiffLabel(d) { return ({ easy: '쉬움', mid: '보통', hard: '어려움' })[d] || d; }
  function spDiffColor(d) { return ({ easy: '#0052ff', mid: '#f4b000', hard: '#a8acb3' })[d] || '#a8acb3'; }

  function spBodyHtml() {
    const sp = state.sp;
    if (sp.loading) {
      return '<div class="rf-sp-loading"><div class="rf-spinner"></div><div class="step">' + esc(sp.step) + '</div>' +
        '<div class="hint">내 상품과 검색 상위 10개를 비교합니다. 최대 1분 정도 걸릴 수 있어요.</div></div>';
    }
    if (sp.result) return spReportHtml(sp.result);
    return '<div class="rf-sp-form">' +
      '<label class="rf-label">비교할 키워드</label>' +
      '<input id="rfSpKw" class="rf-input" type="text" placeholder="예: 무선이어폰" value="' + esc(sp.keyword) + '" autocomplete="off">' +
      '<button type="button" id="rfSpRun" class="rf-btn-primary" style="margin-top:10px;">셀러력 분석</button>' +
      (sp.error ? '<div class="rf-error" style="margin-top:10px;">' + esc(sp.error) + '</div>' : '') +
      '<p class="rf-note">이 상품을 <b>내 상품</b>으로, 같은 키워드 검색 상위 10개를 경쟁 상품으로 비교합니다. 점수·등급은 자체 추정치(네이버 공식 아님).</p>' +
      '</div>';
  }
  function spReportHtml(r) {
    const g = r.grade || 'D', gcolor = spGc(g), score = Math.round(r.score || 0);
    const circ = 477.5, off = circ * (1 - Math.max(0, Math.min(100, score)) / 100);
    const gapTop = (r.radar_avg_total || score) - score;
    const legs = (r.axes || []).map((a) => {
      const gapc = (a.gap || 0) >= 0 ? SP_SEM.ok : SP_SEM.bad;
      return '<div class="rf-sp-leg"><span class="n">' + esc(a.key) + '</span>' +
        '<span class="v"><b>' + a.mine + '</b><s>/ 상위 ' + a.avg + '</s>' +
        '<em style="color:' + gapc + ';background:' + gapc + '22;">' + ((a.gap || 0) >= 0 ? '+' : '') + a.gap + '</em></span></div>';
    }).join('');
    const losses = (r.losses || []).map((l) => {
      const rc = l.rank === 1 ? SP_SEM.bad : SP_SEM.warn;
      return '<div class="rf-sp-loss" style="--rc:' + rc + '"><div class="rk">' + l.rank + '</div><div class="lc">' +
        '<div class="lt">' + esc(l.title) + '</div><div class="ld"><b>' + esc(l.cur) + '</b> → ' + esc(l.target) + '</div>' +
        '<div class="tg"><span style="color:#05b169;background:#05b16922;">+' + l.gain + '점</span>' +
        '<span style="color:' + spDiffColor(l.difficulty) + ';background:' + spDiffColor(l.difficulty) + '22;">난이도 ' + spDiffLabel(l.difficulty) + '</span></div></div></div>';
    }).join('');
    const rx = (r.rx || []).map((grp) => {
      const items = grp.items.map((it) => {
        const c = SP_SEM[it.state] || '#a8acb3', mk = ({ ok: '✓', warn: '!', bad: '✕' })[it.state] || '·';
        return '<div class="rf-sp-rx"><span class="mk" style="color:' + c + ';background:' + c + '22;">' + mk + '</span>' +
          '<div><b>' + esc(it.name) + '</b> <span>— ' + esc(it.tip) + '</span></div></div>';
      }).join('');
      return '<div class="rf-sp-rxg"><div class="ax">' + esc(grp.axis) + '</div>' + items + '</div>';
    }).join('');
    return '<div class="rf-sp-refresh-bar"><button type="button" id="rfSpRefresh" class="rf-sp-refresh">↻ 다시 분석</button></div>' +
      '<div class="rf-sp-hero">' +
        '<div class="kw">🔍 ‘' + esc(r.keyword) + '’ 기준</div>' +
        '<div class="gauge"><svg width="150" height="150" viewBox="0 0 176 176" style="transform:rotate(-90deg)">' +
          '<circle cx="88" cy="88" r="76" fill="none" stroke="#eef0f3" stroke-width="14"/>' +
          '<circle cx="88" cy="88" r="76" fill="none" stroke="' + gcolor + '" stroke-width="14" stroke-linecap="round" stroke-dasharray="' + circ + '" stroke-dashoffset="' + off + '"/></svg>' +
          '<div class="gc"><div class="sn" style="color:' + gcolor + '">' + score + '</div><div class="sm">셀러력 / 100</div></div></div>' +
        '<div class="grade" style="color:' + gcolor + ';background:' + gcolor + '22;">' + g + '등급</div>' +
        '<p class="verdict">' + (gapTop > 0
          ? '상위권과 <b>' + Math.round(gapTop) + '점 차이</b>. 아래 개선 우선순위부터 손보면 시장 위치가 올라갑니다.'
          : '이미 <b>상위권</b> 셀러력입니다. 강점을 유지하세요.') + '</p>' +
        '<div class="stats"><div><span>시장 상위</span><b>' + (r.market_percentile || 0) + '%</b></div>' +
          '<div><span>경쟁 순위</span><b>' + (r.rank_in_top || 0) + '/' + ((r.competitor_count || 0) + 1) + '위</b></div></div>' +
      '</div>' +
      '<div class="rf-sp-sec"><div class="st">어디서 밀리나 — 5축</div>' +
        '<div class="radar-row"><canvas id="rfSpRadar" width="360" height="360"></canvas><div class="legs">' + legs + '</div></div>' +
        '<div class="rlegend"><span><i class="mine"></i>내 상품</span><span><i class="avg"></i>상위 평균</span></div></div>' +
      (losses ? '<div class="rf-sp-sec"><div class="st">가장 큰 손해부터</div>' + losses + '</div>' : '') +
      (rx ? '<div class="rf-sp-sec"><div class="st">항목별 처방</div>' + rx + '</div>' : '') +
      '<div class="rf-sp-saved">✓ 저장됨 · 웹 콘솔(쇼핑 → 셀러력)에서 다시 볼 수 있어요</div>';
  }
  function spDrawRadar() {
    const sp = state.sp;
    if (!sp.result) return;
    const cv = document.getElementById('rfSpRadar'); if (!cv) return;
    const AXES = (sp.result.axes || []).map((a) => ({ key: a.key, mine: a.mine, avg: a.avg }));
    if (!AXES.length) return;
    const ctx = cv.getContext('2d'), dpr = window.devicePixelRatio || 1;
    cv.width = 360 * dpr; cv.height = 360 * dpr; ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    const cx = 180, cy = 180, R = 120, n = AXES.length;
    const line = '#dee1e6', faint = '#a8acb3', brand = '#0052ff', muted = '#5b616e';
    const ang = (i) => -Math.PI / 2 + i * 2 * Math.PI / n;
    ctx.clearRect(0, 0, 360, 360);
    for (let r = 1; r <= 4; r++) { ctx.beginPath();
      for (let i = 0; i <= n; i++) { const a = ang(i % n), rr = R * r / 4, x = cx + rr * Math.cos(a), y = cy + rr * Math.sin(a); i ? ctx.lineTo(x, y) : ctx.moveTo(x, y); }
      ctx.strokeStyle = line; ctx.lineWidth = 1; ctx.stroke(); }
    ctx.fillStyle = muted; ctx.font = '600 12px sans-serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    for (let i = 0; i < n; i++) { const a = ang(i);
      ctx.beginPath(); ctx.moveTo(cx, cy); ctx.lineTo(cx + R * Math.cos(a), cy + R * Math.sin(a)); ctx.strokeStyle = line; ctx.stroke();
      ctx.fillText(AXES[i].key, cx + (R + 20) * Math.cos(a), cy + (R + 20) * Math.sin(a)); }
    const poly = (vals, stroke, fill, dash) => { ctx.beginPath();
      for (let i = 0; i <= n; i++) { const idx = i % n, a = ang(idx), v = vals[idx] / 100, x = cx + R * v * Math.cos(a), y = cy + R * v * Math.sin(a); i ? ctx.lineTo(x, y) : ctx.moveTo(x, y); }
      if (fill) { ctx.fillStyle = fill; ctx.fill(); } ctx.strokeStyle = stroke; ctx.lineWidth = 2; ctx.setLineDash(dash || []); ctx.stroke(); ctx.setLineDash([]); };
    poly(AXES.map((a) => a.avg), faint, null, [5, 4]);
    poly(AXES.map((a) => a.mine), brand, 'rgba(59,91,255,.16)', null);
    for (let i = 0; i < n; i++) { const a = ang(i), v = AXES[i].mine / 100, x = cx + R * v * Math.cos(a), y = cy + R * v * Math.sin(a);
      ctx.beginPath(); ctx.arc(x, y, 3, 0, 2 * Math.PI); ctx.fillStyle = brand; ctx.fill(); }
  }

  // ------------------------------------------------------------------
  // 마운트
  // ------------------------------------------------------------------
  function mount() {
    if (document.getElementById(FAB_ID)) return;

    const fab = h(
      '<button type="button" id="' + FAB_ID + '" title="RankFree — 상품 분석·셀러력">' +
      '<span class="rf-fab-logo">R</span><span class="rf-fab-text">RankFree</span></button>'
    );
    const panel = h(
      '<div id="' + PANEL_ID + '" hidden>' +
      '<div class="rf-head">' +
      '<div class="rf-brand">Rank<b>Free</b><span class="rf-query"></span></div>' +
      '<div class="rf-head-btns"><button type="button" class="rf-close" title="닫기">×</button></div>' +
      '</div>' +
      '<div class="rf-sub">' + esc(getProductName()).slice(0, 60) + '</div>' +
      '<div class="rf-tabs"></div>' +
      '<div class="rf-body"></div>' +
      '<div class="rf-foot"></div>' +
      '</div>'
    );
    document.documentElement.appendChild(fab);
    document.documentElement.appendChild(panel);

    fab.addEventListener('click', async () => {
      const isOpen = !panel.hidden;
      panel.hidden = isOpen;
      if (!isOpen) {
        const res = await sendBg('session');
        state.loggedIn = Boolean(res && res.loggedIn);
        state.user = (res && res.user) || null;
        render();
      }
    });
    panel.querySelector('.rf-close').addEventListener('click', () => {
      panel.hidden = true;
    });
  }

  // 셀러력 경쟁 수집 모드 — 비활성 탭에서 데이터만 추출해 background로 전송(패널 미표시)
  async function runSpCollect() {
    let data = null;
    try {
      let pre = null;
      for (let i = 0; i < 10 && !data; i++) {
        pre = getMyPreloaded();
        if (!pre) pre = await getMyPreloadedAsync(100);
        if (pre) {
          const A = (pre.simpleProductForDetailPage && pre.simpleProductForDetailPage.A) || (pre.product && pre.product.A) || pre.product || null;
          const channel = (pre.smartStoreV2 && pre.smartStoreV2.channel) || pre.channel || (A && A.channel) || null;
          if (channel && channel.channelUid) data = {
            product: { A: A || {} },
            smartStoreV2: { channel: channel },
            mallInfoCache: pre.mallInfoCache || null,
          };
        }
        if (!data) await new Promise((r) => setTimeout(r, 50));
      }
    } catch (e) { /* noop */ }
    try { chrome.runtime.sendMessage({ type: '__spCollected', ok: !!data, data: data }); } catch (e) { /* noop */ }
  }

  // 검색 리스트의 [리뷰분석]으로 열린 경우 — 패널 열고 상품분석 자동 실행
  async function autoOpenReview() {
    const panel = document.getElementById(PANEL_ID);
    if (!panel) return;
    panel.hidden = false;
    const res = await sendBg('session');
    state.loggedIn = Boolean(res && res.loggedIn);
    state.user = (res && res.user) || null;
    state.tab = 'analyze';
    render();
    if (state.loggedIn) setTimeout(() => { if (!state.loading) analyze(); }, 1200);
  }

  /** 전체 진행률(%) — 세부 단계 문구를 한 개의 진행 %로 환산(패널엔 '상품 분석 중 N%'만 표시). */
  function overallReviewPct() {
    const t = state.progress || '';
    const mPage = t.match(/\((\d+)\s*\/\s*(\d+)\s*페이지\)/);
    if (mPage) {
      const d = Number(mPage[1]) || 0, tot = Number(mPage[2]) || 1;
      return 15 + Math.min(75, Math.round((d / Math.max(1, tot)) * 75)); // 페이지 수집: 15→90%
    }
    if (/문의/.test(t)) return 92;
    if (/분석|집계|산출/.test(t)) return 95;
    if (/페이지당|측정/.test(t)) return 12;
    if (/불러오는/.test(t)) return 8;
    if (/상품 정보|확인/.test(t)) return 4;
    return state.loading ? 5 : 0;
  }

  /** 상품(리뷰) 분석 수집 모드 — 백그라운드 탭에서 분석 후 리포트 HTML을 검색 패널로 전달. */
  async function runReviewCollect() {
    const out = { ok: false, html: '', name: '', message: '' };
    let progTimer = null;
    // 수집 중 클릭 유도가 스마트스토어 SPA를 상품 메인으로 '하드 이동'시키지 않게 —
    // 앵커 기본 이동/해시 변경만 차단(preventDefault). React onClick(리뷰 지연로딩)은 그대로 실행.
    const navGuard = (e) => {
      try { const a = e.target && e.target.closest && e.target.closest('a[href]'); if (a) e.preventDefault(); } catch (er) { /* noop */ }
    };
    try { document.addEventListener('click', navGuard, true); } catch (e) { /* noop */ }
    try {
      mount(); // 패널 생성(백그라운드 탭이라 화면엔 안 보임)
      const panel = document.getElementById(PANEL_ID);
      if (panel) panel.hidden = true;
      const res = await sendBg('session');
      state.loggedIn = Boolean(res && res.loggedIn);
      state.user = (res && res.user) || null;
      state.tab = 'analyze';
      state.target = state.target || 500;
      // 진행률 중계 — 세부 단계 문구 대신 전체 진행 %만 원본(검색) 패널로 전달.
      // 캡처·측정 단계(<15%)는 분모가 없어 멈춰 보이므로 시간 기반으로 조금씩 크립(최대 14%).
      let lastPct = -1;
      let creep = 0;
      progTimer = setInterval(() => {
        let pct = overallReviewPct();
        if (pct < 15) { creep = Math.min(10, creep + 1); pct = Math.min(14, pct + creep); }
        else creep = 0;
        if (pct !== lastPct) {
          lastPct = pct;
          try { chrome.runtime.sendMessage({ type: '__reviewProgress', pct: pct }); } catch (e) { /* noop */ }
        }
      }, 400);
      await analyze();
      if (state.result) {
        out.ok = true;
        out.html = bodyHtml();
        out.name = (document.title || '').replace(/\s*:\s*네이버.*$/, '').trim();
        out.id = state.savedId || null;
        out.share_token = state.shareToken || null;
      } else {
        out.message = state.error || '리뷰 분석에 실패했습니다.';
      }
    } catch (e) {
      out.message = String((e && e.message) || e);
    }
    if (progTimer) { clearInterval(progTimer); progTimer = null; }
    try { document.removeEventListener('click', navGuard, true); } catch (e) { /* noop */ }
    try { chrome.runtime.sendMessage({ type: '__reviewCollected', ok: out.ok, html: out.html, name: out.name, message: out.message, id: out.id, share_token: out.share_token }); } catch (e) { /* noop */ }
  }

  // 수집 모드 판정 — 해시가 클릭/라우팅으로 유실돼도 sessionStorage(injected-store가 저장)로 복원
  function collectHash() {
    if (location.hash === '#rfspcollect' || location.hash === '#rfreviewcollect') return location.hash;
    try {
      const m = sessionStorage.getItem('rfCollectMode');
      if (m === '#rfspcollect' || m === '#rfreviewcollect') return m;
    } catch (e) { /* noop */ }
    return '';
  }
  const rfCh = collectHash();
  if (rfCh === '#rfspcollect') {
    runSpCollect();
  } else if (rfCh === '#rfreviewcollect') {
    runReviewCollect();
  } else {
    mount();
    collectProductInfo();   // 상품정보 자동 수집(노출 키워드 분석 조합 재료)
    if (location.hash === '#rankfree-review') autoOpenReview();
  }
})();
