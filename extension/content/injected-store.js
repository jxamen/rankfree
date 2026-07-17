/**
 * MAIN world 스크립트 (스마트스토어 상품 페이지) — 페이지가 리뷰 API
 * (/i/v1/contents/reviews/*)를 호출할 때 쓰는 x-client-* 헤더를 가로채
 * content script(product.js)로 전달한다. 없어도 동작을 시도하지만,
 * 있으면 그대로 재사용해 차단 확률을 낮춘다.
 */
(function () {
  'use strict';

  // 수집 탭(#rfreviewcollect/#rfspcollect)에서 리뷰 섹션 지연로딩을 유도.
  // 센티넬(해시)이 클릭/라우팅으로 유실돼도 유지되도록 sessionStorage에도 백업한다.
  try {
    var h = location.hash;
    var isCollect = h === '#rfreviewcollect' || h === '#rfspcollect';
    var isReviewCollect = h === '#rfreviewcollect';
    try {
      if (isCollect) sessionStorage.setItem('rfCollectMode', h);         // 해시 유실 대비 모드 백업
      else if (sessionStorage.getItem('rfCollectMode')) {
        isCollect = true; // 클릭/라우팅으로 해시 유실돼도 유지
        isReviewCollect = sessionStorage.getItem('rfCollectMode') === '#rfreviewcollect';
      }
    } catch (e) { /* noop */ }
    if (isCollect) {
      Object.defineProperty(document, 'hidden', { configurable: true, get: function () { return false; } });
      Object.defineProperty(document, 'visibilityState', { configurable: true, get: function () { return 'visible'; } });
      document.hasFocus = function () { return true; };
      var swallowVis = function (e) { e.stopImmediatePropagation(); };
      document.addEventListener('visibilitychange', swallowVis, true);
      window.addEventListener('visibilitychange', swallowVis, true);

      // IntersectionObserver 래핑 — 단, 합성 '교차'는 '리뷰 섹션 내부 요소'에만 발화한다.
      // (모든 요소에 무차별 발화하면 Next.js 스크롤스파이가 전 섹션을 동시 교차로 보고
      //  활성 섹션을 상품 메인으로 수렴시켜 '리뷰 못 열고 메인으로 감' 부작용이 난다.)
      var RealIO = window.IntersectionObserver;
      var inReview = function (el) {
        try { return !!(el && el.closest && el.closest('#REVIEW,[id*="review" i],[id*="Review"],[class*="review" i],[class*="Review"],[data-shp-area-id*="rev" i]')); } catch (e) { return false; }
      };
      if (RealIO && isReviewCollect) {
        var WrappedIO = function (cb, opts) {
          var io = new RealIO(cb, opts);
          var realObserve = io.observe.bind(io);
          io.observe = function (el) {
            try { realObserve(el); } catch (e) { /* noop */ }
            if (!inReview(el)) return; // 리뷰 섹션만 강제 교차 — 그 외는 실제 IO 그대로
            try {
              setTimeout(function () {
                var rect = (el && el.getBoundingClientRect) ? el.getBoundingClientRect() : { top: 0, left: 0, bottom: 0, right: 0, width: 0, height: 0 };
                cb([{
                  isIntersecting: true, intersectionRatio: 1, target: el,
                  boundingClientRect: rect, intersectionRect: rect, rootBounds: rect, time: 0,
                }], io);
              }, 0);
            } catch (e) { /* noop */ }
          };
          return io;
        };
        WrappedIO.prototype = RealIO.prototype;
        window.IntersectionObserver = WrappedIO;
      }
    }
  } catch (e) { /* noop */ }

  // x-client-rts(타임스탬프)는 x-client-rtk(그에 대한 서명)와 반드시 짝으로 보내야 검증됨.
  var WANT = ['x-client-rtk', 'x-client-rts', 'x-client-version', 'x-client-lct', 'x-service-type'];
  var lastHeaders = null; // 마지막 캡처본 — content script가 늦게 붙어도 재조회 가능

  function report(headers) {
    if (!headers) return;
    var picked = {};
    var found = false;
    for (var i = 0; i < WANT.length; i++) {
      var v = headers[WANT[i]];
      if (v) {
        picked[WANT[i]] = String(v);
        found = true;
      }
    }
    if (!found) return;
    lastHeaders = picked;
    try {
      document.dispatchEvent(new CustomEvent('rankfree:store-headers', { detail: JSON.stringify(picked) }));
    } catch (e) {
      /* noop */
    }
  }

  // content script가 요청하면 마지막 캡처 헤더를 다시 쏴준다(이벤트 유실 대비)
  document.addEventListener('rankfree:get-headers', function () {
    if (!lastHeaders) return;
    try {
      document.dispatchEvent(new CustomEvent('rankfree:store-headers', { detail: JSON.stringify(lastHeaders) }));
    } catch (e) {
      /* noop */
    }
  });

  // __PRELOADED_STATE__ 제공 — SPA hydration 후 script 태그가 DOM에서 제거돼
  // content script가 못 읽는 경우가 있어, MAIN world 전역에서 직접 읽어 전달한다(셀러력).
  document.addEventListener('rankfree:get-preloaded', function () {
    try {
      var st = window.__PRELOADED_STATE__;
      if (!st) {
        var keys = Object.keys(window).filter(function (k) {
          return /PRELOAD|NEXT_DATA|INITIAL_STATE|__NUXT|APOLLO|__REACT|STORE_STATE/i.test(k);
        });
        console.log('[RankFree] __PRELOADED_STATE__ 없음 · MAIN world 후보 전역:', keys.join(',') || '(없음)');
        // __NEXT_DATA__(Next.js) 폴백
        try {
          var nd = document.getElementById('__NEXT_DATA__');
          if (nd) {
            var j = JSON.parse(nd.textContent);
            var pp = j && j.props && j.props.pageProps;
            st = (pp && (pp.initialState || pp.dehydratedState || pp)) || null;
            if (st) console.log('[RankFree] __NEXT_DATA__에서 상태 확보:', Object.keys(st).slice(0, 12).join(','));
          }
        } catch (e2) { /* noop */ }
      } else {
        console.log('[RankFree] __PRELOADED_STATE__ 확보:', Object.keys(st).slice(0, 12).join(','));
      }
      if (!st) return;
      // 신구조 우선: simpleProductForDetailPage.A + st.channel
      var A =
        (st.simpleProductForDetailPage && st.simpleProductForDetailPage.A) ? st.simpleProductForDetailPage.A
        : (st.product && st.product.A) ? st.product.A
        : (st.product || null);
      var pick = {
        product: A ? { A: A } : null,
        smartStoreV2: st.smartStoreV2 || (st.channel ? { channel: st.channel } : null),
        mallInfoCache: st.mallInfoCache || null,
        blogInfo: st.blogInfo || null,
      };
      document.dispatchEvent(new CustomEvent('rankfree:preloaded', { detail: JSON.stringify(pick) }));
    } catch (e) {
      /* noop */
    }
  });

  function headersToObject(headers) {
    var out = {};
    try {
      if (typeof Headers !== 'undefined' && headers instanceof Headers) {
        headers.forEach(function (v, k) {
          out[k.toLowerCase()] = v;
        });
        return out;
      }
      if (Array.isArray(headers)) {
        for (var i = 0; i < headers.length; i++) out[String(headers[i][0]).toLowerCase()] = headers[i][1];
        return out;
      }
      for (var k in headers) out[k.toLowerCase()] = headers[k];
    } catch (e) {
      /* noop */
    }
    return out;
  }

  // 페이지가 보내는 리뷰 요청의 body — 정확한 checkoutMerchantNo/originProductNo 확보
  function reportBody(bodyText) {
    try {
      var b = JSON.parse(String(bodyText));
      if (b && b.originProductNo) {
        document.dispatchEvent(
          new CustomEvent('rankfree:store-params', {
            detail: JSON.stringify({
              checkoutMerchantNo: b.checkoutMerchantNo,
              originProductNo: b.originProductNo,
            }),
          })
        );
      }
    } catch (e) {
      /* noop */
    }
  }

  // 리뷰 요청 전체(url/method/body/headers)를 통째로 캡처 — content script가 page/정렬만 바꿔 재현.
  // 네이버가 스키마를 바꿔도(단수→group-products/originProductNos 등) 페이지 요청 그대로면 통과.
  function reportReviewReq(method, url, bodyText, headers) {
    try {
      document.dispatchEvent(
        new CustomEvent('rankfree:review-req', {
          detail: JSON.stringify({ method: method || 'POST', url: String(url), body: bodyText || null, headers: headers || null }),
        })
      );
    } catch (e) {
      /* noop */
    }
  }

  // 상품 문의(QnA) 요청 — 엔드포인트가 불확실하므로 method/url/body 통째로 캡처해 재사용
  function isQnaUrl(url) {
    return /inquir|\/qnas?\b|question|contents\/qna/i.test(String(url));
  }
  function reportQna(method, url, bodyText) {
    try {
      document.dispatchEvent(
        new CustomEvent('rankfree:store-qna', {
          detail: JSON.stringify({ method: method || 'GET', url: String(url), body: bodyText || null }),
        })
      );
    } catch (e) {
      /* noop */
    }
  }

  var origFetch = window.fetch;
  window.fetch = function (input, init) {
    try {
      var url = typeof input === 'string' ? input : (input && input.url) || '';
      var method = (init && init.method) || (typeof Request !== 'undefined' && input instanceof Request ? input.method : 'GET');
      var isReview = url.indexOf('/contents/reviews') !== -1;
      var isQna = isQnaUrl(url);
      if (isReview || isQna) {
        var headers = (init && init.headers) ||
          (typeof Request !== 'undefined' && input instanceof Request ? input.headers : null);
        var hdrObj = headersToObject(headers);
        report(hdrObj);
        var handle = function (bodyText) {
          if (isReview) { reportBody(bodyText); reportReviewReq(method, url, bodyText, hdrObj); }
          if (isQna) reportQna(method, url, bodyText);
        };
        if (init && typeof init.body === 'string') handle(init.body);
        else if (typeof Request !== 'undefined' && input instanceof Request) {
          try {
            input.clone().text().then(handle);
          } catch (e2) {
            handle(null);
          }
        } else {
          handle(null);
        }
      }
    } catch (e) {
      /* noop */
    }
    return origFetch.apply(this, arguments);
  };

  // XHR 경로도 커버
  var origOpen = XMLHttpRequest.prototype.open;
  var origSetHeader = XMLHttpRequest.prototype.setRequestHeader;
  var origSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.open = function (method, url) {
    this.__rfReviewApi = String(url).indexOf('/contents/reviews') !== -1;
    this.__rfQnaApi = isQnaUrl(url);
    this.__rfMethod = method || 'GET';
    this.__rfUrl = String(url);
    this.__rfHeaders = {};
    return origOpen.apply(this, arguments);
  };
  XMLHttpRequest.prototype.setRequestHeader = function (name, value) {
    try {
      if (this.__rfReviewApi || this.__rfQnaApi) this.__rfHeaders[String(name).toLowerCase()] = value;
    } catch (e) {
      /* noop */
    }
    return origSetHeader.apply(this, arguments);
  };
  XMLHttpRequest.prototype.send = function (body) {
    try {
      if (this.__rfReviewApi || this.__rfQnaApi) {
        report(this.__rfHeaders);
        var bt = typeof body === 'string' ? body : null;
        if (this.__rfReviewApi) { if (bt) reportBody(bt); reportReviewReq(this.__rfMethod, this.__rfUrl, bt, this.__rfHeaders); }
        if (this.__rfQnaApi) reportQna(this.__rfMethod, this.__rfUrl, bt);
      }
    } catch (e) {
      /* noop */
    }
    return origSend.apply(this, arguments);
  };
})();
