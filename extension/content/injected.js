/**
 * MAIN world 스크립트 — 네이버 쇼핑 페이지가 자체적으로 /api/search/all 을 호출할 때
 * 사용하는 x-wtm-ncaptcha-token 헤더를 가로채 content script(ISOLATED world)로 전달한다.
 * 토큰이 없어도 동작은 하지만(쿠키 기반), 있으면 그대로 재사용해 차단 확률을 낮춘다.
 */
(function () {
  'use strict';

  var TOKEN_HEADER = 'x-wtm-ncaptcha-token';

  function report(token) {
    if (!token) return;
    try {
      document.dispatchEvent(
        new CustomEvent('rankfree:ncaptcha-token', { detail: String(token) })
      );
    } catch (e) {
      /* noop */
    }
  }

  function extractFromHeaders(headers) {
    if (!headers) return null;
    try {
      if (typeof Headers !== 'undefined' && headers instanceof Headers) {
        return headers.get(TOKEN_HEADER);
      }
      if (Array.isArray(headers)) {
        for (var i = 0; i < headers.length; i++) {
          if (String(headers[i][0]).toLowerCase() === TOKEN_HEADER) return headers[i][1];
        }
        return null;
      }
      for (var key in headers) {
        if (key.toLowerCase() === TOKEN_HEADER) return headers[key];
      }
    } catch (e) {
      /* noop */
    }
    return null;
  }

  // fetch 후킹
  var origFetch = window.fetch;
  window.fetch = function (input, init) {
    try {
      var url = typeof input === 'string' ? input : (input && input.url) || '';
      if (url.indexOf('/api/search/') !== -1) {
        var token =
          extractFromHeaders(init && init.headers) ||
          (typeof Request !== 'undefined' && input instanceof Request
            ? input.headers.get(TOKEN_HEADER)
            : null);
        report(token);
      }
    } catch (e) {
      /* noop */
    }
    return origFetch.apply(this, arguments);
  };

  // XHR 후킹
  var origSetHeader = XMLHttpRequest.prototype.setRequestHeader;
  XMLHttpRequest.prototype.setRequestHeader = function (name, value) {
    try {
      if (String(name).toLowerCase() === TOKEN_HEADER) report(value);
    } catch (e) {
      /* noop */
    }
    return origSetHeader.apply(this, arguments);
  };
})();
