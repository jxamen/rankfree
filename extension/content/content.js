/**
 * RankFree 쇼핑 시장분석 — content script.
 *
 * search.shopping.naver.com/search/* 에서 동작.
 *  1) rankfree.kr 로그인 게이트 (미로그인 시 로그인 폼만 노출)
 *  2) 페이지와 같은 오리진으로 /api/search/all 을 호출해 상품 수집
 *     (6개월 구매건수 × 판매가 → 시장 규모 추정)
 *  3) rankfree 서버의 키워드 분석(월간 검색량 등)을 합쳐서 표시
 *  4) 탭 구조로 기능 전환 (v0.1은 '시장 분석'만 구현, 나머지는 준비 중)
 */
(function () {
  'use strict';

  // ------------------------------------------------------------------
  // 상태
  // ------------------------------------------------------------------
  const state = {
    ncaptchaToken: null,
    loggedIn: false,
    user: null,
    query: null,
    tab: (function () { // 세부 탭
      if (typeof location === 'undefined') return 'seller';
      if (location.hostname === 'search.naver.com') return 'keyword';
      if (/(^|\.)map\.naver\.com$/.test(location.hostname)) return 'pmarket';
      return 'seller';
    })(),
    topTab: (function () { // 상위 탭: unified(통합분석)·place(플레이스)·shop(쇼핑)·summary(요약)
      if (typeof location === 'undefined') return 'shop';
      if (location.hostname === 'search.naver.com') return 'unified';
      if (/(^|\.)map\.naver\.com$/.test(location.hostname)) return 'place';
      return 'shop';
    })(),
    place: { loading: false, error: null, items: null, total: 0, cat: '', selected: null }, // 플레이스 시장/매장 분석
    count: 80, // 시장분석 수집 상품수(40/80/160/240)
    includeAds: false,
    marginPct: 30,
    loading: false,
    extracting: false, // 연관 키워드 자동 추출 중
    progress: null, // 수집 진행 상황 문구
    error: null,
    products: [], // 정규화된 상품 목록 (광고 포함)
    totalCount: 0, // 검색결과 전체 상품수
    keywordData: undefined, // undefined=미조회, null=없음, object=데이터
    keywordLoading: false, // 키워드 분석 조회 중(중복 호출 방지)
    keywordMsg: null, // 키워드 조회 실패 사유
    relatedTags: [], // 네이버 쇼핑 연관검색어
    showSettings: false,
    apiKey: '', // rankfree API 키 (v1 키워드 분석용)
    apiBase: null,
    savedId: null, // 서버 저장된 분석 id
    saveLimitMsg: null, // 저장 한도 초과 메시지
    history: undefined, // 저장된 분석 내역 목록
    historyLoading: false,
    snapshot: null, // 서버에서 불러온 저장본(내역 보기 모드)
    lastAnalyzedQuery: null,
    urlChangedSinceLoad: false, // SPA 내 이동 후에는 DOM의 SSR 데이터를 신뢰하지 않음
    seller: { loading: false, error: null, result: null, targetTitle: '', count: 80 }, // 셀러력 탭(수집 개수)
    product: { loading: false, error: null, html: '', targetTitle: '', targetLink: '' }, // 상품 분석 탭(리뷰 분석 in-panel)
    captured: {}, // 페이지가 스스로 받은 검색 응답 캡처(키워드별) — 우리 재요청 없이 사용
  };

  // 페이지가 쓰는 nCaptcha 토큰 가로채기 (injected.js → CustomEvent)
  document.addEventListener('rankfree:ncaptcha-token', (e) => {
    if (e && e.detail) state.ncaptchaToken = String(e.detail);
  });

  // 페이지가 스스로 받은 검색 응답 캡처 → 우리 재요청 없이 그대로 사용 (injected.js → CustomEvent)
  document.addEventListener('rankfree:search-response', (e) => {
    try {
      const d = JSON.parse(e.detail);
      const url = d.url || '';
      const qm = String(url).match(/[?&]query=([^&]*)/);
      const q = qm ? decodeURIComponent(qm[1].replace(/\+/g, ' ')) : (state.query || getQueryFromUrl());
      if (!q) return;
      const json = JSON.parse(d.body);
      const out = extractProducts(json);
      if (!out || !out.products.length) return;
      ingestCaptured(q, out.products, out.total, findRelatedTags(json));
    } catch (err) {
      /* noop — 검색 외 응답이거나 파싱 실패 */
    }
  });

  // 캡처 병합(키워드별, id 기준 dedup·순서 유지)
  function ingestCaptured(q, products, total, relatedTags) {
    const key = spCacheKey(q);
    let c = state.captured[key];
    if (!c) c = state.captured[key] = { products: [], total: 0, relatedTags: [], at: 0, seen: {} };
    for (const p of products) {
      const id = p.id || p.title + '|' + p.mallName;
      if (c.seen[id]) continue;
      c.seen[id] = true;
      p.rank = c.products.length + 1;
      c.products.push(p);
    }
    if (total) c.total = total;
    if (relatedTags && relatedTags.length && !c.relatedTags.length) c.relatedTags = relatedTags;
    c.at = Date.now();
  }

  function getCaptured(q) {
    const c = state.captured[spCacheKey(q)];
    return c && c.products.length ? c : null;
  }

  // 검색 API는 nCaptcha 토큰 없으면 418. 페이지가 자체 검색 호출 시 토큰이 캡처되므로 잠깐 대기.
  function waitForToken(maxMs) {
    if (state.ncaptchaToken) return Promise.resolve(true);
    return new Promise((resolve) => {
      const start = Date.now();
      const iv = setInterval(() => {
        if (state.ncaptchaToken || Date.now() - start > maxMs) {
          clearInterval(iv);
          resolve(Boolean(state.ncaptchaToken));
        }
      }, 150);
    });
  }

  // ── 수집 캐시 ─ 같은 키워드는 12시간에 1번만 실제 수집(새로고침 시 재수집 방지) ──
  const SP_CACHE_TTL = 24 * 3600 * 1000; // 24시간 — 동일 키워드는 하루 1회만 실제 수집(다시 수집 버튼은 강제)
  function spCacheKey(q) {
    // v2: talkAccountId 추출 추가 이후 캐시 무효화(옛 캐시엔 talkId 없음)
    return 'rfSpCache:v2:' + String(q || '').trim().toLowerCase();
  }
  function getSpCache(q) {
    return new Promise((resolve) => {
      try {
        const key = spCacheKey(q);
        chrome.storage.local.get(key, (obj) => {
          const c = obj && obj[key];
          if (c && c.at && Date.now() - c.at < SP_CACHE_TTL && c.products && c.products.length) resolve(c);
          else resolve(null);
        });
      } catch (e) {
        resolve(null);
      }
    });
  }
  function setSpCache(q, data) {
    try {
      chrome.storage.local.set({
        [spCacheKey(q)]: {
          at: Date.now(),
          products: (data && data.products) || [],
          total: (data && data.total) || 0,
          relatedTags: (data && data.relatedTags) || [],
        },
      });
      pruneSpCache();
    } catch (e) {
      /* noop */
    }
  }
  // 만료된(12h 초과) 캐시 항목 제거 — 무한 누적 방지
  function pruneSpCache() {
    try {
      chrome.storage.local.get(null, (all) => {
        const dead = [];
        for (const k in all) {
          if (k.indexOf('rfSpCache:') === 0) {
            const c = all[k];
            if (!c || !c.at || Date.now() - c.at >= SP_CACHE_TTL) dead.push(k);
          }
        }
        if (dead.length) chrome.storage.local.remove(dead);
      });
    } catch (e) {
      /* noop */
    }
  }

  const harvestedCounts = {}; // 키워드별 지금까지 수집한 상품수 — 더 많은 세트가 오면 재수집(전체 확보)

  /**
   * 로딩된 문서 JSON을 먼저 파싱하고, 조직 상품이 count에 못 미치면 HTML 페이지를 추가로 GET.
   * 스크롤·화면 캡처·/api/search POST 없이, 페이지 HTML에 들어있는 JSON만 사용한다.
   */
  async function collectFromDocAndHtml(query, count) {
    const seen = new Set();
    const all = [];
    let total = 0;
    let relatedTags = [];
    const add = (out) => {
      if (!out || !out.products) return;
      if (out.total) total = out.total;
      if (!relatedTags.length && out.relatedTags && out.relatedTags.length) relatedTags = out.relatedTags;
      for (const p of out.products) {
        const k = p.id || p.title + '|' + p.mallName;
        if (seen.has(k)) continue;
        seen.add(k);
        all.push(p);
      }
    };
    const organicN = () => all.filter((p) => !p.isAd).length;

    // 1) 현재 로딩된 문서의 JSON (요청 없음)
    let havePage1 = false;
    if (!state.urlChangedSinceLoad) {
      const d = domNextData();
      if (d && d.products && d.products.length) { add(d); havePage1 = true; }
    }

    // 2) 부족하면 HTML 페이지를 추가로 GET (스크롤·API 아님)
    const startPage = havePage1 ? 2 : 1;
    for (let i = startPage; organicN() < count && i <= startPage + 4; i++) {
      state.progress = '상품 수집 중… (' + organicN() + '/' + count + ')';
      render();
      const before = all.length;
      try {
        const h = await fetchHtmlPage(query, i);
        if (!h.products.length) break; // 마지막 페이지
        add(h);
      } catch (e) {
        break; // 더 못 받으면 지금까지로
      }
      if (all.length <= before) break; // 새 상품 없음(중복/끝) → 중단
      await new Promise((r) => setTimeout(r, 400));
    }

    all.forEach((p, idx) => { p.rank = p.rank || idx + 1; });
    tagOfficialFromDom(all); // 현재 렌더된 리스트에서 공식(ico_public) 배지 태깅
    return all.length ? { products: all, total, relatedTags } : null;
  }

  /** 리스트 DOM에서 "공식(ico_public)" 배지 상품을 nvMid로 매핑해 official 태깅(현재 렌더된 페이지 한정) */
  function tagOfficialFromDom(products) {
    try {
      const official = new Set();
      document.querySelectorAll('[class*="ico_public"]').forEach((el) => {
        let card = el;
        for (let i = 0; i < 8 && card; i++) {
          const a = card.querySelector && card.querySelector('a[href*="nvMid="], a[href*="/products/"]');
          if (a) {
            const href = a.getAttribute('href') || '';
            const m = href.match(/nvMid=(\d+)/) || href.match(/\/products\/(\d+)/);
            if (m) { official.add(m[1]); break; }
          }
          card = card.parentElement;
        }
      });
      if (!official.size) return;
      for (const p of products) {
        if ((p.nvMid && official.has(String(p.nvMid))) || (p.id && official.has(String(p.id)))) p.official = 1;
      }
    } catch (e) { /* noop */ }
  }

  /** 현재 탭이 네이버 쇼핑 검색 페이지인지 — 아니면(통합검색 등) 수집 시 쇼핑 페이지를 백그라운드로 열어야 함 */
  function isShoppingPage() {
    return typeof location !== 'undefined' && location.hostname === 'search.shopping.naver.com';
  }

  /** 통합검색 등 비쇼핑 페이지 — 쇼핑 검색 페이지를 백그라운드 탭으로 열어 수집(현재 origin으로는 /search 요청 불가) */
  async function collectViaShoppingTab(query, count) {
    state.progress = '쇼핑 검색 페이지를 열어 상품을 수집하는 중…';
    render();
    const r = await sendBg('collectShopping', { keyword: query, count });
    if (r && r.ok && r.products && r.products.length) {
      return { products: r.products, total: r.total || 0, relatedTags: r.relatedTags || [] };
    }
    return null;
  }

  /** 로딩된 HTML JSON 우선 → 캐시 → 최후에만 토큰 API. */
  async function collectCached(query, count, force) {
    let page = null;

    // 동일 키워드는 하루 1회만 실제 수집 — 다시 수집(force)이 아니고 24h 캐시가 요청 개수 이상이면 캐시 사용.
    if (!force) {
      const cached = await getSpCache(query);
      if (cached && cached.products && cached.products.length >= count) {
        return { ...cached, fromCache: true };
      }
    }

    // 쇼핑 검색 페이지가 아니면(통합검색 등) 백그라운드로 쇼핑 페이지를 열어 수집
    if (!isShoppingPage()) {
      page = await collectViaShoppingTab(query, count);
      if (page && page.products.length) {
        setSpCache(query, page);
        const hk0 = spCacheKey(query);
        if (page.products.length > (harvestedCounts[hk0] || 0)) {
          harvestedCounts[hk0] = page.products.length;
          harvestTalkContacts(query, page.products);
        }
        return page;
      }
      // 백그라운드 수집 실패 → 12h 캐시 폴백
      if (!force) {
        const cached = await getSpCache(query);
        if (cached && cached.products.length) return { ...cached, fromCache: true };
      }
      return page; // null 가능
    }

    // 1) 로딩된 문서 JSON + 부족분 HTML 페이지 GET (요청 최소·화면 캡처/스크롤 없음)
    page = await collectFromDocAndHtml(query, count);
    if (page) setSpCache(query, page);
    // 2) 12h 캐시
    if (!page && !force) {
      const cached = await getSpCache(query);
      if (cached && cached.products.length) page = { ...cached, fromCache: true };
    }
    // 3) 그래도 없으면 최후로 토큰 API 수집
    if (!page) {
      page = await collectProducts(query, Math.max(1, Math.ceil(count / 80)));
      setSpCache(query, page);
    }
    // 이전보다 많은 상품이 확보되면 톡톡 재수집(전체 talkAccountId 확보)
    const hk = spCacheKey(query);
    const n = (page && page.products && page.products.length) || 0;
    if (n > (harvestedCounts[hk] || 0)) {
      harvestedCounts[hk] = n;
      harvestTalkContacts(query, page.products);
    }
    return page;
  }

  /** 수집된 상품에서 talkAccountId(톡톡 코드)가 있는 업체를 서버에 저장(슈퍼어드민 조회용). */
  function harvestTalkContacts(query, products) {
    try {
      const seen = new Set();
      const contacts = [];
      for (const p of products) {
        const tid = p.talkId; // talkAccountId 있는 업체만
        if (!tid || seen.has(tid)) continue;
        seen.add(tid);
        contacts.push({ mall_name: p.mallName || '', rank: p.isAd ? 0 : (p.rank || 0), talk_id: tid });
        if (contacts.length >= 300) break;
      }
      if (contacts.length) sendBg('harvestTalk', { keyword: query, contacts });
    } catch (e) {
      /* noop */
    }
  }

  // ------------------------------------------------------------------
  // 유틸
  // ------------------------------------------------------------------
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function stripTags(s) {
    return String(s == null ? '' : s).replace(/<[^>]*>/g, '');
  }

  function num(v) {
    if (v == null || v === '') return 0;
    const n = Number(String(v).replace(/[^0-9.\-]/g, ''));
    return isFinite(n) ? n : 0;
  }

  function truthy(v) {
    return v === true || v === 'true' || v === 'Y' || num(v) > 0;
  }

  function comma(n) {
    return Math.round(n).toLocaleString('ko-KR');
  }

  /** 12345678 → "1,234만", 123456789 → "1.2억" */
  function krw(n) {
    n = Math.round(n);
    const abs = Math.abs(n);
    if (abs >= 1e8) return (n / 1e8).toFixed(abs >= 1e9 ? 0 : 1).replace(/\.0$/, '') + '억';
    if (abs >= 1e4) return comma(n / 1e4) + '만';
    return comma(n);
  }

  function median(arr) {
    if (!arr.length) return 0;
    const s = arr.slice().sort((a, b) => a - b);
    const m = Math.floor(s.length / 2);
    return s.length % 2 ? s[m] : (s[m - 1] + s[m]) / 2;
  }

  function getQueryFromUrl() {
    try {
      const url = new URL(location.href);
      // 네이버 지도: 검색어가 경로에 있음 (/p/search/{검색어} 또는 /search/{검색어})
      if (/(^|\.)map\.naver\.com$/.test(url.hostname)) {
        const m = url.pathname.match(/\/search\/([^/?]+)/);
        if (m) return decodeURIComponent(m[1]);
      }
      const p = url.searchParams;
      return p.get('query') || p.get('origQuery') || null;
    } catch (e) {
      return null;
    }
  }

  function sendBg(type, payload) {
    return new Promise((resolve) => {
      try {
        chrome.runtime.sendMessage({ type, payload }, (res) => {
          if (chrome.runtime.lastError) {
            resolve({ ok: false, message: chrome.runtime.lastError.message });
          } else {
            resolve(res || { ok: false, message: '응답 없음' });
          }
        });
      } catch (e) {
        resolve({ ok: false, message: String(e.message || e) });
      }
    });
  }

  // ------------------------------------------------------------------
  // 네이버 쇼핑 데이터 수집

  function normalizeItem(raw, fallbackRank) {
    if (!raw) return null;
    const item = raw.item || raw; // __NEXT_DATA__ 는 {item:{...}} 형태
    const mallCache = item.mallInfoCache || {}; // 몰(판매자) 정보 — goodService·talkAccountId·mallGrade 등
    const chnlCache = item.channelInfoCache || {};
    const price = num(item.price || item.lowPrice || item.mobileLowPrice);
    const title = stripTags(item.productTitle || item.productName || item.title || '');
    if (!title && !price) return null;
    const arrivalTag = (item.sellerDeliveryInfo && item.sellerDeliveryInfo.tagType) || '';
    const manuTag = String(item.manuTag || ''); // 콤마구분 태그(오늘출발·무료교환반품 등)
    // 실제 스토어 핸들은 mallPcUrl(예: smartstore.naver.com/spigen)에 있음.
    // mallProductUrl은 'main' 제네릭 경로라 직접 접근 시 로그인으로 리다이렉트됨 → 실제 핸들로 상품 URL 재구성.
    const pcBase = String(item.mallPcUrl || '').replace(/[?#].*$/, '').replace(/\/+$/, '');
    const storeM = pcBase.match(/(?:smartstore|brand)\.naver\.com\/([^/]+)$/);
    const storeId = storeM ? storeM[1] : '';
    const detailUrl = (storeId && item.mallProductId)
      ? pcBase + '/products/' + item.mallProductId
      : (item.mallProductUrl || item.crUrl || item.productUrl || '');
    // 톡톡 코드(talk.naver.com/ct/{code}) — channelInfoCache/mallInfoCache.talkAccountId(확정 경로)
    const talkId = chnlCache.talkAccountId || mallCache.talkAccountId || '';
    const itemType = String(item.type || raw.type || '').toLowerCase();
    return {
      id: item.id || item.nvMid || null,
      nvMid: item.nvMid || item.id || null, // DOM 공식배지 매핑용
      official: 0, // 공식 판매처 — 리스트 DOM(ico_public)에서 나중에 태깅
      rank: num(item.rank) || fallbackRank, // 네이버 원본 순위(정렬용)
      title,
      price,
      purchase6m: num(item.purchaseCnt),
      reviewCount: num(item.reviewCount),
      keepCount: num(item.keepCnt),
      mallName: item.mallName || (item.lowMallList && item.lowMallList[0] && item.lowMallList[0].name) || '',
      mallCount: num(item.mallCount),
      // 가격비교(카탈로그) 상품 — 판매처별 판매량은 카탈로그 페이지에서 보강
      isCatalog: num(item.productType) === 1 || num(item.mallCount) > 1,
      revenue6m: null, // 카탈로그 보강 시 판매처별 (구매건수×가격) 합
      sellerCount: 0,
      brand: item.brand || item.maker || '',
      manuTag, // 콤마구분 태그(상품키워드·오늘출발·무료교환반품 등)
      freeExchange: /무료교환|무료반품/.test(manuTag) ? 1 : 0, // 무료교환반품(manuTag)
      category: [item.category1Name, item.category2Name, item.category3Name, item.category4Name]
        .filter(Boolean)
        .join(' > '),
      openDate: item.openDate ? String(item.openDate).slice(0, 8) : '',
      // 광고 확정 필드(프로빙): adId(nad-…)·adType=1·adcrUrl / 슈퍼적립 광고(type=supersaving)
      isAd: Boolean(
        item.adId || item.adcrUrl || item.adType === 1 || item.adType === '1' ||
        raw.adId || raw.adcrUrl || itemType === 'supersaving'
      ),
      mallGrade: mallGradeOf(item),
      link: detailUrl, // 실제 스토어 상품 URL(로그인 리다이렉트되는 'main' 제네릭 대신)
      // ── 셀러력용 배송·npay (프로빙 확정 키) ──
      reviewCountSum: num(item.reviewCountSum || item.reviewCount), // 합산 리뷰(있으면 우선)
      deliveryFee: num(item.dlvryFee != null ? item.dlvryFee : item.lowDlvryFee != null ? item.lowDlvryFee : item.deliveryFeeContent),
      freeShip: (num(item.dlvryFee != null ? item.dlvryFee : item.lowDlvryFee) === 0 || String(item.deliveryFeeContent) === '0') ? 1 : 0,
      fastDelivery: (truthy(item.fastdlvry) || /TODAY|오늘|DISPATCH/i.test(String(arrivalTag)) || /오늘출발|오늘발송/.test(manuTag)) ? 1 : 0, // 오늘출발(manuTag 포함)
      arrivalTag,
      arrivalGuarantee: /ARRIVAL|GUARANTEE|DAWN|TOMORROW|도착|새벽|내일/i.test(String(arrivalTag)) ? 1 : 0, // N배송(도착보장)
      npay: (mallCache.naverPay || chnlCache.naverPay || item.nPayPcType || item.nPayMblType) ? 1 : 0, // nPay+ (mallInfoCache.naverPay)
      npayAccumRto: num(item.naverPayAccumRto), // 적립률(%)
      buyPoint: item.buyPointContent ? num(String(item.buyPointContent).split('^')[0]) : 0, // 적립 포인트(원)
      isBrandStore: (truthy(item.isBrandStore) || (item.brandNo != null && String(item.brandNo) !== '0' && String(item.brandNo) !== '')) ? 1 : 0, // 브랜드스토어(brandNo≠0)
      goodService: (mallCache.goodService || chnlCache.goodService) ? 1 : 0, // 굿서비스 인증(mallInfoCache)
      hasCoupon: truthy(item.hasCouponContent) ? 1 : 0, // 쿠폰(hasCouponContent)
      certBadge: 0, // 공식/인증 배지 — 검색 JSON에 필드 없음(추후 DOM 인식 필요)
      talktalk: talkId ? 1 : 0, // 톡톡 상담(channelInfoCache/mallInfoCache.talkAccountId)
      storeId, // 스토어 핸들(smartstore/brand)
      talkId, // 톡톡 코드(talk.naver.com/ct/{code}) — 없으면 storeId로 폴백
    };
  }

  /**
   * 판매처(몰) 등급 분류 — 가격비교/브랜드스토어/스마트스토어/일반/해외 + (있으면)프리미엄/빅파워/파워.
   * 검색결과 데이터로 확실한 것: 가격비교(productType/mallCount)·브랜드스토어/스마트스토어(상품 URL 도메인).
   * 프리미엄/빅파워/파워는 "판매자 등급"이라 검색결과 상품에 대개 없음 — 필드가 실려 있을 때만 잡힌다.
   */
  function mallGradeOf(item) {
    if (num(item.mallCount) > 1 || num(item.productType) === 1) return '가격비교';
    // 상품 URL 도메인으로 스토어 유형 확정(가장 신뢰도 높음)
    const url = String(
      item.mallProductUrl || item.mallPcUrl || item.crUrl || item.productUrl || item.lowMallPcUrl || ''
    ).toLowerCase();

    // 네이버 필드가 버전마다 달라 여러 키를 넓게 확인(판매자 등급 뱃지가 실려 있을 때만 유효)
    const flags = [
      item.mallGradeType, item.mallGrade, item.sellerGrade, item.storeGrade,
      item.mallProductType, item.channelGrade, item.storeGradeType, item.sellerBadge,
    ]
      .filter((v) => v != null)
      .map((v) => String(v).toUpperCase())
      .join(' ');

    // 브랜드스토어: brand.naver.com 도메인 또는 명시 플래그
    if (/brand\.naver\.com/.test(url) || truthy(item.brandStore) || truthy(item.isBrandStore) || /BRAND/.test(flags)) return '브랜드스토어';
    // 판매자 등급(응답에 있을 때만)
    if (/PREMIUM|프리미엄/.test(flags) || truthy(item.premium)) return '프리미엄';
    if (/BIGPOWER|BIG_POWER|빅파워/.test(flags)) return '빅파워';
    if (/\bPOWER\b|파워/.test(flags)) return '파워';
    if (truthy(item.overseasTp) || truthy(item.overseas) || /OVERSEA|해외/.test(flags)) return '해외직구';
    // 스마트스토어: smartstore.naver.com 도메인
    if (/smartstore\.naver\.com/.test(url) || truthy(item.npayMall) || truthy(item.smartStore)) return '스마트스토어';
    if (item.mallName || url) return '일반';
    return '기타';
  }

  function buildSearchParams(query, pageIndex) {
    const q = encodeURIComponent(query);
    return (
      'sort=rel&pagingIndex=' + pageIndex + '&pagingSize=80' +
      '&viewType=list&productSet=total' +
      '&query=' + q + '&origQuery=' + q + '&adQuery=' + q +
      '&iq=&eq=&xq='
    );
  }

  /**
   * JSON 트리에서 상품 배열을 재귀 탐색해 {products, total}로 정규화.
   * 네이버가 경로를 바꿔도 견디도록 고정 경로를 쓰지 않는다.
   */
  function extractProducts(root) {
    const candidates = [];

    (function walk(node, parent, depth, key) {
      if (depth > 9 || node == null || typeof node !== 'object') return;

      if (Array.isArray(node)) {
        if (node.length && node[0] && typeof node[0] === 'object') {
          const sample = node[0].item || node[0];
          const keys = sample ? Object.keys(sample) : [];
          const score = [
            'purchaseCnt', 'productTitle', 'productName', 'lowPrice',
            'reviewCount', 'mallName', 'rank',
          ].filter((k) => keys.indexOf(k) !== -1).length;
          if (score >= 2) {
            candidates.push({
              list: node,
              total: parent ? num(parent.total) : 0,
              wrapped: !!(node[0] && node[0].item), // 메인 리스트는 {item:{...}} 형태
              promo: /superSaving|adProduct|\bads?\b/i.test(String(key || '')), // 슈퍼적립·광고 배열
            });
            return;
          }
        }
        walk(node[0], null, depth + 1, key);
        return;
      }

      for (const k in node) walk(node[k], node, depth + 1, k);
    })(root, null, 0, '');

    if (!candidates.length) return null;

    // 메인 상품 리스트 우선: 슈퍼적립·광고 배열(promo)·flat 배열보다 {item:{...}}로 감싼 큰 배열을 선호
    const wrappedMain = candidates.filter((c) => !c.promo && c.wrapped && c.list.length >= 10);
    const nonPromo = candidates.filter((c) => !c.promo);
    const pool = wrappedMain.length ? wrappedMain : (nonPromo.length ? nonPromo : candidates);
    pool.sort((a, b) => b.list.length - a.list.length);
    const best = pool[0];
    const total = best.total || candidates.reduce((mx, c) => Math.max(mx, c.total || 0), 0);

    const products = [];
    for (const raw of best.list) {
      const it = normalizeItem(raw, products.length + 1);
      if (it) products.push(it);
    }
    return products.length ? { products, total } : null;
  }

  /** 트리에서 연관검색어 배열 탐색 — relat* 키 우선, 없으면 keyword/tag 키 */
  function findRelatedTags(root) {
    const attempt = (keyRe) => {
      let found = null;

      (function walk(node, depth) {
        if (found || depth > 9 || node == null || typeof node !== 'object') return;
        if (Array.isArray(node)) {
          walk(node[0], depth + 1);
          return;
        }
        for (const k in node) {
          const v = node[k];
          if (!found && keyRe.test(k) && Array.isArray(v) && v.length >= 3) {
            let vals = null;
            if (v.every((x) => typeof x === 'string')) {
              vals = v;
            } else if (v.every((x) => x && typeof x === 'object')) {
              vals = v
                .map((x) => x.keyword || x.relKeyword || x.title || x.text || x.tagName || '')
                .filter((s) => typeof s === 'string' && s);
            }
            if (vals) {
              vals = vals.filter((s) => s.length >= 1 && s.length <= 25);
              if (vals.length >= 3) {
                found = vals.slice(0, 20);
                return;
              }
            }
          }
          walk(v, depth + 1);
        }
      })(root, 0);

      return found;
    };

    return attempt(/relat/i) || attempt(/keyword|tag/i) || [];
  }

  /** 화면에 렌더된 연관검색어 영역에서 직접 추출 — 가장 신뢰도 높은 경로 */
  function scrapeRelatedTagsFromDom() {
    const collectLinks = (rootEl) => {
      const tags = [];
      const seen = new Set();
      rootEl.querySelectorAll('a').forEach((a) => {
        const href = a.getAttribute('href') || '';
        if (!/query=/.test(href)) return;
        const t = (a.textContent || '').trim().replace(/^#/, '');
        if (t && t.length <= 25 && !seen.has(t)) {
          seen.add(t);
          tags.push(t);
        }
      });
      return tags;
    };

    // 1) 알려진 클래스 패턴
    for (const sel of ['[class*="relatedTags"]', '[class*="relatedKeyword"]', '[class*="relation"]']) {
      let els;
      try {
        els = document.querySelectorAll(sel);
      } catch (e) {
        continue;
      }
      for (const el of els) {
        const tags = collectLinks(el);
        if (tags.length >= 2) return tags.slice(0, 20);
      }
    }

    // 2) '연관' 라벨 텍스트 기준 — 클래스명이 바뀌어도 동작
    const labels = [];
    document.querySelectorAll('span,em,strong,b,h2,h3,dt,div').forEach((el) => {
      if (labels.length >= 8) return;
      const t = (el.textContent || '').trim();
      if (el.childElementCount === 0 && t.length <= 8 && t.indexOf('연관') !== -1) labels.push(el);
    });
    for (const label of labels) {
      let node = label;
      for (let up = 0; up < 5 && node && node !== document.body; up++) {
        node = node.parentElement;
        if (!node) break;
        const tags = collectLinks(node).filter((t) => t.indexOf('연관') === -1);
        if (tags.length >= 3) return tags.slice(0, 20);
      }
    }
    return [];
  }

  /**
   * '함께 많이 찾는'(네이버 AI 제안 키워드) 블록 스크레이프 — 통합검색 전용.
   * href의 query= 파라미터에서 원문 키워드를 추출(<mark> 분절 영향 없음). 헤더 텍스트로 블록을 특정.
   */
  function scrapeAiKeywordsFromDom() {
    let root = null;
    const heads = document.querySelectorAll('span, strong, b, h2, h3');
    for (const el of heads) {
      if (el.childElementCount !== 0) continue;
      if ((el.textContent || '').trim() !== '함께 많이 찾는') continue;
      let node = el;
      for (let up = 0; up < 8 && node && node !== document.body; up++) {
        node = node.parentElement;
        if (node && node.querySelectorAll('a[href*="query="]').length >= 3) { root = node; break; }
      }
      if (root) break;
    }
    if (!root) return [];
    const out = [];
    const seen = new Set();
    root.querySelectorAll('a[href*="query="]').forEach((a) => {
      const href = a.getAttribute('href') || '';
      const m = href.match(/[?&]query=([^&]+)/);
      let kw = '';
      if (m) { try { kw = decodeURIComponent(m[1].replace(/\+/g, ' ')).trim(); } catch (e) { kw = ''; } }
      if (!kw) kw = (a.textContent || '').trim();
      if (kw && kw.length <= 30 && !seen.has(kw)) { seen.add(kw); out.push(kw); }
    });
    return out;
  }

  /** 연관 키워드 추출 실패 시 원인 파악용 진단 로그 */
  function logKeywordDiagnostics() {
    try {
      const el = document.getElementById('__NEXT_DATA__');
      const info = { nextData: Boolean(el), relClasses: [], anchors: [], jsonKeys: [] };

      const cls = new Set();
      document.querySelectorAll('[class*="relat"], [class*="Relat"]').forEach((e) => {
        String(e.className).split(/\s+/).forEach((c) => {
          if (/relat/i.test(c)) cls.add(c);
        });
      });
      info.relClasses = Array.from(cls).slice(0, 10);

      document.querySelectorAll('a[href*="query="]').forEach((a) => {
        if (info.anchors.length >= 15) return;
        const t = (a.textContent || '').trim();
        if (t && t.length <= 25) info.anchors.push(t);
      });

      if (el) {
        const json = JSON.parse(el.textContent);
        (function walk(node, path, depth) {
          if (depth > 6 || info.jsonKeys.length >= 20 || node == null || typeof node !== 'object') return;
          if (Array.isArray(node)) {
            walk(node[0], path + '[0]', depth + 1);
            return;
          }
          for (const k in node) {
            if (/relat|keyword|tag/i.test(k)) info.jsonKeys.push(path + '.' + k);
            walk(node[k], path + '.' + k, depth + 1);
          }
        })(json, '', 0);
      }

      console.warn('[RankFree] 연관 키워드 진단:', JSON.stringify(info));
    } catch (e) {
      console.warn('[RankFree] 진단 실패:', e);
    }
  }

  /** 전략 1 — 검색 API (페이지가 쓰는 nCaptcha 토큰이 잡혀 있으면 함께 전송) */
  async function fetchApiPage(query, pageIndex) {
    const headers = { accept: 'application/json, text/plain, */*', logic: 'PART' };
    if (state.ncaptchaToken) headers['x-wtm-ncaptcha-token'] = state.ncaptchaToken;

    const res = await fetch(location.origin + '/api/search/all?' + buildSearchParams(query, pageIndex), {
      credentials: 'include',
      headers,
    });
    if (!res.ok) throw new Error('API ' + res.status);
    if ((res.headers.get('content-type') || '').indexOf('json') === -1) {
      throw new Error('API 차단');
    }
    const json = await res.json();
    const out = extractProducts(json);
    if (!out) throw new Error('API 파싱 실패');
    out.relatedTags = findRelatedTags(json);
    return out;
  }

  /** HTML 문자열/문서에 임베드된 상품 JSON을 견고하게 추출(__NEXT_DATA__·__PRELOADED_STATE__·스크립트 스캔) */
  function extractEmbeddedProducts(html) {
    const tryJson = (str) => {
      try {
        const json = JSON.parse(str);
        const out = extractProducts(json);
        if (out && out.products.length) {
          out.relatedTags = findRelatedTags(json);
          return out;
        }
      } catch (e) { /* 다음 후보 */ }
      return null;
    };
    // 1) __NEXT_DATA__ (전체가 JSON)
    let m = html.match(/<script id="__NEXT_DATA__"[^>]*>([\s\S]*?)<\/script>/);
    if (m) { const r = tryJson(m[1]); if (r) return r; }
    // 2) window.__PRELOADED_STATE__ / __APOLLO_STATE__ = {...}
    m = html.match(/__(?:PRELOADED_STATE|APOLLO_STATE)__\s*=\s*({[\s\S]*?})\s*;?\s*<\/script>/);
    if (m) { const r = tryJson(m[1]); if (r) return r; }
    // 3) 상품 필드를 담은 아무 <script> 블록 스캔
    const re = /<script[^>]*>([\s\S]*?)<\/script>/g;
    let sc;
    while ((sc = re.exec(html))) {
      const txt = sc[1];
      if (txt.length < 200 || (txt.indexOf('productTitle') === -1 && txt.indexOf('talkAccountId') === -1)) continue;
      const jm = txt.match(/({[\s\S]*})/);
      if (jm) { const r = tryJson(jm[1]); if (r) return r; }
    }
    return null;
  }

  /** 전략 2 — 검색 페이지 HTML을 같은 오리진으로 GET(text/html) → 임베드 JSON 추출(API 아님) */
  async function fetchHtmlPage(query, pageIndex) {
    const res = await fetch(location.origin + '/search/all?' + buildSearchParams(query, pageIndex), {
      credentials: 'include',
      headers: { accept: 'text/html,application/xhtml+xml,*/*;q=0.8' },
    });
    if (!res.ok) throw new Error('HTML ' + res.status);
    const html = await res.text();
    const out = extractEmbeddedProducts(html);
    if (!out) throw new Error('HTML에 상품 데이터 없음');
    return out;
  }

  /** 전략 1 — 현재 로딩된 문서의 임베드 JSON을 바로 파싱(요청 없음) */
  function domNextData() {
    try {
      const el = document.getElementById('__NEXT_DATA__');
      if (el) {
        const out = extractEmbeddedProducts('<script id="__NEXT_DATA__">' + el.textContent + '</script>');
        if (out) return out;
      }
      // __NEXT_DATA__가 없거나 파싱 실패 → 문서 전체 HTML에서 스캔
      return extractEmbeddedProducts(document.documentElement.outerHTML);
    } catch (e) {
      return null;
    }
  }

  /** 가격비교(카탈로그) 페이지에서 판매처별 구매건수 추출 — 스마트스토어 등 노출 판매처 합산 */
  async function fetchCatalogSales(catalogId) {
    const res = await fetch(location.origin + '/catalog/' + encodeURIComponent(catalogId), {
      credentials: 'include',
      headers: { accept: 'text/html,application/xhtml+xml,*/*;q=0.8' },
    });
    if (!res.ok) throw new Error('catalog ' + res.status);
    const html = await res.text();
    const m = html.match(/<script id="__NEXT_DATA__"[^>]*>([\s\S]*?)<\/script>/);
    if (!m) throw new Error('catalog 데이터 없음');
    const root = JSON.parse(m[1]);

    let purchase = 0;
    let revenue = 0;
    let sellers = 0;
    const seen = new Set();

    (function walk(node, depth) {
      if (depth > 10 || node == null || typeof node !== 'object') return;
      if (Array.isArray(node)) {
        for (const it of node) walk(it, depth + 1);
        return;
      }
      const pc = num(node.purchaseCnt != null ? node.purchaseCnt : node.purchaseCount);
      const price = num(node.price || node.lowPrice || node.mobileLowPrice);
      if (pc > 0 && price > 0) {
        const key = pc + '|' + price + '|' + (node.mallName || node.mallNo || node.id || '');
        if (!seen.has(key)) {
          seen.add(key);
          purchase += pc;
          revenue += pc * price;
          sellers++;
        }
        return; // 판매처 노드 내부는 더 보지 않음
      }
      for (const k in node) walk(node[k], depth + 1);
    })(root, 0);

    return { purchase, revenue, sellers };
  }

  async function collectProducts(query, pages) {
    const seen = new Set();
    const all = [];
    let total = 0;
    let relatedTags = [];
    const errors = [];

    // 토큰 없으면 잠깐 대기(페이지 자체 검색 호출로 캡처될 시간). 없어도 HTML/SSR 폴백으로 진행.
    if (!state.ncaptchaToken) await waitForToken(4000);

    for (let i = 1; i <= pages; i++) {
      let page = null;

      state.progress = '상품 수집 중… (' + i + '/' + pages + '페이지)';
      render();

      try {
        page = await fetchApiPage(query, i);
      } catch (e) {
        errors.push(e.message);
        try {
          page = await fetchHtmlPage(query, i);
          if (!page.products.length) {
            errors.push('HTML 상품 없음');
            page = null;
          }
        } catch (e2) {
          errors.push(e2.message);
        }
      }
      if (!page && i === 1 && !state.urlChangedSinceLoad) {
        page = domNextData();
        if (page && !page.products.length) page = null;
        if (page) errors.push('SSR 폴백 사용');
      }
      if (!page) break;

      total = page.total || total;
      if (!relatedTags.length && page.relatedTags && page.relatedTags.length) {
        relatedTags = page.relatedTags;
      }
      for (const p of page.products) {
        const key = p.id || p.title + '|' + p.mallName;
        if (seen.has(key)) continue;
        seen.add(key);
        p.rank = all.length + 1;
        all.push(p);
      }
      console.debug('[RankFree] page', i, '수집', page.products.length, '누적', all.length);

      if (page.products.length < 60) break; // 마지막 페이지로 판단
      if (i < pages) await new Promise((r) => setTimeout(r, 600)); // 과호출 방지
    }

    if (!all.length) {
      console.warn('[RankFree] 수집 실패:', errors.join(' / '));
      throw new Error(
        '네이버 쇼핑 데이터를 불러오지 못했습니다' +
        (errors.length ? ' — ' + errors.slice(0, 4).join(', ') : '') +
        '. 페이지를 새로고침한 뒤 다시 시도해 주세요.'
      );
    }
    return { products: all, total, relatedTags };
  }

  // ------------------------------------------------------------------
  // 시장 분석 계산
  // ------------------------------------------------------------------
  /** 상품 6개월 매출 — 가격비교 보강값(revenue6m)이 있으면 우선 */
  function itemRevenue6m(p) {
    return p.revenue6m != null ? p.revenue6m : p.purchase6m * p.price;
  }

  function computeMarket(products, includeAds) {
    const items = includeAds ? products : products.filter((p) => !p.isAd);
    const withSales = items.filter((p) => p.price > 0 || itemRevenue6m(p) > 0);

    const sales6m = withSales.reduce((a, p) => a + p.purchase6m, 0);
    const revenue6m = withSales.reduce((a, p) => a + itemRevenue6m(p), 0);
    const prices = withSales.map((p) => p.price).filter((v) => v > 0);
    const avgPrice = prices.length ? prices.reduce((a, b) => a + b, 0) / prices.length : 0;

    const byRevenue = withSales.slice().sort((a, b) => itemRevenue6m(b) - itemRevenue6m(a));
    const top10Revenue = byRevenue.slice(0, 10).reduce((a, p) => a + itemRevenue6m(p), 0);

    // 몰 등급별 상품 수 카운팅 (프리미엄/빅파워/파워/브랜드스토어/스마트스토어/가격비교/일반 …)
    const GRADE_ORDER = ['프리미엄', '빅파워', '파워', '브랜드스토어', '스마트스토어', '일반', '가격비교', '해외직구', '기타'];
    const gradeMap = {};
    for (const p of items) {
      const g = p.mallGrade || '기타';
      gradeMap[g] = (gradeMap[g] || 0) + 1;
    }
    const mallGrades = GRADE_ORDER.filter((g) => gradeMap[g]).map((g) => [g, gradeMap[g]]);

    // 1등(매출 최상위) 상품 카테고리 + 카테고리 분포
    const catMap = {};
    for (const p of items) {
      if (!p.category) continue;
      catMap[p.category] = (catMap[p.category] || 0) + 1;
    }
    const topCategories = Object.entries(catMap).sort((a, b) => b[1] - a[1]).slice(0, 5);

    return {
      itemCount: items.length,
      adCount: products.length - products.filter((p) => !p.isAd).length,
      sales6m,
      revenue6m,
      monthlySales: sales6m / 6,
      monthlyRevenue: revenue6m / 6,
      avgPrice,
      medianPrice: median(prices),
      top10Share: revenue6m > 0 ? (top10Revenue / revenue6m) * 100 : 0,
      topProducts: byRevenue.slice(0, 10),
      topProductCategory: byRevenue.length ? byRevenue[0].category : '',
      mallGrades,
      topCategories,
    };
  }

  // ------------------------------------------------------------------
  // 렌더링
  // ------------------------------------------------------------------
  const PANEL_ID = 'rankfree-panel';
  const FAB_ID = 'rankfree-fab';

  function h(html) {
    const t = document.createElement('template');
    t.innerHTML = html.trim();
    return t.content.firstElementChild;
  }

  function panelEl() {
    return document.getElementById(PANEL_ID);
  }

  function render() {
    const panel = panelEl();
    if (!panel) return;
    const body = panel.querySelector('.rf-body');
    const foot = panel.querySelector('.rf-foot');

    panel.querySelector('.rf-query').textContent = state.query || '';

    if (!state.loggedIn) {
      panel.querySelector('.rf-tabs').style.display = 'none';
      body.innerHTML = loginHtml();
      foot.innerHTML = '';
      bindLogin(body);
      return;
    }

    if (state.showSettings) {
      panel.querySelector('.rf-tabs').style.display = 'none';
      body.innerHTML = settingsHtml();
      bindSettings(body);
      foot.innerHTML = '';
      return;
    }

    panel.querySelector('.rf-tabs').style.display = '';
    renderTabs(panel);

    if (state.tab === 'keyword') {
      // 키워드 분석 탭 — 진입 시 검색어 키워드 자동 조회(검색량·성별/연령·트렌드·연관키워드)
      if (state.keywordData === undefined && !state.keywordLoading && (state.query || getQueryFromUrl())) {
        refreshKeyword(state.query || getQueryFromUrl());
      }
      body.innerHTML = keywordTabHtml();
      bindKeyword(body);
    } else if (state.tab === 'pmarket') {
      body.innerHTML = placeMarketHtml();
      bindPlaceMarket(body);
    } else if (state.tab === 'pstore') {
      body.innerHTML = placeStoreHtml();
      bindPlaceStore(body);
    } else if (state.tab === 'summary') {
      body.innerHTML = summaryHtml();
    } else if (state.tab === 'market') {
      // 셀러 탭 공유 수집분으로 결과를 볼 때도 키워드 분석(검색량 등)을 조회 — 없으면 1회 조회
      if (state.keywordData === undefined && !state.keywordLoading && getQueryFromUrl()) {
        refreshKeyword(getQueryFromUrl());
      }
      body.innerHTML = marketHtml();
      bindMarket(body);
    } else if (state.tab === 'seller') {
      // 셀러력 탭 진입 시 검색 상위 상품 자동 수집(완료 시 재렌더)
      if (!state.products.length && !state.seller.collecting && !state.seller.result) {
        ensureProducts(false);
      }
      body.innerHTML = sellerHtml();
      bindSeller(body);
    } else if (state.tab === 'product') {
      // 상품 분석 탭 — 리스트 없음. 분석 결과 있으면 리포트, 없으면 빈 상태(분석은 '상품 목록' 탭에서만).
      body.innerHTML = productHtml();
      bindProduct(body);
    } else if (state.tab === 'history') {
      body.innerHTML = historyHtml();
      bindHistory(body);
    } else {
      body.innerHTML =
        '<div class="rf-empty"><div class="rf-empty-badge">준비 중</div>' +
        '<p>이 기능은 곧 제공될 예정입니다.<br>지금은 <b>시장 분석</b>을 이용해 주세요.</p></div>';
    }

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

  // 2단 탭: 상위(통합분석·플레이스·쇼핑·요약) + 세부(상위에 subs가 있을 때만)
  const TOP_TABS = [
    { key: 'unified', label: '통합분석', tab: 'keyword' }, // 하위 없음 — 바로 키워드 분석
    { key: 'place', label: '플레이스', subs: [
      { key: 'pmarket', label: '시장분석' },
      { key: 'pstore', label: '매장분석' },
    ] },
    { key: 'shop', label: '쇼핑', subs: [
      { key: 'seller', label: '상품 목록' },
      { key: 'market', label: '시장 분석' },
      { key: 'product', label: '상품 분석' },
    ] },
    { key: 'summary', label: '요약', tab: 'summary' }, // 하위 없음
  ];

  /** 상위 탭의 기본 세부 탭 */
  function topDefaultTab(topKey) {
    const t = TOP_TABS.find((x) => x.key === topKey);
    if (!t) return 'keyword';
    if (t.tab) return t.tab;
    return (t.subs && t.subs[0]) ? t.subs[0].key : 'keyword';
  }

  function renderTabs(panel) {
    const wrap = panel.querySelector('.rf-tabs');
    const top = state.topTab || 'shop';
    let html = '<div class="rf-toptabs">';
    TOP_TABS.forEach((t) => {
      html += '<button type="button" class="rf-toptab' + (top === t.key ? ' is-active' : '') +
        '" data-top="' + t.key + '">' + esc(t.label) + '</button>';
    });
    html += '</div>';
    const cur = TOP_TABS.find((x) => x.key === top);
    if (cur && cur.subs) {
      html += '<div class="rf-subtabs">';
      cur.subs.forEach((s) => {
        html += '<button type="button" class="rf-subtab' + (state.tab === s.key ? ' is-active' : '') +
          '" data-sub="' + s.key + '">' + esc(s.label) + '</button>';
      });
      html += '</div>';
    }
    wrap.innerHTML = html;
    // 상위 탭 — 클릭/마우스오버(쇼핑처럼 올리면 동작) 모두 전환
    wrap.querySelectorAll('.rf-toptab').forEach((btn) => {
      const go = () => {
        const k = btn.dataset.top;
        if (state.topTab === k) return;
        state.topTab = k;
        state.tab = topDefaultTab(k);
        if (state.tab === 'history') loadHistory(true);
        render();
      };
      btn.addEventListener('click', go);
      btn.addEventListener('mouseenter', go);
    });
    // 세부 탭
    wrap.querySelectorAll('.rf-subtab').forEach((btn) => {
      btn.addEventListener('click', () => {
        state.tab = btn.dataset.sub;
        if (state.tab === 'history') loadHistory(true);
        render();
      });
    });
  }

  // ==================================================================
  // 플레이스 탭 — 시장분석(경쟁 업체 리스트) + 매장분석(1개 상세)
  // ==================================================================
  /** 지도 상위 URL엔 카테고리가 없어 검색어로 추정(맛집·카페 등 → restaurant) */
  function placeCatFromUrl() {
    const q = state.query || getQueryFromUrl() || '';
    if (/맛집|식당|밥집|카페|술집|고기|초밥|스시|파스타|국밥|치킨|피자|디저트|브런치|베이커리|호프|포차|곱창|막창|삼겹|해장/.test(q)) return 'restaurant';
    return 'place';
  }

  function loadPlaceSerp() {
    const q = state.query || getQueryFromUrl();
    if (!q || state.place.loading) return;
    const cat = placeCatFromUrl();
    state.place.loading = true;
    state.place.error = null;
    sendBg('placeSerp', { keyword: q, cat, top: 30 }).then((res) => {
      state.place.loading = false;
      if (!res || !res.ok) {
        state.place.items = null;
        state.place.error = (res && res.loggedIn === false) ? '로그인이 필요합니다.' : ((res && res.message) || '플레이스 순위를 조회하지 못했습니다.');
      } else {
        state.place.items = res.items || [];
        state.place.total = res.total || 0;
        state.place.cat = cat;
      }
      render();
    });
  }

  function placeMarketHtml() {
    const q = state.query || getQueryFromUrl() || '';
    if (!q) return '<div class="rf-empty"><p>검색어를 찾을 수 없습니다.<br>네이버 지도·통합검색에서 이용하세요.</p></div>';
    if (state.place.items === null && !state.place.loading && !state.place.error) loadPlaceSerp();
    if (state.place.loading) return '<div class="rf-loading"><div class="rf-spinner"></div>플레이스 순위·지수 분석 중…</div>';
    if (state.place.error) return '<div class="rf-card rf-empty-card"><div class="rf-error" style="margin-bottom:12px;">' + esc(state.place.error) + '</div><button type="button" class="rf-btn-lg-ghost" data-act="pm-retry">다시 시도</button></div>';
    const items = state.place.items || [];
    if (!items.length) return '<div class="rf-empty"><p>플레이스 순위 데이터가 없습니다.</p></div>';
    const rows = items.map((it) =>
      '<tr>' +
      '<td class="rf-td-rank">' + it.rank + '</td>' +
      '<td class="rf-td-title"><a href="#" data-pm-store="' + esc(String(it.place_id)) + '" title="상세 분석">' + esc(it.name) + '</a></td>' +
      '<td class="rf-td-num">' + comma(it.visitor_cnt || 0) + '</td>' +
      '<td class="rf-td-num">' + comma(it.blog_cnt || 0) + '</td>' +
      '<td class="rf-td-num">' + comma(it.save_cnt || 0) + '</td>' +
      '<td class="rf-td-num rf-td-strong">' + Math.round(it.n1 || 0) + '</td>' +
      '<td class="rf-td-num rf-td-strong">' + Math.round(it.n2 || 0) + '</td>' +
      '<td class="rf-td-num rf-td-strong">' + Math.round(it.n3 || 0) + '</td>' +
      '</tr>'
    ).join('');
    return '<div class="rf-kw-head"><span>‘<b>' + esc(q) + '</b>’ 플레이스 시장분석</span>' +
      '<button type="button" class="rf-btn-ghost" data-act="pm-retry" title="다시 분석">↻</button></div>' +
      '<div class="rf-card"><div class="rf-card-title">경쟁 업체 순위·지수 <span class="rf-chip">상위 ' + items.length + '개 · 광고 제외</span></div>' +
      '<div class="rf-table-wrap"><table class="rf-table"><thead><tr><th>#</th><th>업체</th><th>영수증</th><th>블로그</th><th>저장</th><th>N1</th><th>N2</th><th>N3</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
      '<p class="rf-note">업체명을 누르면 매장 상세 분석. N1 유사도·N2 관련성·N3 랭킹은 관측 신호 기반 <b>자체 추정치</b>(네이버 공식 점수 아님).</p></div>';
  }

  function bindPlaceMarket(body) {
    const retry = body.querySelector('[data-act="pm-retry"]');
    if (retry) retry.addEventListener('click', () => { state.place.items = null; state.place.error = null; loadPlaceSerp(); });
    body.querySelectorAll('[data-pm-store]').forEach((a) => a.addEventListener('click', (e) => {
      e.preventDefault();
      const pid = a.dataset.pmStore;
      state.place.selected = (state.place.items || []).find((x) => String(x.place_id) === pid) || null;
      state.place.detail = undefined;
      state.place.detailLoading = false;
      state.tab = 'pstore';
      render();
    }));
  }

  function placeStoreHtml() {
    const sel = state.place.selected;
    if (!sel) return '<div class="rf-card rf-empty-card"><p class="rf-empty-d"><b>시장분석</b>에서 업체를 선택하면 상세 분석이 표시됩니다.</p><button type="button" class="rf-btn-lg-ghost" data-act="ps-back">시장분석으로</button></div>';
    const head = '<div class="rf-sp-refresh-bar rf-sticky"><div class="rf-sp-bar-left"><button type="button" class="rf-sp-refresh" data-act="ps-back">← 시장분석</button></div></div>' +
      '<div class="rf-prod-name">' + esc(sel.name) + '</div>';
    const brief = '<div class="rf-card"><div class="rf-card-title">지수 요약 <span class="rf-chip">순위 ' + sel.rank + '위</span></div>' +
      '<div class="rf-stats">' +
      statTile('N1 유사도', Math.round(sel.n1 || 0)) +
      statTile('N2 관련성', Math.round(sel.n2 || 0)) +
      statTile('N3 랭킹', Math.round(sel.n3 || 0)) +
      statTile('영수증리뷰', comma(sel.visitor_cnt || 0)) +
      statTile('블로그리뷰', comma(sel.blog_cnt || 0)) +
      statTile('저장수', comma(sel.save_cnt || 0)) +
      '</div></div>';
    const dimCard = sel.d ? placeDimCard(sel.d) : '';
    return head + brief + dimCard +
      '<p class="rf-note">D7 정보충실·D9 최근활동·D10 리뷰어영향력은 상세 수집이 필요해 요약에선 생략(–)됩니다. 관측 신호 기반 <b>자체 추정치</b>(네이버 공식 점수 아님).</p>';
  }

  /** D1~D10 세부 지표 막대 */
  function placeDimCard(d) {
    const dim = (label, v) => '<div class="rf-grade-row"><span class="rf-grade-name">' + label + '</span>' +
      '<span class="rf-grade-num">' + (v == null ? '–' : Math.round(v)) + '</span>' +
      '<span class="rf-grade-track"><span class="rf-grade-bar" style="width:' + (v == null ? 0 : Math.max(2, Math.min(100, Math.round(v)))) + '%"></span></span></div>';
    return '<div class="rf-card"><div class="rf-card-title">세부 지표 (D1~D10)</div>' +
      dim('D1 영수증리뷰', d.d1) + dim('D2 블로그리뷰', d.d2) + dim('D3 예약리뷰', d.d3) +
      dim('D4 평점', d.d4) + dim('D5 저장수', d.d5) + dim('D6 사진수', d.d6) +
      dim('D7 정보충실', d.d7) + dim('D9 최근활동', d.d9) + dim('D10 리뷰어영향력', d.d10) +
      '</div>';
  }

  function bindPlaceStore(body) {
    const back = body.querySelector('[data-act="ps-back"]');
    if (back) back.addEventListener('click', () => { state.tab = 'pmarket'; render(); });
  }

  function summaryHtml() {
    return '<div class="rf-empty"><div class="rf-empty-badge">준비 중</div><p>플레이스 관련 요약은 곧 제공됩니다.</p></div>';
  }

  // ==================================================================
  // 셀러력 탭 — 검색 리스트 상품으로 5축 비교(서버 scoreSearch)
  // ==================================================================
  function sellerHtml() {
    const s = state.seller;
    if (s.loading) {
      return '<div class="rf-loading"><div class="rf-spinner"></div>‘' + esc(s.targetTitle) + '’ 셀러력 계산 중…</div>';
    }
    if (s.result) {
      return '<div class="rf-sp-refresh-bar">' +
        '<div class="rf-sp-bar-left">' +
          '<button type="button" class="rf-sp-refresh" data-act="sp-web">랭크프리에서 보기</button>' +
          (s.shareToken ? '<button type="button" class="rf-sp-refresh" data-act="sp-share">🔗 공유</button>' : '') +
        '</div>' +
        '<button type="button" class="rf-sp-refresh" data-act="sp-back">← 상품 목록</button></div>' + sellerReportHtml(s.result);
    }
    if (s.collecting) {
      return '<div class="rf-loading"><div class="rf-spinner"></div>검색 상위 80개 상품 수집 중…</div>';
    }
    if (!state.products.length) {
      return '<div class="rf-empty"><p>' + (s.error ? esc(s.error) + '<br>' : '') + '상품을 수집하지 못했습니다.</p>' +
        '<button type="button" class="rf-btn-primary" data-act="sp-collect">상품 수집</button></div>';
    }
    return (s.error ? '<div class="rf-error" style="margin-bottom:10px;">' + esc(s.error) + '</div>' : '') + sellerListHtml();
  }

  /** 리스트 표시·인덱스 기준 — 광고 포함 전체(네이버 리스트 순서: 광고 상단 → 조직 rank순) */
  function sellerProducts() {
    return state.products;
  }

  /** 셀러력·상품분석은 네이버 스마트스토어/브랜드스토어 상세 상품만 가능(옥션·지마켓·자사몰·가격비교 불가) */
  function isSmartstoreProduct(p) {
    return /(?:smartstore|brand)\.naver\.com\/[^/]+\/products\/\d+/.test(String((p && p.link) || ''));
  }

  function sellerListHtml() {
    const rows = sellerProducts().slice(0, state.seller.count || 80).map((p, i) => {
      const ss = isSmartstoreProduct(p);
      const actBtns = ss
        ? '<button type="button" class="rf-sp-act seller" data-sp-idx="' + i + '">🎯 셀러력</button>' +
          '<button type="button" class="rf-sp-act review" data-rv-idx="' + i + '">📝 상품분석</button>'
        : '<span class="rf-sp-act-na" title="네이버 스마트스토어 상품만 분석 가능합니다(옥션·지마켓·자사몰 불가)">스토어 외 상품</span>';
      return '<div class="rf-sp-item' + (p.isAd ? ' is-ad' : '') + (ss ? '' : ' is-na') + '" data-idx="' + i + '">' +
        (p.isAd ? '<span class="rk ad">광고</span>' : '<span class="rk">' + (p.rank || i + 1) + '</span>') + // 광고=표기, 조직=실제 rank
        '<span class="ti">' + esc((p.title || '').slice(0, 46)) +
        '<span class="mt">' + esc(p.mallName || '') + ' · 리뷰 ' + comma(p.reviewCountSum || p.reviewCount) + ' · 구매 ' + (p.purchase6m > 0 ? comma(p.purchase6m) : '비공개') + ' · 찜 ' + comma(p.keepCount) + '</span></span>' +
        '<span class="acts">' + actBtns + '</span></div>';
    }).join('');
    const cnt = state.seller.count || 80;
    const sel = '<select class="rf-sp-count" data-ctl="sp-count">' +
      [40, 80, 160, 240].map((c) => '<option value="' + c + '"' + (c === cnt ? ' selected' : '') + '>상위 ' + c + '개</option>').join('') +
      '</select>';
    return '<div class="rf-sp-list-head">상품에 마우스를 올려 <b>셀러력·상품분석</b> 선택 ' +
      sel + '<button type="button" class="rf-sp-recollect" data-act="sp-collect" title="다시 수집">↻</button></div>' +
      '<div class="rf-sp-list">' + rows + '</div>';
  }

  function bindSeller(body) {
    body.querySelectorAll('[data-sp-idx]').forEach((b) =>
      b.addEventListener('click', (e) => { e.stopPropagation(); runSellerPower(num(b.dataset.spIdx)); })
    );
    body.querySelectorAll('[data-rv-idx]').forEach((b) =>
      b.addEventListener('click', (e) => { e.stopPropagation(); runProductAnalysis(num(b.dataset.rvIdx)); })
    );
    const collect = body.querySelector('[data-act="sp-collect"]');
    if (collect) collect.addEventListener('click', () => { state.products = []; state.seller.error = null; ensureProducts(true); });
    const cntSel = body.querySelector('[data-ctl="sp-count"]');
    if (cntSel) cntSel.addEventListener('change', () => { state.seller.count = num(cntSel.value) || 80; state.products = []; ensureProducts(false); }); // 캐시 우선(개수만큼 확보돼 있으면 재사용)
    const back = body.querySelector('[data-act="sp-back"]');
    if (back) back.addEventListener('click', () => { state.seller.result = null; render(); });
    const web = body.querySelector('[data-act="sp-web"]');
    if (web) web.addEventListener('click', () => {
      const base = String((state.seller.webBase || state.apiBase || 'https://rankfree.kr')).replace(/\/+$/, '');
      try { window.open(base + '/', '_blank'); } catch (e) { /* noop */ }
    });
    const share = body.querySelector('[data-act="sp-share"]');
    if (share) share.addEventListener('click', () => {
      const base = String((state.seller.webBase || state.apiBase || 'https://rankfree.kr')).replace(/\/+$/, '');
      if (state.seller.shareToken) {
        try { window.open(base + '/sp/' + state.seller.shareToken, '_blank'); } catch (e) { /* noop */ }
      }
    });
    if (state.seller.result) requestAnimationFrame(sellerDrawRadar);
  }

  /** 셀러력 탭 진입/요청 시 검색 상위 80건 자동 수집(1페이지). */
  async function ensureProducts(force) {
    if (state.seller.collecting) return;
    if (state.products.length && !force) return;
    state.seller.collecting = true;
    state.seller.error = null;
    render();
    let collected = false;
    try {
      const q = state.query || getQueryFromUrl();
      const count = state.seller.count || 80;
      const page = await collectCached(q, count, force); // 같은 키워드 12h 캐시(force면 새수집)
      state.products = ((page && page.products) || []); // 전체 보관(광고 제외·개수 슬라이스는 표시 단계에서)
      state.totalCount = (page && page.total) || state.totalCount;
      // 빈 배열([])은 truthy라 기존 연관키워드를 덮어쓰지 않도록 length 확인
      if (page && page.relatedTags && page.relatedTags.length) state.relatedTags = page.relatedTags;
      state.query = q;
      collected = state.products.length > 0;
    } catch (e) {
      state.seller.error = '상품 수집 실패: ' + String((e && e.message) || e);
    }
    state.seller.collecting = false;
    render();
    // 상품 목록 수집 = 시장 분석도 자동 계산·저장(통합검색 퍼널: 검색→키워드 분석→상품목록→시장 분석).
    // 목록 표시를 막지 않도록 비동기. force(다시 수집)면 같은 키워드도 재저장, 아니면 키워드당 1회.
    if (collected && state.loggedIn && state.query && (force || state.marketSavedQuery !== state.query)) {
      state.marketSavedQuery = state.query;
      autoSaveMarket(state.query).catch(() => { state.marketSavedQuery = null; });
    }
  }

  /** 상품 목록 수집분으로 시장 분석을 계산·저장(통합검색 퍼널). analyze()와 동일하게 키워드 데이터 보강 + 카탈로그 판매처 확인 후 저장. */
  async function autoSaveMarket(query) {
    // 월검색량·경쟁지수 저장을 위해 키워드 분석 데이터가 없으면 1회 조회
    if (state.keywordData === undefined && !state.keywordLoading) {
      try {
        const res = await sendBg('keywordAnalysis', { keyword: query });
        state.keywordData = res && res.ok && res.data ? res.data : null;
      } catch (e) { state.keywordData = null; }
    }
    // 가격비교(카탈로그) 판매처 구매건수 보강 — 매출 추정 정확도(analyze()와 동일 로직)
    const catalogs = state.products
      .filter((p) => p.isCatalog && !p.isAd && p.id && p.purchase6m === 0)
      .sort((a, b) => b.reviewCount - a.reviewCount)
      .slice(0, 15);
    if (catalogs.length) {
      const queue = catalogs.slice();
      const worker = async () => {
        while (queue.length) {
          const c = queue.shift();
          try {
            const sale = await fetchCatalogSales(c.id);
            if (sale.purchase > 0) { c.purchase6m = sale.purchase; c.revenue6m = sale.revenue; c.sellerCount = sale.sellers; }
          } catch (e2) { /* 개별 카탈로그 실패는 무시 */ }
        }
      };
      await Promise.all(Array.from({ length: Math.min(4, catalogs.length) }, worker));
    }
    await saveAnalysis();
    if (state.tab === 'seller' || state.tab === 'market') render(); // 저장 배지 반영
  }

  /** 스마트스토어/브랜드스토어 상세 상품 URL인지 */
  function isProductDetailUrl(link) {
    return /(smartstore|brand)\.naver\.com\/[^/]+\/products\/\d+/.test(String(link || ''));
  }

  /** 상품 분석 — 별도 탭 없이 상품분석 탭에서 in-panel로. 이미 분석한 상품이면 저장본을 그대로 불러온다(재수집 X). */
  async function runProductAnalysis(idx) {
    const p = sellerProducts()[idx];
    if (!p) return;
    const link = String(p.link || '');
    if (!isProductDetailUrl(link)) {
      state.tab = 'product';
      state.product = { loading: false, error: '스마트스토어/브랜드스토어 상세 상품만 분석할 수 있습니다(가격비교·광고 리다이렉트 제외).', html: '', targetTitle: p.title || '', targetLink: link };
      render();
      return;
    }
    return openSavedOrAnalyze(link, p.title || '');
  }

  /** 이미 분석한 상품(내역에 origin_product_no 일치)이면 저장본을, 아니면 새로 수집. */
  async function openSavedOrAnalyze(link, title) {
    const m = String(link).match(/\/products\/(\d+)/);
    const pid = m ? m[1] : null;
    if (pid) {
      if (!Array.isArray(state.history)) { try { await loadHistory(); } catch (e) { /* noop */ } }
      const saved = (state.history || []).find((hh) => hh.type === 'product' && String(hh.origin_product_no) === pid);
      if (saved) { state.tab = 'product'; return openSavedProduct(saved.id); } // 재수집 없이 저장본
    }
    return runProductAnalysisByLink(String(link).split('#')[0], title);
  }

  async function runProductAnalysisByLink(link, title) {
    state.tab = 'product';
    state.product = { loading: true, error: null, html: '', targetTitle: title, targetLink: link };
    render();
    const r = await sendBg('reviewCollectDetail', { url: link, title });
    if (r && r.ok && r.html) {
      state.product = { loading: false, error: null, html: r.html, targetTitle: r.name || title, targetLink: link, id: r.id || null, shareToken: r.share_token || '' };
    } else {
      state.product = { loading: false, error: (r && r.message) || (r && r.loggedIn === false ? '로그인이 필요합니다.' : '상품 분석에 실패했습니다.'), html: '', targetTitle: title, targetLink: link };
    }
    render();
  }

  /** 상품 분석 탭 본문 — 결과(리포트 HTML) 있으면 리포트, 없으면 빈 상태(분석은 '상품 목록' 탭에서만). */
  function productHtml() {
    const p = state.product;
    if (p.loading) {
      const pct = (typeof p.progressPct === 'number') ? p.progressPct : 0;
      return '<div class="rf-loading"><div class="rf-spinner"></div>‘' + esc((p.targetTitle || '').slice(0, 30)) + '’' +
        '<br><span class="rf-loading-prog">상품 분석 중 ' + pct + '%</span>' +
        '<br><span class="rf-loading-sub">백그라운드에서 분석 중입니다 — 다른 페이지로 이동해도 계속되고, 완료되면 <b>내역</b>에 저장됩니다.</span></div>';
    }
    if (p.html) {
      const web = webBase() + '/';
      const share = p.shareToken ? (webBase() + '/p/' + p.shareToken) : '';
      const link = p.targetLink ? p.targetLink.split('#')[0] : '';
      const name = esc(p.targetTitle || '상품');
      // 상단 바(← 상품 목록 라인)는 스크롤해도 상단 고정(rf-sticky). 상품 페이지 버튼 제거.
      return '<div class="rf-sp-refresh-bar rf-sticky"><div class="rf-sp-bar-left">' +
        '<button type="button" class="rf-sp-refresh" data-rf-web="' + esc(web) + '">랭크프리에서 보기</button>' +
        (share ? '<button type="button" class="rf-sp-refresh" data-rf-share="' + esc(share) + '">🔗 공유</button>' : '') +
        '</div><div class="rf-sp-bar-left">' +
        '<button type="button" class="rf-sp-refresh" data-act="manual-open">✎ 수동 분석</button>' +
        '<button type="button" class="rf-sp-refresh" data-act="go-list">← 상품 목록</button></div></div>' +
        // 리뷰 요약 위 상품명 — 누르면 상품 페이지로
        (link ? '<a class="rf-prod-name" href="' + esc(link) + '" target="_blank" rel="noopener">' + name + '</a>'
              : '<div class="rf-prod-name">' + name + '</div>') +
        p.html;
    }
    // 수동 URL 입력 — 상품 목록에 없어도 스마트스토어 상품 URL을 직접 넣어 분석
    const manual =
      '<div class="rf-manual"><input type="url" class="rf-input" data-ctl="manual-url" placeholder="스마트스토어 상품 URL 붙여넣기" value="' + esc(state.manualUrl || '') + '">' +
      '<button type="button" class="rf-btn-primary" data-act="manual-analyze">분석하기</button></div>' +
      '<p class="rf-note">스마트스토어/브랜드스토어 상세 상품만 분석할 수 있습니다.</p>';
    const goListBtn = '<button type="button" class="rf-btn-lg-ghost" data-act="go-list">상품 목록으로</button>';
    if (p.error) {
      // 분석 실패/타임아웃 — 카드로 에러와 재시도·수동 입력을 정돈
      return '<div class="rf-card rf-empty-card">' +
        '<div class="rf-error" style="margin-bottom:12px;">' + esc(p.error) + '</div>' +
        (p.targetLink ? '<button type="button" class="rf-btn-lg-ghost" data-act="pa-retry" style="margin-top:0;">다시 분석</button>' : '') +
        manual + goListBtn + '</div>';
    }
    return '<div class="rf-card rf-empty-card">' +
      '<p class="rf-empty-d"><b>상품 목록</b> 탭에서 상품분석을 누르거나, 아래에 상품 URL을 넣어 분석하세요.</p>' +
      manual + goListBtn + '</div>';
  }

  /** 수동 입력 URL 분석 — 스마트스토어/브랜드스토어 상세 상품만 허용 */
  function runManualAnalyze(body) {
    const el = body.querySelector('[data-ctl="manual-url"]');
    const url = String((el && el.value) || '').trim();
    state.manualUrl = url;
    if (!isProductDetailUrl(url)) {
      state.product = { loading: false, error: '스마트스토어/브랜드스토어 상세 상품 URL만 분석할 수 있습니다. (예: smartstore.naver.com/스토어/products/123…)', html: '', targetTitle: '', targetLink: '' };
      render();
      return;
    }
    state.manualUrl = '';
    openSavedOrAnalyze(url.split('#')[0], '');
  }

  function bindProduct(body) {
    bindRankfreeBar(body); // 랭크프리·공유 버튼
    // '상품 목록' 탭으로 이동(분석은 그 탭에서만 트리거). 분석 결과(state.product)는 유지
    body.querySelectorAll('[data-act="go-list"]').forEach((b) =>
      b.addEventListener('click', () => { state.tab = 'seller'; render(); })
    );
    // 수동 분석 열기 — 리포트를 비우고 URL 입력 화면으로
    body.querySelectorAll('[data-act="manual-open"]').forEach((b) =>
      b.addEventListener('click', () => { state.product = { loading: false, error: null, html: '', targetTitle: '', targetLink: '' }; render(); })
    );
    const retry = body.querySelector('[data-act="pa-retry"]');
    if (retry && state.product.targetLink) {
      retry.addEventListener('click', () => runProductAnalysisByLink(state.product.targetLink, state.product.targetTitle));
    }
    // 수동 URL 분석
    const manualBtn = body.querySelector('[data-act="manual-analyze"]');
    if (manualBtn) {
      manualBtn.addEventListener('click', () => runManualAnalyze(body));
      const inp = body.querySelector('[data-ctl="manual-url"]');
      if (inp) inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') runManualAnalyze(body); });
    }
    const rerun = body.querySelector('[data-act="run"]'); // 주입된 리포트의 ↻ 다시 분석
    if (rerun && state.product.targetLink) {
      rerun.addEventListener('click', () => runProductAnalysisByLink(state.product.targetLink, state.product.targetTitle));
    }
    // 옵션별 예상 판매·매출 — 주입된 리포트라 product.js 바인딩이 없으므로 여기서 재계산(data-ratio 사용)
    const est = body.querySelector('.rf-est-table');
    if (est) {
      const salesEl = body.querySelector('[data-ctl="sales6m"]');
      const priceEl = body.querySelector('[data-ctl="price"]');
      const recompute = () => {
        const sales = num(salesEl && salesEl.value);
        const price = num(priceEl && priceEl.value);
        est.querySelectorAll('tbody tr').forEach((tr) => {
          const ratio = parseFloat(tr.dataset.ratio) || 0;
          const qty = sales > 0 ? Math.round(sales * ratio) : null;
          const rev = qty != null && price > 0 ? qty * price : null;
          const q = tr.querySelector('.rf-est-qty');
          const rv = tr.querySelector('.rf-est-rev');
          if (q) q.textContent = qty == null ? '-' : comma(qty) + '건';
          if (rv) rv.textContent = rev == null ? '-' : krw(rev) + '원';
        });
      };
      if (salesEl) salesEl.addEventListener('input', recompute);
      if (priceEl) priceEl.addEventListener('input', recompute);
    }
  }

  async function runSellerPower(idx) {
    const list = sellerProducts();
    const target = list[idx];
    if (!target) return;
    // 셀러력은 네이버 스마트스토어/브랜드스토어 상품만 — 옥션·지마켓·자사몰·가격비교 불가
    if (!isSmartstoreProduct(target)) {
      state.seller = { loading: false, error: '셀러력은 네이버 스마트스토어 상품만 분석할 수 있습니다(옥션·지마켓·자사몰·가격비교 제외).', result: null, targetTitle: target.title || '', targetLink: target.link || '' };
      render();
      return;
    }
    // 비교 대상도 스마트스토어 조직 상품만(광고·타몰 제외)
    const competitors = list.filter((p, i) => i !== idx && !p.isAd && isSmartstoreProduct(p)).slice(0, 10);

    const tLink = target.link || '';
    state.seller = { loading: true, error: null, result: null, targetTitle: target.title || '', targetLink: tLink };
    render();
    const r = await sendBg('saveSellerPower', {
      mode: 'search',
      keyword: state.query || getQueryFromUrl(),
      terms: (state.relatedTags || []).slice(0, 10),
      product_url: String((target.link || ('search:' + (target.id || idx))).split('?')[0]).slice(0, 500), // 광고 crUrl 등 초장문 방지(쿼리 제거+절단)
      my: target,
      competitors,
    });
    if (r && r.ok && r.result) {
      state.seller = { loading: false, result: r.result, targetTitle: target.title || '', targetLink: tLink, shareToken: r.shareToken || '', webBase: r.apiBase || state.apiBase };
    } else {
      state.seller = { loading: false, error: (r && r.message) || (r && r.loggedIn === false ? '로그인이 필요합니다.' : '셀러력 분석에 실패했습니다.'), result: null, targetTitle: target.title || '', targetLink: tLink };
    }
    render();
  }

  // ---- 셀러력 리포트 렌더(레이더·손해·처방) ----
  const SP_SEM = { ok: '#05b169', warn: '#f4b000', bad: '#cf202f' };
  function spGc(g) { return ({ S: '#05b169', A: '#0052ff', B: '#8b5cf6', C: '#f4b000', D: '#a8acb3' })[g] || '#a8acb3'; }
  function spDiffLabel(d) { return ({ easy: '쉬움', mid: '보통', hard: '어려움' })[d] || d; }
  function spDiffColor(d) { return ({ easy: '#0052ff', mid: '#f4b000', hard: '#a8acb3' })[d] || '#a8acb3'; }

  function sellerReportHtml(r) {
    const g = r.grade || 'D', gcolor = spGc(g), score = Math.round(r.score || 0);
    const circ = 477.5, off = circ * (1 - Math.max(0, Math.min(100, score)) / 100);
    const gapTop = (r.radar_avg_total || score) - score;
    const legs = (r.axes || []).map((a) => {
      const gapc = (a.gap || 0) >= 0 ? SP_SEM.ok : SP_SEM.bad;
      const mine = Math.max(0, Math.min(100, num(a.mine)));
      const avg = Math.max(0, Math.min(100, num(a.avg)));
      return '<div class="rf-sp-leg">' +
        '<div class="lh"><span class="n">' + esc(a.key) + '</span>' +
        '<span class="v"><b>' + a.mine + '</b><s>/ 상위 ' + a.avg + '</s>' +
        '<em style="color:' + gapc + ';background:' + gapc + '22;">' + ((a.gap || 0) >= 0 ? '+' : '') + a.gap + '</em></span></div>' +
        '<div class="rfbar"><div class="rffill" style="width:' + mine + '%;background:' + gcolor + '"></div>' +
        '<i class="rfavgmk" style="left:' + avg + '%" title="상위 평균"></i></div>' +
        '</div>';
    }).join('');
    const losses = (r.losses || []).map((l) => {
      return '<div class="rf-sp-loss"><div class="rk">' + l.rank + '</div><div class="lc">' +
        '<div class="lt">' + esc(l.title) + '</div><div class="ld"><b>' + esc(l.cur) + '</b> → ' + esc(l.target) + '</div>' +
        '<div class="tg"><span class="gain">잠재 +' + l.gain + '점</span>' +
        '<span class="diff">난이도 ' + spDiffLabel(l.difficulty) + '</span></div></div></div>';
    }).join('');
    const rx = (r.rx || []).map((grp) => {
      const items = grp.items.map((it) => {
        const c = SP_SEM[it.state] || '#a8acb3', mk = ({ ok: '✓', warn: '!', bad: '✕' })[it.state] || '·';
        return '<div class="rf-sp-rx"><span class="mk" style="color:' + c + ';background:' + c + '22;">' + mk + '</span>' +
          '<div><b>' + esc(it.name) + '</b> <span>— ' + esc(it.tip) + '</span></div></div>';
      }).join('');
      return '<div class="rf-sp-rxg"><div class="ax">' + esc(grp.axis) + '</div>' + items + '</div>';
    }).join('');
    const pLink = (state.seller && state.seller.targetLink) || '';
    const pName = esc(r.product_name || state.seller.targetTitle || '');
    return '<div class="rf-sp-hero">' +
        (pLink
          ? '<a class="rf-sp-pname" href="' + esc(pLink.split('#')[0]) + '" target="_blank" rel="noopener">' + pName + '</a>'
          : '<div class="rf-sp-pname">' + pName + '</div>') +
        '<div class="gauge"><svg width="150" height="150" viewBox="0 0 176 176" style="transform:rotate(-90deg)">' +
          '<circle cx="88" cy="88" r="76" fill="none" stroke="#eef0f3" stroke-width="14"/>' +
          '<circle cx="88" cy="88" r="76" fill="none" stroke="' + gcolor + '" stroke-width="14" stroke-linecap="round" stroke-dasharray="' + circ + '" stroke-dashoffset="' + off + '"/></svg>' +
          '<div class="gc"><div class="sn" style="color:' + gcolor + '">' + score + '</div><div class="sm">셀러력 / 100</div></div></div>' +
        '<div class="grade" style="color:' + gcolor + ';background:' + gcolor + '22;">' + g + '등급</div>' +
        '<p class="verdict">' + (gapTop > 0
          ? '상위권과 <b>' + Math.round(gapTop) + '점 차이</b>. 아래 개선 우선순위부터 손보면 순위가 오릅니다.'
          : '이미 <b>상위권</b> 셀러력입니다.') + '</p>' +
        '<div class="stats"><div><span>시장 상위</span><b>' + (r.market_percentile || 0) + '%</b></div>' +
          '<div><span>경쟁 순위</span><b>' + (r.rank_in_top || 0) + '/' + ((r.competitor_count || 0) + 1) + '위</b></div></div>' +
      '</div>' +
      '<div class="rf-sp-sec"><div class="st">어디서 밀리나 — 5축</div>' +
        '<div class="radar-row"><canvas id="rfSpRadar" width="360" height="360"></canvas><div class="legs">' + legs + '</div></div>' +
        '<div class="rlegend"><span><i class="mine"></i>내 상품</span><span><i class="avg"></i>상위 평균</span></div></div>' +
      (losses ? '<div class="rf-sp-sec"><div class="st">가장 큰 손해부터</div>' + losses + '</div>' : '') +
      (rx ? '<div class="rf-sp-sec"><div class="st">항목별 처방</div>' + rx + '</div>' : '') +
      ((r.tags && r.tags.length)
        ? '<div class="rf-sp-sec"><div class="st">판매자 태그 <span style="color:var(--faint);font-weight:400;">' + r.tags.length + '개</span></div>' +
          '<div class="rf-sp-tags">' + r.tags.map((t) => '<span class="rf-sp-tag">' + esc(t) + '</span>').join('') + '</div></div>'
        : '') +
      '<div class="rf-sp-saved">✓ 저장됨 · 웹 콘솔(쇼핑 → 셀러력)에서 다시 볼 수 있어요</div>';
  }

  function sellerDrawRadar() {
    const s = state.seller;
    if (!s.result) return;
    const cv = document.getElementById('rfSpRadar');
    if (!cv) return;
    const AXES = (s.result.axes || []).map((a) => ({ key: a.key, mine: a.mine, avg: a.avg }));
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

  // ---------- 로그인 ----------
  function loginHtml() {
    return (
      '<div class="rf-login">' +
      '<h3>랭크프리 로그인</h3>' +
      '<p class="rf-muted">시장 분석은 rankfree.kr 회원만 이용할 수 있습니다.</p>' +
      '<label class="rf-label">이메일</label>' +
      '<input type="email" class="rf-input" name="email" autocomplete="username" placeholder="you@example.com">' +
      '<label class="rf-label">비밀번호</label>' +
      '<input type="password" class="rf-input" name="password" autocomplete="current-password" placeholder="••••••••">' +
      '<div class="rf-login-err" hidden></div>' +
      '<button type="button" class="rf-btn-primary" data-act="login">로그인</button>' +
      '<a class="rf-link rf-join" href="https://rankfree.kr/register" target="_blank" rel="noopener">아직 계정이 없다면 — 무료 가입</a>' +
      '</div>'
    );
  }

  function bindLogin(body) {
    const btn = body.querySelector('[data-act="login"]');
    const errEl = body.querySelector('.rf-login-err');
    const doLogin = async () => {
      const email = body.querySelector('[name="email"]').value.trim();
      const password = body.querySelector('[name="password"]').value;
      if (!email || !password) {
        errEl.hidden = false;
        errEl.textContent = '이메일과 비밀번호를 입력해 주세요.';
        return;
      }
      btn.disabled = true;
      btn.textContent = '로그인 중…';
      const res = await sendBg('login', { email, password }); // 서버는 항상 기본(rankfree.kr)
      btn.disabled = false;
      btn.textContent = '로그인';
      if (res.ok) {
        state.loggedIn = true;
        state.user = res.user;
        render();
        extractKeywords(); // 키워드만 자동 추출 — 수집은 버튼으로
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

  // ---------- 설정 ----------
  function settingsHtml() {
    const base = state.apiBase || 'https://rankfree.kr';
    return (
      '<div class="rf-settings">' +
      '<h3>설정</h3>' +
      '<label class="rf-label">rankfree API 키 <span class="rf-chip">scope: keyword</span></label>' +
      '<input type="text" class="rf-input" name="apiKey" placeholder="rk_..." value="' +
      esc(state.apiKey || '') + '" spellcheck="false" autocomplete="off">' +
      '<p class="rf-set-hint">키를 등록하면 키워드 분석이 공개 API(v1)로 조회되고, ' +
      'keyword_detail scope가 있으면 성별·연령·12개월 트렌드까지 표시됩니다. ' +
      '<a class="rf-link" href="' + esc(base) + '/console/api-keys" target="_blank" rel="noopener">콘솔에서 키 발급 →</a></p>' +
      '<div class="rf-set-actions">' +
      '<button type="button" class="rf-btn-primary" data-act="save-key">저장</button>' +
      '<button type="button" class="rf-btn-ghost" data-act="back">닫기</button>' +
      '</div>' +
      '<div class="rf-set-msg" hidden></div>' +
      '</div>'
    );
  }

  function bindSettings(body) {
    const msg = body.querySelector('.rf-set-msg');
    body.querySelector('[data-act="save-key"]').addEventListener('click', async () => {
      const key = body.querySelector('[name="apiKey"]').value.trim();
      const res = await sendBg('saveSettings', { apiKey: key });
      state.apiKey = key;
      msg.hidden = false;
      msg.textContent = res && res.ok ? (key ? '저장되었습니다.' : 'API 키를 제거했습니다.') : '저장에 실패했습니다.';
      // 이미 분석된 키워드가 있으면 키워드 데이터만 즉시 갱신
      if (state.lastAnalyzedQuery) refreshKeyword(state.lastAnalyzedQuery);
    });
    body.querySelector('[data-act="back"]').addEventListener('click', () => {
      state.showSettings = false;
      render();
    });
  }

  /** 키워드 분석(검색량 등) 조회 — 중복 호출 방지 가드 */
  async function refreshKeyword(query) {
    if (state.keywordLoading) return;
    state.keywordLoading = true;
    const res = await sendBg('keywordAnalysis', { keyword: query });
    state.keywordData = res && res.ok && res.data ? res.data : null;
    state.keywordShareToken = res && res.ok ? (res.share_token || '') : '';
    if (res && res.apiBase) state.apiBase = res.apiBase;
    state.keywordMsg = res && !res.ok ? res.message || null : null;
    state.keywordLoading = false;
    if (!state.showSettings) render();
  }

  // ---------- 내역 / 저장본 ----------
  /** 내역 통합 — 셀러력·시장분석·상품분석 3종을 한 목록으로(타입 배지·날짜순). */
  async function loadHistory(force) {
    if (state.historyLoading) return;
    if (!force && Array.isArray(state.history)) return;
    state.historyLoading = true;
    render();
    const [mk, sp, pr] = await Promise.all([
      sendBg('listMarketAnalyses', { limit: 30 }),
      sendBg('listSellerPower', { limit: 30 }),
      sendBg('listProductAnalyses', { limit: 30 }),
    ]);
    const items = [];
    ((mk && mk.ok && mk.data) || []).forEach((a) => items.push({
      type: 'market', id: a.id, title: a.keyword || '', date: a.created_at || a.updated_at || '',
      meta: '시장 ' + krw(num(a.revenue_6m)) + '원' + (a.monthly_search ? ' · 월검색 ' + krw(num(a.monthly_search)) : ''),
    }));
    ((sp && sp.ok && sp.data) || []).forEach((a) => items.push({
      type: 'seller', id: a.id, title: a.product_name || a.keyword || a.store_id || '', date: a.updated_at || a.created_at || '',
      meta: '셀러력 ' + Math.round(a.score || 0) + '점 · ' + (a.grade || '-') + '등급',
    }));
    ((pr && pr.ok && pr.data) || []).forEach((a) => items.push({
      type: 'product', id: a.id, title: a.name || a.store || '', date: a.updated_at || a.created_at || '', url: a.url || '',
      origin_product_no: a.origin_product_no, // 저장본 재사용 매칭용
      meta: '상품 분석' + (a.total_reviews ? ' · 리뷰 ' + comma(a.total_reviews) : ''),
    }));
    items.sort((x, y) => String(y.date).localeCompare(String(x.date)));
    state.history = items;
    state.historyWebBase = (sp && sp.apiBase) || (mk && mk.apiBase) || state.apiBase;
    state.historyLoading = false;
    render();
  }

  function historyHtml() {
    if (state.historyLoading) {
      return '<div class="rf-loading"><div class="rf-spinner"></div>내역을 불러오는 중…</div>';
    }
    const list = Array.isArray(state.history) ? state.history : [];
    if (!list.length) {
      return '<div class="rf-empty"><p>저장된 분석 내역이 없습니다.<br>셀러력·시장분석·상품분석을 실행하면 자동으로 저장됩니다.</p></div>';
    }
    const label = (t) => ({ market: '시장분석', seller: '셀러력', product: '상품분석' })[t] || t;
    return (
      '<div class="rf-card rf-card-bare"><div class="rf-card-title">최근 분석 내역 <span class="rf-chip">' + list.length + '건</span>' +
      '<span class="rf-copy-group"><button type="button" class="rf-copy" data-act="hist-refresh">새로고침</button></span></div>' +
      '<div class="rf-hist">' +
      list
        .map(
          (a) =>
            '<button type="button" class="rf-hist-row" data-id="' + a.id + '" data-type="' + a.type + '">' +
            '<span class="rf-hist-badge rf-hb-' + a.type + '">' + label(a.type) + '</span>' +
            '<span class="rf-hist-kw">' + esc((a.title || '').slice(0, 38)) +
            '<span class="rf-hist-meta">' + esc(String(a.date).slice(0, 10)) + ' · ' + esc(a.meta) + '</span></span>' +
            '</button>'
        )
        .join('') +
      '</div></div>' +
      '<p class="rf-note">항목을 클릭하면 저장 결과를 다시 봅니다. 웹 콘솔에서도 확인됩니다.</p>'
    );
  }

  function bindHistory(body) {
    body.querySelectorAll('.rf-hist-row').forEach((row) =>
      row.addEventListener('click', () => openHistory(row.dataset.id, row.dataset.type))
    );
    const refresh = body.querySelector('[data-act="hist-refresh"]');
    if (refresh) refresh.addEventListener('click', () => loadHistory(true));
  }

  /** 내역 항목 열기 — 타입별 라우팅(시장→시장탭, 셀러력→리포트, 상품→저장본 재생). */
  async function openHistory(id, type) {
    if (type === 'seller') return openSavedSeller(id);
    if (type === 'product') return openSavedProduct(id);
    return openSaved(id); // market
  }

  /** 저장된 상품 분석 열기 — 저장 당시 리포트(report_html)를 재수집 없이 상품분석 탭에 재생. */
  async function openSavedProduct(id) {
    const item = (state.history || []).find((h) => String(h.id) === String(id) && h.type === 'product');
    state.tab = 'product';
    state.product = { loading: true, error: null, html: '', targetTitle: (item && item.title) || '', targetLink: (item && item.url) || '' };
    render();
    const res = await sendBg('getProductAnalysis', { id });
    const a = res && res.ok && res.data;
    if (a && a.report_html) {
      state.product = { loading: false, error: null, html: a.report_html, targetTitle: a.name || (item && item.title) || '', targetLink: a.url || (item && item.url) || '', id: a.id, shareToken: a.share_token || '' };
      render();
      return;
    }
    // 저장 당시 리포트가 없으면(구버전 저장본) 상세 URL로 재분석 폴백
    const url = (a && a.url) || (item && item.url) || '';
    if (url && /(smartstore|brand)\.naver\.com\/[^/]+\/products\/\d+/.test(url)) {
      return runProductAnalysisByLink(url.split('#')[0], (a && a.name) || (item && item.title) || '');
    }
    state.product = { loading: false, error: '저장된 리포트를 불러오지 못했습니다.', html: '', targetTitle: (item && item.title) || '', targetLink: url };
    render();
  }

  /** 저장된 셀러력 열기 — 셀러 탭에서 리포트 렌더 */
  async function openSavedSeller(id) {
    state.tab = 'seller';
    state.seller = { loading: true, error: null, result: null, targetTitle: '' };
    render();
    const res = await sendBg('getSellerPower', { id });
    const a = res && res.ok && res.data;
    if (a && a.snapshot) {
      state.seller = { loading: false, result: a.snapshot, targetTitle: a.product_name || '', targetLink: a.product_url || '', shareToken: a.share_token || '', webBase: res.apiBase || state.apiBase };
    } else {
      state.seller = { loading: false, error: '셀러력 내역을 불러오지 못했습니다.', result: null, targetTitle: '' };
    }
    render();
  }

  /** 저장본 열기 — 시장 분석 탭에서 저장 당시 데이터로 렌더링 */
  async function openSaved(id) {
    state.tab = 'market';
    state.loading = true;
    state.error = null;
    state.snapshot = null;
    render();
    const res = await sendBg('getMarketAnalysis', { id });
    state.loading = false;
    if (!res || !res.ok || !res.data) {
      state.error = '저장본을 불러오지 못했습니다.';
      render();
      return;
    }
    const a = res.data;
    state.snapshot = a;
    state.marketToken = a.share_token || '';
    state.query = a.keyword;
    state.totalCount = num(a.total_count);
    state.relatedTags = (a.snapshot && a.snapshot.related_tags) || [];
    state.keywordData = (a.snapshot && a.snapshot.keyword_data) || null;
    state.keywordMsg = null;
    render();
  }

  function clearSnapshot() {
    state.snapshot = null;
    state.products = [];
    state.totalCount = 0;
    state.relatedTags = [];
    state.keywordData = undefined;
    state.keywordMsg = null;
    state.savedId = null;
    state.lastAnalyzedQuery = null;
    state.query = getQueryFromUrl();
  }

  /**
   * 스냅샷이 서버 저장 한도(json_encode 120,000바이트)를 넘지 않게 무거운 부분부터 축소.
   * PHP json_encode는 한글을 \uXXXX(6바이트)로 escape하므로 그 기준으로 크기를 계산한다.
   */
  function encodedSize(obj) {
    const s = JSON.stringify(obj) || '';
    let n = 0;
    for (let i = 0; i < s.length; i++) n += s.charCodeAt(i) < 128 ? 1 : 6; // 비ASCII는 \uXXXX(6)
    return n;
  }
  function fitSnapshot(snap) {
    const LIMIT = 115000; // 120,000 한도에 여유
    if (encodedSize(snap) <= LIMIT) return snap;
    const kd = snap.keyword_data;
    // 1) 연관 키워드 목록 축소(가장 큰 가변 부분)
    if (kd && Array.isArray(kd.related)) { kd.related = kd.related.slice(0, 30); if (encodedSize(snap) <= LIMIT) return snap; }
    // 2) 원시 성별×연령 버킷 제거(파생 지표·인사이트는 이미 detail에 포함)
    if (kd && kd.detail && kd.detail.buckets) { delete kd.detail.buckets; if (encodedSize(snap) <= LIMIT) return snap; }
    // 3) 상위 상품/카테고리 추가 축소
    if (Array.isArray(snap.top_products)) { snap.top_products = snap.top_products.slice(0, 5); if (encodedSize(snap) <= LIMIT) return snap; }
    // 4) 최후 — 연관 키워드 완전 제거
    if (kd && Array.isArray(kd.related)) kd.related = [];
    return snap;
  }

  /** 분석 결과 서버 자동 저장 */
  async function saveAnalysis() {
    try {
      const m = computeMarket(state.products, state.includeAds);
      // detail(성별·연령·buckets·12개월 트렌드)까지 통째로 저장 — 콘솔 상세 분석에 사용
      const kd = state.keywordData ? JSON.parse(JSON.stringify(state.keywordData)) : null;
      // 숫자 필드 방어 — 카탈로그 매출 보강 실패 등으로 NaN/undefined가 되면 서버 검증(required numeric) 422로
      // 저장이 조용히 실패한다. NaN·예외를 0/유효범위로 강제한다.
      const int0 = (v) => (Number.isFinite(+v) ? Math.round(+v) : 0);
      const pct0 = (v) => { const n = +v; return Number.isFinite(n) ? Math.max(0, Math.min(100, Math.round(n * 100) / 100)) : 0; };

      const snapshot = fitSnapshot({
        related_tags: (state.relatedTags || []).slice(0, 15),
        keyword_data: kd,
        count: state.count,
        top_product_category: m.topProductCategory,
        mall_grades: m.mallGrades,
        top_categories: m.topCategories,
        top_products: m.topProducts.map((p) => ({
          title: String(p.title).slice(0, 100),
          price: p.price,
          purchase6m: p.purchase6m,
          revenue6m: p.revenue6m,
          mallName: p.mallName,
          mallCount: p.mallCount,
          sellerCount: p.sellerCount,
          isAd: p.isAd,
          isCatalog: p.isCatalog,
          link: p.link,
        })),
      });

      const res = await sendBg('saveMarketAnalysis', {
        keyword: state.query,
        total_count: int0(state.totalCount),
        item_count: int0(m.itemCount),
        include_ads: !!state.includeAds,
        sales_6m: int0(m.sales6m),
        revenue_6m: int0(m.revenue6m),
        avg_price: int0(m.avgPrice),
        median_price: int0(m.medianPrice),
        top10_share: pct0(m.top10Share),
        monthly_search: kd ? num(kd.monthly_total) : null,
        comp_idx: kd ? kd.comp_idx || null : null,
        snapshot,
      });
      state.savedId = res && res.ok ? res.id : null;
      state.marketToken = res && res.ok ? (res.share_token || '') : '';
      state.saveLimitMsg = res && res.status === 429 ? (res.message || '이번 달 저장 횟수를 모두 사용했습니다.') : null;
      state.history = undefined; // 내역 캐시 무효화
    } catch (e) {
      state.savedId = null;
    }
  }

  /** 저장본 보기 — 저장 당시 지표/상품으로 렌더링 */
  function snapshotHtml() {
    const a = state.snapshot;
    const monthlyRevenue = num(a.revenue_6m) / 6;
    const monthlyProfit = monthlyRevenue * (state.marginPct / 100);
    const when = String(a.created_at || '').replace('T', ' ').slice(0, 16);

    const banner =
      '<div class="rf-snapbar">📁 저장본 · ' + esc(when) +
      '<span class="rf-copy-group">' +
      '<button type="button" class="rf-copy" data-act="snap-renew">새로 분석</button>' +
      '<button type="button" class="rf-copy" data-act="snap-close">닫기</button>' +
      '</span></div>';

    const stats =
      '<div class="rf-card"><div class="rf-card-title">시장 규모 <span class="rf-chip">' +
      (a.include_ads ? '광고 포함' : '광고 제외') + ' · 상위 ' + comma(num(a.item_count)) + '개 기준</span></div>' +
      '<div class="rf-stats">' +
      statTile('6개월 시장 규모', krw(num(a.revenue_6m)) + '원', '판매량 × 판매가 합산') +
      statTile('월평균 매출', krw(monthlyRevenue) + '원') +
      statTile('6개월 판매량', comma(num(a.sales_6m)) + '건', '월평균 ' + comma(num(a.sales_6m) / 6) + '건') +
      statTile('평균 판매가', comma(num(a.avg_price)) + '원', '중앙값 ' + comma(num(a.median_price)) + '원') +
      statTile('상위 10개 점유율', Number(a.top10_share || 0).toFixed(1) + '%', '매출 기준') +
      statTile('월 예상 수익', krw(monthlyProfit) + '원',
        '마진율 <input type="number" class="rf-margin" data-ctl="margin" min="1" max="90" value="' + state.marginPct + '">%') +
      '</div></div>';

    const tops = (a.snapshot && a.snapshot.top_products) || [];
    const rows = tops
      .map((p, i) => {
        const rev = p.revenue6m != null ? num(p.revenue6m) : num(p.purchase6m) * num(p.price);
        const mallInfo = p.isCatalog
          ? '가격비교' + (p.mallCount ? ' · ' + p.mallCount + '몰' : '')
          : p.mallName || '';
        const title = esc(String(p.title || '').slice(0, 34));
        return (
          '<tr><td class="rf-td-rank">' + (i + 1) + '</td>' +
          '<td class="rf-td-title">' +
          (p.link ? '<a href="' + esc(p.link) + '" target="_blank" rel="noopener">' + title + '</a>' : title) +
          '<div class="rf-td-mall">' + esc(mallInfo) + '</div></td>' +
          '<td class="rf-td-num">' + comma(num(p.price)) + '</td>' +
          '<td class="rf-td-num">' + comma(num(p.purchase6m)) + '</td>' +
          '<td class="rf-td-num rf-td-strong">' + krw(rev) + '</td></tr>'
        );
      })
      .join('');

    const table = tops.length
      ? '<div class="rf-card"><div class="rf-card-title">매출 상위 상품</div>' +
        '<div class="rf-table-wrap"><table class="rf-table">' +
        '<thead><tr><th>#</th><th>상품</th><th>판매가</th><th>6개월<br>판매량</th><th>6개월<br>매출</th></tr></thead>' +
        '<tbody>' + rows + '</tbody></table></div></div>'
      : '';

    const comp = snapshotCompositionHtml(a.snapshot || {});
    const rfbar = state.marketToken ? rankfreeBar(webBase() + '/', webBase() + '/m/' + state.marketToken) : '';
    return rfbar + banner + relatedTagsHtml() + keywordCardHtml() + stats + comp + table;
  }

  /** 저장본의 시장 구성 카드 */
  function snapshotCompositionHtml(snap) {
    const grades = snap.mall_grades || [];
    const cats = snap.top_categories || [];
    if (!grades.length && !cats.length && !snap.top_product_category) return '';
    const gradeTotal = grades.reduce((a, g) => a + num(g[1]), 0) || 1;
    const gradeRows = grades
      .map((g) => {
        const pct = (num(g[1]) / gradeTotal) * 100;
        return (
          '<div class="rf-grade-row"><span class="rf-grade-name">' + esc(g[0]) + '</span>' +
          '<span class="rf-grade-num">' + num(g[1]) + '개 · ' + pct.toFixed(0) + '%</span>' +
          '<span class="rf-grade-track"><span class="rf-grade-bar" style="width:' + Math.max(2, Math.round(pct)) + '%"></span></span></div>'
        );
      })
      .join('');
    const catRows = cats
      .map((c) => '<div class="rf-cat-row"><span class="rf-cat-name">' + esc(c[0]) + '</span><span class="rf-cat-cnt">' + num(c[1]) + '개</span></div>')
      .join('');
    return (
      '<div class="rf-card"><div class="rf-card-title">시장 구성</div>' +
      (snap.top_product_category
        ? '<div class="rf-lead-cat"><span class="rf-lead-lab">1등 상품 카테고리</span><span class="rf-lead-val">' + esc(snap.top_product_category) + '</span></div>'
        : '') +
      (grades.length ? '<div class="rf-kw-sec-title" style="margin-top:10px;">판매처 등급 분포</div>' + gradeRows : '') +
      (cats.length ? '<div class="rf-kw-sec-title" style="margin-top:12px;">주요 카테고리</div><div class="rf-cats">' + catRows + '</div>' : '') +
      '</div>'
    );
  }

  // ---------- 시장 분석 ----------
  function statTile(label, value, sub) {
    return (
      '<div class="rf-stat"><div class="rf-stat-label">' + esc(label) + '</div>' +
      '<div class="rf-stat-value">' + value + '</div>' +
      (sub ? '<div class="rf-stat-sub">' + sub + '</div>' : '') +
      '</div>'
    );
  }

  function normKeyword(s) {
    return String(s).replace(/\s+/g, '').toUpperCase();
  }

  /** 화면에 표시할 연관 키워드 목록 — 쇼핑 연관검색어 + keywordstool 연관어 병합 */
  function mergedRelatedKeywords() {
    const kwRelated = (state.keywordData && state.keywordData.related) || [];
    const list = (state.relatedTags || []).slice(0, 15);
    const seen = new Set(list.map(normKeyword));
    for (const r of kwRelated) {
      if (list.length >= 15) break;
      if (!seen.has(normKeyword(r.keyword))) {
        seen.add(normKeyword(r.keyword));
        list.push(r.keyword);
      }
    }
    return list;
  }

  /** 상단 연관 키워드 칩 — 클릭 이동 + 전체 복사(줄바꿈/쉼표) */
  function relatedTagsHtml() {
    const normKey = normKeyword;

    const kwRelated = (state.keywordData && state.keywordData.related) || [];
    const volumes = {};
    kwRelated.forEach((r) => {
      volumes[normKey(r.keyword)] = num(r.monthly_total);
    });

    const list = mergedRelatedKeywords();

    if (!list.length) return '';

    const chips = list
      .map((kw) => {
        const vol = volumes[normKey(kw)];
        return (
          '<button type="button" class="rf-tag" data-kw="' + esc(kw) + '" title="‘' + esc(kw) + '’ 분석하기">' +
          esc(kw) +
          (vol ? '<span class="rf-tag-vol">' + krw(vol) + '</span>' : '') +
          '</button>'
        );
      })
      .join('');

    return (
      '<div class="rf-card"><div class="rf-card-title">연관 키워드 ' +
      '<span class="rf-chip">클릭하면 해당 키워드 분석</span>' +
      '<span class="rf-copy-group">' +
      '<button type="button" class="rf-copy" data-copy="lines" title="줄바꿈으로 구분해 전체 복사">복사 ↵</button>' +
      '<button type="button" class="rf-copy" data-copy="comma" title="쉼표로 구분해 전체 복사">복사 ,</button>' +
      '</span></div>' +
      '<div class="rf-tags">' + chips + '</div></div>'
    );
  }

  /** '함께 많이 찾는'(네이버 AI 제안 키워드) — 통합검색에서 수집. 클릭 시 해당 키워드 분석. */
  function aiKeywordsHtml() {
    const list = (state.aiKeywords || []).filter(Boolean);
    if (!list.length) return '';
    const chips = list
      .map((kw) => '<button type="button" class="rf-tag" data-kw="' + esc(kw) + '" title="‘' + esc(kw) + '’ 분석하기">' + esc(kw) + '</button>')
      .join('');
    return (
      '<div class="rf-card"><div class="rf-card-title">함께 많이 찾는 ' +
      '<span class="rf-chip">AI 제안</span>' +
      '<span class="rf-copy-group">' +
      '<button type="button" class="rf-copy" data-copy-ai="lines" title="줄바꿈으로 구분해 전체 복사">복사 ↵</button>' +
      '<button type="button" class="rf-copy" data-copy-ai="comma" title="쉼표로 구분해 전체 복사">복사 ,</button>' +
      '</span></div>' +
      '<div class="rf-tags">' + chips + '</div>' +
      '<p class="rf-note">더 깊이 있는 검색을 위해 AI가 사용자 관심사와 검색 흐름에 적합한 키워드를 제안합니다. AI의 특성상 다소 부정확하거나 부적절한 정보가 포함될 수 있습니다.</p>' +
      '</div>'
    );
  }

  /** 웹(랭크프리) 베이스 URL */
  function webBase() {
    return String(state.apiBase || 'https://rankfree.kr').replace(/\/+$/, '');
  }

  /** 랭크프리에서 보기 + 공유 바(셀러력 리포트와 동일 스타일) */
  function rankfreeBar(webUrl, shareUrl) {
    if (!webUrl && !shareUrl) return '';
    return '<div class="rf-sp-refresh-bar"><div class="rf-sp-bar-left">' +
      (webUrl ? '<button type="button" class="rf-sp-refresh" data-rf-web="' + esc(webUrl) + '">랭크프리에서 보기</button>' : '') +
      (shareUrl ? '<button type="button" class="rf-sp-refresh" data-rf-share="' + esc(shareUrl) + '">🔗 공유</button>' : '') +
      '</div></div>';
  }
  function bindRankfreeBar(body) {
    body.querySelectorAll('[data-rf-web]').forEach((b) =>
      b.addEventListener('click', () => { try { window.open(b.dataset.rfWeb, '_blank'); } catch (e) { /* noop */ } })
    );
    body.querySelectorAll('[data-rf-share]').forEach((b) =>
      b.addEventListener('click', () => { try { window.open(b.dataset.rfShare, '_blank'); } catch (e) { /* noop */ } })
    );
  }

  /** 키워드 분석 탭 — 검색량·경쟁·성별/연령·12개월 트렌드·연관키워드 (통합검색·쇼핑검색 공용) */
  function keywordTabHtml() {
    const q = state.query || getQueryFromUrl() || '';
    if (!q) {
      return '<div class="rf-empty"><p>검색어를 찾을 수 없습니다.<br>네이버 통합검색 또는 쇼핑 검색에서 이용하세요.</p></div>';
    }
    const web = webBase() + '/console/keyword?keyword=' + encodeURIComponent(q);
    const share = state.keywordShareToken ? (webBase() + '/k/' + state.keywordShareToken) : web;
    return rankfreeBar(web, share) +
      '<div class="rf-kw-head"><span>‘<b>' + esc(q) + '</b>’ 키워드 분석</span>' +
      '<button type="button" class="rf-btn-ghost rf-kw-refresh" data-act="kw-reanalyze" title="다시 분석"' +
      (state.keywordLoading ? ' disabled' : '') + '>↻</button></div>' +
      keywordInsightHtml(state.keywordData) +
      aiKeywordsHtml() +
      keywordCardHtml() + relatedTagsHtml();
  }

  /** 키워드 인사이트 — 서버(콘솔)와 동일한 PC식 자연어 요약. 상세(성별·연령·계절) 없으면 지표 요약으로 폴백. */
  function keywordInsightHtml(k) {
    if (!k) return '';
    // 원래 PC처럼: 항목별 나열 대신 서버가 만든 자연어 요약 문장 + 시즌/타겟 칩(데이터 기반)
    const ins = k.detail && k.detail.insights;
    if (ins && ins.summary) {
      const cards = Array.isArray(ins.cards) ? ins.cards : [];
      const chip = (c) => '<div class="rf-ins-chip"><span class="rf-ins-chip-l">' + esc(c.label) + '</span><span class="rf-ins-chip-v">' + esc(c.value) + '</span></div>';
      const grp = (title, arr) => arr.length
        ? '<div class="rf-ins-grp"><div class="rf-ins-grp-t">' + title + '</div><div class="rf-ins-chips">' + arr.map(chip).join('') + '</div></div>'
        : '';
      const season = cards.filter((c) => c.group === 'season');
      const target = cards.filter((c) => c.group === 'target');
      return '<div class="rf-card rf-kw-insight">' +
        '<div class="rf-card-title">키워드 인사이트 <span class="rf-chip">데이터 기반 요약</span></div>' +
        grp('시즌 분석', season) + grp('타겟 분석', target) +
        '<p class="rf-ins-summary"><span class="rf-ins-ico">💡</span><span>' + esc(ins.summary) + '</span></p>' +
        '</div>';
    }
    const total = num(k.monthly_total);
    const pc = num(k.monthly_pc);
    const mobile = num(k.monthly_mobile);
    const lines = [];
    if (total > 0) {
      const gTxt = { S: '최상위', A: '상위권', B: '상위', C: '중상위', D: '중위', E: '하위', F: '미미' }[k.grade] || '';
      lines.push('월 <b>' + comma(total) + '회</b> 검색' + (k.grade ? ' · <b>' + esc(k.grade) + '등급</b>' + (gTxt ? '(' + gTxt + ')' : '') : ''));
    }
    if (total > 0) {
      const moPct = Math.round((mobile / total) * 100);
      const pcPct = 100 - moPct;
      if (moPct >= 65) lines.push('<b>모바일 중심</b> 키워드 (모바일 ' + moPct + '%)');
      else if (pcPct >= 45) lines.push('<b>PC 비중이 높은</b> 키워드 (PC ' + pcPct + '%)');
      else lines.push('PC·모바일 고른 분포 (모바일 ' + moPct + '%)');
    }
    const wd = Array.isArray(k.weekday) ? k.weekday : [];
    if (wd.length) {
      const peak = wd.reduce((a, b) => (num(b.pct) > num(a.pct) ? b : a), wd[0]);
      lines.push('<b>' + esc(peak.w) + '요일</b>에 검색이 가장 많음 (' + num(peak.pct) + '%)');
    }
    const months = k.detail && Array.isArray(k.detail.monthly) ? k.detail.monthly : [];
    if (months.length) {
      const pm = months.reduce((a, b) => (num(b.total) > num(a.total) ? b : a), months[0]);
      const mm = String(pm.label || '').match(/(\d{1,2})(?!.*\d)/);
      if (mm) lines.push('연중 <b>' + parseInt(mm[1], 10) + '월</b>에 검색량이 가장 높음');
    }
    if (k.comp_idx) lines.push('광고 경쟁 강도 <b>' + esc(k.comp_idx) + '</b>');
    if (!lines.length) return '';
    return '<div class="rf-card rf-kw-insight"><div class="rf-card-title">키워드 인사이트 <span class="rf-chip">데이터 기반 요약</span></div>' +
      '<ul class="rf-insight-list">' + lines.map((l) => '<li>' + l + '</li>').join('') + '</ul></div>';
  }
  function bindKeyword(body) {
    bindRankfreeBar(body);
    // 연관 키워드 칩 클릭 → 그 키워드를 in-panel 재분석(페이지 이동 없음)
    body.querySelectorAll('[data-kw]').forEach((b) =>
      b.addEventListener('click', () => {
        const kw = b.dataset.kw;
        if (!kw) return;
        state.query = kw;
        state.keywordData = undefined;
        state.keywordMsg = null;
        refreshKeyword(kw);
      })
    );
    // 연관 키워드 복사
    body.querySelectorAll('[data-copy]').forEach((b) =>
      b.addEventListener('click', async () => {
        const kws = mergedRelatedKeywords();
        const text = b.dataset.copy === 'comma' ? kws.join(', ') : kws.join('\n');
        const ok = await copyText(text);
        b.textContent = ok ? '복사됨 ✓' : '복사 실패';
        setTimeout(() => render(), 1200);
      })
    );
    // '함께 많이 찾는'(AI 제안) 키워드 복사
    body.querySelectorAll('[data-copy-ai]').forEach((b) =>
      b.addEventListener('click', async () => {
        const kws = (state.aiKeywords || []).filter(Boolean);
        const text = b.dataset.copyAi === 'comma' ? kws.join(', ') : kws.join('\n');
        const ok = await copyText(text);
        b.textContent = ok ? '복사됨 ✓' : '복사 실패';
        setTimeout(() => render(), 1200);
      })
    );
    const set = body.querySelector('[data-act="open-settings"]');
    if (set) set.addEventListener('click', () => { state.showSettings = true; render(); });
    // 키워드 재분석 — 검색량·성별/연령·인사이트 재조회 + 연관·AI 키워드 재수집
    const re = body.querySelector('[data-act="kw-reanalyze"]');
    if (re) re.addEventListener('click', () => {
      if (state.keywordLoading) return;
      const q = state.query || getQueryFromUrl();
      if (!q) return;
      state.keywordData = undefined;
      state.keywordMsg = null;
      refreshKeyword(q);
      extractKeywords();
    });
  }

  /** 클립보드 복사 — Clipboard API 실패 시 execCommand 폴백 */
  async function copyText(text) {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch (e) {
      try {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.cssText = 'position:fixed;left:-9999px;top:0;';
        document.body.appendChild(ta);
        ta.select();
        const ok = document.execCommand('copy');
        ta.remove();
        return ok;
      } catch (e2) {
        return false;
      }
    }
  }

  function keywordCardHtml() {
    if (state.keywordData === undefined) {
      return state.keywordLoading
        ? '<div class="rf-card rf-kw"><div class="rf-card-title">키워드 분석</div><p class="rf-muted">검색량·트렌드를 불러오는 중…</p></div>'
        : ''; // 아직 조회 안 함
    }
    if (state.keywordData === null) {
      return (
        '<div class="rf-card rf-kw"><div class="rf-card-title">키워드 분석</div>' +
        '<p class="rf-muted">' + esc(state.keywordMsg || '이 키워드의 검색량 데이터를 가져오지 못했습니다.') + '</p>' +
        (state.apiKey
          ? ''
          : '<button type="button" class="rf-link" data-act="open-settings">⚙ API 키를 등록하면 검색량·상세 분석이 표시됩니다</button>') +
        '</div>'
      );
    }
    const k = state.keywordData;
    const total = num(k.monthly_total);
    const pc = num(k.monthly_pc);
    const mobile = num(k.monthly_mobile);
    const pcShare = total > 0 ? Math.round((pc / total) * 100) : 0;
    const moShare = total > 0 ? Math.round((mobile / total) * 100) : 0;
    return (
      '<div class="rf-card rf-kw">' +
      '<div class="rf-card-title">키워드 분석 <span class="rf-chip">네이버 검색광고 기준</span>' +
      (k.detail ? '<span class="rf-chip">상세</span>' : '') + '</div>' +
      // 월간 검색량 — 한 줄 전체 + PC/모바일 표
      '<div class="rf-kw-vol">' +
        '<div class="rf-kw-vol-head"><span class="lab">월간 검색량</span><span class="num">' + comma(total) + '</span></div>' +
        '<div class="rf-kw-dev">' +
          '<div class="cell"><span class="k">PC</span><span class="v">' + comma(pc) + '</span><span class="p">' + pcShare + '%</span></div>' +
          '<div class="cell"><span class="k">모바일</span><span class="v">' + comma(mobile) + '</span><span class="p">' + moShare + '%</span></div>' +
        '</div>' +
      '</div>' +
      // 경쟁 강도 · 검색량 등급 — 같은 라인
      '<div class="rf-stats" style="margin-top:8px;">' +
        statTile('경쟁 강도', esc(k.comp_idx || '-'), '광고 입찰 경쟁(네이버 제공)') +
        statTile('검색량 등급', esc(k.grade || '-'), 'S~F · 월간 검색량 기준') +
      '</div>' +
      '<p class="rf-note">* 검색량은 네이버 검색광고 월간 조회수입니다. 등급(S~F)은 검색량 기준, 경쟁 강도는 광고 입찰 기준(낮음·중간·높음)입니다.</p>' +
      keywordDetailHtml(k.detail, k.weekday) +
      '</div>'
    );
  }

  /** 요일별 검색 비율(월~일) — DataLab 기준. 단색 세로 막대. */
  function weekdayHtml(weekday) {
    const wd = Array.isArray(weekday) ? weekday : [];
    if (!wd.length) return '';
    const max = Math.max.apply(null, wd.map((d) => num(d.pct))) || 1;
    const peakIdx = wd.reduce((mi, d, i) => (num(d.pct) > num(wd[mi].pct) ? i : mi), 0);
    const bars = wd.map((d, i) => {
      const h = Math.max(6, Math.round((num(d.pct) / max) * 100));
      return '<div class="rf-wd-col' + (i === peakIdx ? ' is-peak' : '') + '" title="' + esc(d.w) + '요일 ' + num(d.pct) + '%">' +
        '<span class="rf-wd-p">' + num(d.pct) + '%</span>' +
        '<div class="rf-wd-track"><div class="rf-wd-bar" style="height:' + h + '%"></div></div>' +
        '<span class="rf-wd-x">' + esc(d.w) + '</span></div>';
    }).join('');
    return '<div class="rf-kw-sec"><div class="rf-kw-sec-title">요일별 검색 비율 <span class="rf-wd-peak-lab">' + esc(wd[peakIdx].w) + '요일 최다</span></div>' +
      '<div class="rf-wd">' + bars + '</div></div>';
  }

  /** 상세 분석(월별 트렌드·요일별·성별·연령) — 상세(detail)와 요일별(weekday, 독립 소스) */
  function keywordDetailHtml(detail, weekday) {
    let html = '';

    // 최근 12개월 검색량 — 단일 시리즈 미니 컬럼 (최대월만 직접 라벨)
    const months = detail && Array.isArray(detail.monthly) ? detail.monthly : [];
    if (months.length) {
      let maxIdx = 0;
      months.forEach((m, i) => {
        if (num(m.total) > num(months[maxIdx].total)) maxIdx = i;
      });
      const max = num(months[maxIdx].total) || 1;
      const bars = months
        .map((m, i) => {
          const h = Math.max(4, Math.round((num(m.total) / max) * 100));
          const mm = String(m.label || '').match(/(\d{1,2})(?!.*\d)/); // 마지막 숫자군 = 월
          const mLabel = mm ? (parseInt(mm[1], 10) + '월') : esc(m.label || '');
          return (
            '<div class="rf-tr-col" title="' + esc(m.label) + ' · ' + comma(num(m.total)) +
            ' (PC ' + comma(num(m.pc)) + ' · 모바일 ' + comma(num(m.mobile)) + ')">' +
            '<span class="rf-tr-val' + (i === maxIdx ? ' is-peak' : '') + '">' + krw(num(m.total)) + '</span>' +
            '<div class="rf-tr-track"><div class="rf-tr-bar" style="height:' + h + '%"></div></div>' +
            '<span class="rf-tr-m">' + mLabel + '</span></div>'
          );
        })
        .join('');
      html +=
        '<div class="rf-kw-sec"><div class="rf-kw-sec-title">월별 검색 비율 <span class="rf-wd-peak-lab">최근 12개월</span></div>' +
        '<div class="rf-trend rf-trend-lg">' + bars + '</div></div>';
    }

    // 요일별 검색 비율 — 월별 바로 아래(독립 소스)
    html += weekdayHtml(weekday);

    // 성별 비율 — 100% 분할 바 + 텍스트 직접 라벨(색 단독 식별 금지)
    const g = detail && detail.gender;
    if (g && (num(g.female) > 0 || num(g.male) > 0)) {
      html +=
        '<div class="rf-kw-sec"><div class="rf-kw-sec-title">성별 비율</div>' +
        '<div class="rf-gender" title="여성 ' + num(g.female_pct) + '% · 남성 ' + num(g.male_pct) + '%">' +
        '<div class="rf-gender-f" style="width:' + num(g.female_pct) + '%"></div>' +
        '<div class="rf-gender-m" style="width:' + num(g.male_pct) + '%"></div></div>' +
        '<div class="rf-gender-lab">' +
        '<span><i class="rf-dot rf-dot-f"></i>여성 ' + num(g.female_pct) + '%</span>' +
        '<span><i class="rf-dot rf-dot-m"></i>남성 ' + num(g.male_pct) + '%</span></div></div>';
    }

    // 연령대 분포 — 단색 가로 바 (행 라벨 + 우측 % 라벨)
    const ages = detail && Array.isArray(detail.age) ? detail.age : [];
    if (ages.length) {
      const maxPct = Math.max.apply(null, ages.map((a) => num(a.pct))) || 1;
      html +=
        '<div class="rf-kw-sec"><div class="rf-kw-sec-title">연령대 분포</div>' +
        ages
          .map(
            (a) =>
              '<div class="rf-age-row" title="' + esc(a.age) + '세 · ' + comma(num(a.total)) + '">' +
              '<span class="rf-age-lab">' + esc(a.age) + '</span>' +
              '<span class="rf-age-track"><span class="rf-age-bar" style="width:' +
              Math.round((num(a.pct) / maxPct) * 100) + '%"></span></span>' +
              '<span class="rf-age-pct">' + num(a.pct) + '%</span></div>'
          )
          .join('') +
        '</div>';
    }

    return html;
  }

  function marketHtml() {
    if (state.loading) {
      return (
        '<div class="rf-loading"><div class="rf-spinner"></div>' +
        esc(state.progress || '상품 데이터를 수집하고 있습니다…') +
        '</div>'
      );
    }
    if (state.snapshot) return snapshotHtml();
    if (state.error) {
      return (
        '<div class="rf-error">' + esc(state.error) + '</div>' +
        '<button type="button" class="rf-btn-primary" data-act="retry">다시 시도</button>'
      );
    }
    // 수집 전 — 연관 키워드는 자동 추출, 상품 수집은 버튼으로만
    if (!state.products.length) {
      const scope =
        '<label class="rf-scope">수집 범위 <select class="rf-select" data-ctl="count">' +
        [40, 80, 160, 240]
          .map(
            (c) =>
              '<option value="' + c + '"' + (state.count === c ? ' selected' : '') + '>상위 ' + c + '개</option>'
          )
          .join('') +
        '</select></label>';

      return (
        relatedTagsHtml() +
        '<div class="rf-collect">' +
        (state.extracting ? '<p class="rf-muted">연관 키워드를 추출하고 있습니다…</p>' : '') +
        '<p class="rf-muted">수집을 시작하면 상위 상품을 모아 시장 규모·판매량·예상 수익을 계산합니다.</p>' +
        scope +
        '<button type="button" class="rf-btn-primary" data-act="collect">수집 시작</button>' +
        '</div>'
      );
    }

    const m = computeMarket(state.products, state.includeAds);
    const monthlyProfit = m.monthlyRevenue * (state.marginPct / 100);

    const controls =
      '<div class="rf-controls">' +
      '<label>수집 범위 <select class="rf-select" data-ctl="count">' +
      [40, 80, 160, 240]
        .map(
          (c) =>
            '<option value="' + c + '"' + (state.count === c ? ' selected' : '') + '>상위 ' + c + '개</option>'
        )
        .join('') +
      '</select></label>' +
      '<label class="rf-check"><input type="checkbox" data-ctl="ads"' + (state.includeAds ? ' checked' : '') + '> 광고 포함</label>' +
      '<button type="button" class="rf-btn-ghost" data-act="retry" title="다시 수집">↻</button>' +
      '</div>';

    const stats =
      '<div class="rf-card"><div class="rf-card-title">시장 규모 <span class="rf-chip">최근 6개월 · 상위 ' + m.itemCount + '개 기준</span>' +
      (state.savedId ? '<span class="rf-chip">☁ 저장됨</span>' : '') +
      (state.saveLimitMsg ? '<span class="rf-chip" style="color:var(--rf-error);">저장 한도 초과</span>' : '') + '</div>' +
      (state.saveLimitMsg ? '<p class="rf-note" style="color:var(--rf-error);">' + esc(state.saveLimitMsg) + '</p>' : '') +
      '<div class="rf-stats">' +
      statTile('6개월 시장 규모', krw(m.revenue6m) + '원', '판매량 × 판매가 합산') +
      statTile('월평균 매출', krw(m.monthlyRevenue) + '원') +
      statTile('6개월 판매량', comma(m.sales6m) + '건', '월평균 ' + comma(m.monthlySales) + '건') +
      statTile('평균 판매가', comma(m.avgPrice) + '원', '중앙값 ' + comma(m.medianPrice) + '원') +
      statTile('상위 10개 점유율', m.top10Share.toFixed(1) + '%', '매출 기준') +
      statTile('월 예상 수익', krw(monthlyProfit) + '원',
        '마진율 <input type="number" class="rf-margin" data-ctl="margin" min="1" max="90" value="' + state.marginPct + '">%') +
      '</div>' +
      '<p class="rf-note">* 구매건수는 네이버 노출값(최근 6개월)이며, 시장 규모는 rankfree 자체 추정치입니다.<br>' +
      '* 가격비교 상품은 카탈로그 판매처(스마트스토어 등)의 구매건수×판매가를 합산합니다.</p>' +
      '</div>';

    const rows = m.topProducts
      .map((p, i) => {
        const rev = itemRevenue6m(p);
        const mallInfo = p.isCatalog
          ? '가격비교' +
            (p.mallCount ? ' · ' + p.mallCount + '몰' : '') +
            (p.sellerCount ? ' · 판매량 ' + p.sellerCount + '곳 합산' : '')
          : p.mallName || p.brand || '';
        return (
          '<tr>' +
          '<td class="rf-td-rank">' + (i + 1) + '</td>' +
          '<td class="rf-td-title"><a href="' + esc(p.link) + '" target="_blank" rel="noopener" title="' + esc(p.title) + '">' +
          esc(p.title.length > 34 ? p.title.slice(0, 34) + '…' : p.title) + '</a>' +
          (p.isAd ? ' <span class="rf-ad">AD</span>' : '') +
          '<div class="rf-td-mall">' + esc(mallInfo) + '</div></td>' +
          '<td class="rf-td-num">' + comma(p.price) + '</td>' +
          '<td class="rf-td-num">' + comma(p.purchase6m) + '</td>' +
          '<td class="rf-td-num rf-td-strong">' + krw(rev) + '</td>' +
          '</tr>'
        );
      })
      .join('');

    const table =
      '<div class="rf-card"><div class="rf-card-title">매출 상위 10개 상품</div>' +
      '<div class="rf-table-wrap"><table class="rf-table">' +
      '<thead><tr><th>#</th><th>상품</th><th>판매가</th><th>6개월<br>판매량</th><th>6개월<br>매출</th></tr></thead>' +
      '<tbody>' + rows + '</tbody></table></div></div>';

    const rfbar = state.marketToken ? rankfreeBar(webBase() + '/', webBase() + '/m/' + state.marketToken) : '';
    // 수집 범위(controls) 라인을 연관 키워드(relatedTags) 위로
    return rfbar + controls + relatedTagsHtml() + keywordCardHtml() + stats + compositionHtml(m) + table;
  }

  /** 시장 구성 — 1등 상품 카테고리 + 몰 등급별 카운팅 */
  function compositionHtml(m) {
    if (!m.mallGrades.length && !m.topCategories.length) return '';

    const grades = m.mallGrades
      .map(([g, c]) => {
        const pct = (c / m.itemCount) * 100;
        return (
          '<div class="rf-grade-row" title="' + esc(g) + ' ' + c + '개 (' + pct.toFixed(0) + '%)">' +
          '<span class="rf-grade-name">' + esc(g) + '</span>' +
          '<span class="rf-grade-num">' + c + '개 · ' + pct.toFixed(0) + '%</span>' +
          '<span class="rf-grade-track"><span class="rf-grade-bar" style="width:' + Math.max(2, Math.round(pct)) + '%"></span></span>' +
          '</div>'
        );
      })
      .join('');

    const premium = m.mallGrades
      .filter(([g]) => ['프리미엄', '빅파워', '브랜드스토어'].indexOf(g) !== -1)
      .reduce((a, [, c]) => a + c, 0);

    const cats = m.topCategories
      .map(
        ([cat, c]) =>
          '<div class="rf-cat-row"><span class="rf-cat-name">' + esc(cat) + '</span>' +
          '<span class="rf-cat-cnt">' + c + '개</span></div>'
      )
      .join('');

    return (
      '<div class="rf-card"><div class="rf-card-title">시장 구성 <span class="rf-chip">상위 ' + m.itemCount + '개 기준</span></div>' +
      (m.topProductCategory
        ? '<div class="rf-lead-cat"><span class="rf-lead-lab">1등 상품 카테고리</span><span class="rf-lead-val">' +
          esc(m.topProductCategory) + '</span></div>'
        : '') +
      (m.mallGrades.length
        ? '<div class="rf-kw-sec-title" style="margin-top:10px;">판매처 등급 분포' +
          (premium ? ' <span class="rf-chip">상위등급(프리미엄·빅파워·브랜드) ' + premium + '개</span>' : '') +
          '</div>' + grades
        : '') +
      (m.topCategories.length
        ? '<div class="rf-kw-sec-title" style="margin-top:12px;">주요 카테고리</div><div class="rf-cats">' + cats + '</div>'
        : '') +
      '</div>'
    );
  }

  function bindMarket(body) {
    bindRankfreeBar(body); // 랭크프리·공유 버튼
    // 수집 시작 = 캐시 허용, 다시 시도/다시 수집(↻) = 강제 새수집
    body.querySelectorAll('[data-act="collect"]').forEach((b) =>
      b.addEventListener('click', () => analyze(false))
    );
    body.querySelectorAll('[data-act="retry"]').forEach((b) =>
      b.addEventListener('click', () => analyze(true))
    );
    const openSet = body.querySelector('[data-act="open-settings"]');
    if (openSet) {
      openSet.addEventListener('click', () => {
        const gear = panelEl() && panelEl().querySelector('.rf-gear');
        if (gear) gear.click();
      });
    }
    // 저장본 보기 모드 컨트롤
    const snapClose = body.querySelector('[data-act="snap-close"]');
    if (snapClose)
      snapClose.addEventListener('click', () => {
        clearSnapshot();
        state.tab = 'history';
        render();
      });
    const snapRenew = body.querySelector('[data-act="snap-renew"]');
    if (snapRenew)
      snapRenew.addEventListener('click', () => {
        clearSnapshot();
        analyze();
      });
    // 연관 키워드 전체 복사 (줄바꿈 / 쉼표)
    body.querySelectorAll('.rf-copy').forEach((btn) =>
      btn.addEventListener('click', async (e) => {
        e.stopPropagation();
        const list = mergedRelatedKeywords();
        if (!list.length) return;
        const text = btn.dataset.copy === 'comma' ? list.join(', ') : list.join('\n');
        const ok = await copyText(text);
        const orig = btn.textContent;
        btn.textContent = ok ? '복사됨 ✓' : '복사 실패';
        btn.disabled = true;
        setTimeout(() => {
          btn.textContent = orig;
          btn.disabled = false;
        }, 1200);
      })
    );
    // 연관 키워드 칩 → 해당 키워드로 이동 + 자동 수집(명시적 분석 요청이므로)
    body.querySelectorAll('.rf-tag').forEach((btn) =>
      btn.addEventListener('click', () => {
        try {
          sessionStorage.setItem('rfPanelOpen', '1');
          sessionStorage.setItem('rfAutoCollect', '1');
        } catch (e) {
          /* noop */
        }
        location.href = location.origin + '/search/all?query=' + encodeURIComponent(btn.dataset.kw);
      })
    );
    const countSel = body.querySelector('[data-ctl="count"]');
    if (countSel)
      countSel.addEventListener('change', () => {
        state.count = num(countSel.value) || 80;
        if (state.products.length) analyze(); // 수집 전에는 범위만 기억
      });
    const adsChk = body.querySelector('[data-ctl="ads"]');
    if (adsChk)
      adsChk.addEventListener('change', () => {
        state.includeAds = adsChk.checked;
        render();
      });
    const marginInp = body.querySelector('[data-ctl="margin"]');
    if (marginInp) {
      marginInp.addEventListener('change', () => {
        const v = Math.min(90, Math.max(1, num(marginInp.value) || 30));
        state.marginPct = v;
        render();
      });
      marginInp.addEventListener('click', (e) => e.stopPropagation());
    }
  }

  // ------------------------------------------------------------------
  // 분석 실행
  // ------------------------------------------------------------------

  /** 연관 키워드만 가볍게 자동 추출 — 상품 수집은 하지 않는다 */
  async function extractKeywords() {
    if (!state.loggedIn || state.extracting || state.loading) return;
    const query = getQueryFromUrl();
    if (!query) return;

    state.query = query;
    state.extracting = true;
    render();

    // 1) 화면에 렌더된 연관검색어 스트립 → 2) 문서 SSR 데이터 → 3) 1페이지 HTML
    let tags = scrapeRelatedTagsFromDom();
    if (!tags.length && !state.urlChangedSinceLoad) {
      const dom = domNextData();
      tags = (dom && dom.relatedTags) || [];
    }
    if (!tags.length) {
      try {
        const page = await fetchHtmlPage(query, 1);
        tags = page.relatedTags || [];
      } catch (e) {
        /* 추출 실패는 조용히 — 수집 시 다시 시도됨 */
      }
    }

    if (!tags.length) logKeywordDiagnostics();

    state.relatedTags = tags;
    state.aiKeywords = scrapeAiKeywordsFromDom(); // '함께 많이 찾는'(AI 제안) — 통합검색에만 존재
    state.extracting = false;
    render();
  }

  /** 상품 수집 + 시장분석 — 사용자가 수집 버튼(또는 연관 키워드 칩)을 눌렀을 때만. force=재수집 */
  async function analyze(force) {
    if (!state.loggedIn || state.loading) return;
    const query = getQueryFromUrl();
    if (!query) {
      state.error = '검색어를 찾을 수 없습니다.';
      render();
      return;
    }

    state.query = query;
    state.loading = true;
    state.progress = null;
    state.error = null;
    state.snapshot = null;
    state.savedId = null;
    state.saveLimitMsg = null;
    render();

    // 키워드 분석(rankfree 서버)은 수집과 병렬로 — 도착 시 즉시 렌더
    const kwPromise = sendBg('keywordAnalysis', { keyword: query })
      .then((res) => {
        state.keywordData = res && res.ok && res.data ? res.data : null;
        state.keywordMsg = res && !res.ok ? res.message || null : null;
        render();
      })
      .catch(() => {
        state.keywordData = null;
        render();
      });

    try {
      const page = (await collectCached(query, state.count, force)) || { products: [], total: 0, relatedTags: [] };
      const { products, total, relatedTags } = page; // 같은 키워드 12h 캐시(수집 실패해도 throw 방지)
      state.products = (products || []).slice(0, state.count);
      state.totalCount = total;
      if (relatedTags && relatedTags.length) state.relatedTags = relatedTags;
      state.lastAnalyzedQuery = query;

      // 가격비교 상품 — 카탈로그 페이지 판매처(스마트스토어 등) 구매건수 보강
      // 매출 기여가 큰 상위만(리뷰 많은 순 15개) 동시 4요청 병렬 처리로 단축
      const catalogs = products
        .filter((p) => p.isCatalog && !p.isAd && p.id && p.purchase6m === 0)
        .sort((a, b) => b.reviewCount - a.reviewCount)
        .slice(0, 15);
      if (catalogs.length) {
        const queue = catalogs.slice();
        let done = 0;
        const worker = async () => {
          while (queue.length) {
            const c = queue.shift();
            try {
              const sale = await fetchCatalogSales(c.id);
              if (sale.purchase > 0) {
                c.purchase6m = sale.purchase;
                c.revenue6m = sale.revenue;
                c.sellerCount = sale.sellers;
              }
            } catch (e2) {
              /* 개별 카탈로그 실패는 무시 */
            }
            done++;
            state.progress = '가격비교 판매처 확인 중… (' + done + '/' + catalogs.length + ')';
            render();
          }
        };
        await Promise.all(
          Array.from({ length: Math.min(4, catalogs.length) }, worker)
        );
      }
    } catch (e) {
      state.error = String((e && e.message) || e);
      state.products = [];
    }

    await kwPromise;

    // 성공한 분석은 서버에 자동 저장 — 내역 탭과 웹 콘솔에서 다시 볼 수 있음
    if (!state.error && state.products.length) {
      await saveAnalysis();
      state.marketSavedQuery = state.query; // 상품목록 탭 자동저장과 중복 저장 방지
    }

    state.loading = false;
    state.progress = null;
    render();
  }

  // ------------------------------------------------------------------
  // 마운트 + SPA 대응
  // ------------------------------------------------------------------
  function mount() {
    if (document.getElementById(FAB_ID)) return;

    const fab = h(
      '<button type="button" id="' + FAB_ID + '" title="랭크프리 분석">' +
      '<span class="rf-fab-logo">R</span><span class="rf-fab-text">랭크프리</span></button>'
    );
    const panel = h(
      '<div id="' + PANEL_ID + '" hidden>' +
      '<div class="rf-head">' +
      '<div class="rf-brand"><button type="button" class="rf-brand-name" title="랭크프리 홈으로">랭크<b>프리</b></button><span class="rf-query"></span></div>' +
      '<div class="rf-head-btns">' +
      '<button type="button" class="rf-gear" title="설정">⚙</button>' +
      '<button type="button" class="rf-close" title="닫기">×</button>' +
      '</div>' +
      '</div>' +
      '<div class="rf-tabs"></div>' +
      '<div class="rf-body"></div>' +
      '<div class="rf-foot"></div>' +
      '</div>'
    );
    document.documentElement.appendChild(fab);
    document.documentElement.appendChild(panel);

    const openPanel = async () => {
      panel.hidden = false;
      positionPanel();
      try {
        sessionStorage.setItem('rfPanelOpen', '1');
      } catch (e) {
        /* noop */
      }
      await checkSession();
      const s = await sendBg('getSettings');
      if (s && s.ok) {
        state.apiKey = s.apiKey || '';
        state.apiBase = s.apiBase || state.apiBase;
      }
      render(); // 기본 탭(셀러력) 렌더 시 검색 상위 상품 자동 수집(ensureProducts)
      if (state.loggedIn) {
        if (consumeAutoCollect()) analyze(); // 연관 키워드 칩으로 넘어온 경우
        else extractKeywords(); // 연관 키워드(수집은 탭 render가 담당)
      }
    };
    const closePanel = () => {
      panel.hidden = true;
      state.showSettings = false;
      try {
        sessionStorage.removeItem('rfPanelOpen');
      } catch (e) {
        /* noop */
      }
    };

    // ---- FAB 드래그 이동 + 위치 저장/복원 (패널은 FAB 위치를 따라 열림) ----
    let suppressClick = false;
    // 패널을 FAB 위치 기준으로 화면 안쪽에 배치 — FAB가 오른쪽이면 우측 정렬, 아래 공간 부족하면 위로 열림
    function positionPanel() {
      const r = fab.getBoundingClientRect();
      const vw = window.innerWidth, vh = window.innerHeight;
      panel.style.width = Math.min(520, vw - 32) + 'px';
      if ((r.left + r.width / 2) > vw / 2) {
        panel.style.right = Math.max(8, vw - r.right) + 'px';
        panel.style.left = 'auto';
      } else {
        panel.style.left = Math.max(8, r.left) + 'px';
        panel.style.right = 'auto';
      }
      if (vh - r.bottom > 260) {
        panel.style.top = (r.bottom + 8) + 'px';
        panel.style.bottom = '24px';
      } else {
        panel.style.top = '24px';
        panel.style.bottom = Math.max(8, vh - r.top + 8) + 'px';
      }
    }
    function clampFab(left, top) {
      const w = fab.offsetWidth || 130, hh = fab.offsetHeight || 52;
      return {
        left: Math.min(Math.max(4, left), window.innerWidth - w - 4),
        top: Math.min(Math.max(4, top), window.innerHeight - hh - 4),
      };
    }
    function applyFabPos(pos) {
      if (!pos || typeof pos.left !== 'number' || typeof pos.top !== 'number') return;
      const c = clampFab(pos.left, pos.top);
      fab.style.left = c.left + 'px';
      fab.style.top = c.top + 'px';
      fab.style.right = 'auto';
    }
    (function makeDraggable() {
      let sx = 0, sy = 0, ox = 0, oy = 0, moved = false, dragging = false;
      const onMove = (e) => {
        if (!dragging) return;
        const dx = e.clientX - sx, dy = e.clientY - sy;
        if (!moved && Math.abs(dx) + Math.abs(dy) < 5) return; // 5px 미만은 클릭으로 간주
        moved = true;
        fab.classList.add('rf-dragging');
        const c = clampFab(ox + dx, oy + dy);
        fab.style.left = c.left + 'px';
        fab.style.top = c.top + 'px';
        fab.style.right = 'auto';
        if (!panel.hidden) positionPanel();
      };
      const onUp = () => {
        document.removeEventListener('mousemove', onMove, true);
        document.removeEventListener('mouseup', onUp, true);
        dragging = false;
        fab.classList.remove('rf-dragging');
        if (moved) {
          suppressClick = true; // 드래그 직후의 click은 토글하지 않음
          const r = fab.getBoundingClientRect();
          try { chrome.storage.local.set({ rfFabPos: { top: r.top, left: r.left } }); } catch (e) { /* noop */ }
        }
      };
      fab.addEventListener('mousedown', (e) => {
        if (e.button !== 0) return;
        const r = fab.getBoundingClientRect();
        sx = e.clientX; sy = e.clientY; ox = r.left; oy = r.top;
        moved = false; dragging = true;
        e.preventDefault();
        document.addEventListener('mousemove', onMove, true);
        document.addEventListener('mouseup', onUp, true);
      });
    })();

    fab.addEventListener('click', () => {
      if (suppressClick) { suppressClick = false; return; }
      panel.hidden ? openPanel() : closePanel();
    });
    // 저장된 FAB 위치 복원
    try {
      chrome.storage.local.get('rfFabPos', (o) => {
        if (o && o.rfFabPos) { applyFabPos(o.rfFabPos); if (!panel.hidden) positionPanel(); }
      });
    } catch (e) { /* noop */ }
    // 창 크기 변경 시 뷰포트 안으로 clamp + 패널 재배치
    window.addEventListener('resize', () => {
      const r = fab.getBoundingClientRect();
      applyFabPos({ left: r.left, top: r.top });
      if (!panel.hidden) positionPanel();
    });
    panel.querySelector('.rf-close').addEventListener('click', closePanel);
    const brandName = panel.querySelector('.rf-brand-name');
    if (brandName) brandName.addEventListener('click', () => { try { window.open(webBase() + '/', '_blank', 'noopener'); } catch (e) { /* noop */ } });
    panel.querySelector('.rf-gear').addEventListener('click', async () => {
      if (!state.loggedIn) return;
      if (!state.showSettings) {
        const s = await sendBg('getSettings');
        if (s && s.ok) {
          state.apiKey = s.apiKey || '';
          state.apiBase = s.apiBase || state.apiBase;
        }
      }
      state.showSettings = !state.showSettings;
      render();
    });

    // SPA 쿼리 변경 감지 — 자동 수집하지 않고 키워드만 새로 추출
    let lastHref = location.href;
    setInterval(() => {
      if (location.href === lastHref) return;
      lastHref = location.href;
      state.urlChangedSinceLoad = true;
      const q = getQueryFromUrl();
      if (q && q !== state.query) {
        state.query = q;
        state.keywordData = undefined;
        state.relatedTags = [];
        state.products = [];
        state.totalCount = 0;
        state.error = null;
        state.lastAnalyzedQuery = null;
        state.snapshot = null;
        state.savedId = null;
        if (!panel.hidden && state.loggedIn) extractKeywords();
        else render();
      }
    }, 800);

    state.query = getQueryFromUrl();

    // 첫 로드 SSR 데이터를 캡처 버퍼에 시드 — 페이지가 fetch를 안 해도 요청 없이 즉시 사용
    try {
      const q0 = getQueryFromUrl();
      if (q0 && !state.urlChangedSinceLoad) {
        const dom = domNextData();
        if (dom && dom.products && dom.products.length) ingestCaptured(q0, dom.products, dom.total, dom.relatedTags);
      }
    } catch (e) {
      /* noop */
    }

    // 연관 키워드 클릭 등으로 전체 페이지 이동한 경우 패널 자동 복원
    try {
      if (sessionStorage.getItem('rfPanelOpen') === '1') openPanel();
    } catch (e) {
      /* noop */
    }
  }

  function consumeAutoCollect() {
    try {
      if (sessionStorage.getItem('rfAutoCollect') === '1') {
        sessionStorage.removeItem('rfAutoCollect');
        return true;
      }
    } catch (e) {
      /* noop */
    }
    return false;
  }

  async function checkSession() {
    const res = await sendBg('session');
    state.loggedIn = Boolean(res && res.loggedIn);
    state.user = (res && res.user) || null;
  }

  /** 백그라운드 수집 모드(#rfcollect=COUNT) — 다른 탭(통합검색 등)이 이 쇼핑 페이지를 열어 수집을 위임. 패널 없이 수집만 하고 결과 회신. */
  async function runCollect() {
    const count = num((location.hash.split('=')[1]) || '') || 80;
    const query = getQueryFromUrl();
    const out = { ok: false, products: [], total: 0, relatedTags: [], message: '' };
    try {
      if (!query) throw new Error('검색어 없음');
      let page = await collectFromDocAndHtml(query, count);
      if (!page || !page.products.length) {
        await new Promise((r) => setTimeout(r, 900)); // SSR 지연 대비 1회 재시도
        page = await collectFromDocAndHtml(query, count);
      }
      if (page && page.products && page.products.length) {
        out.ok = true;
        out.products = page.products;
        out.total = page.total || 0;
        out.relatedTags = page.relatedTags || [];
      } else {
        out.message = '상품 데이터를 찾지 못했습니다.';
      }
    } catch (e) {
      out.message = String((e && e.message) || e);
    }
    try {
      chrome.runtime.sendMessage({
        type: '__shoppingCollected',
        ok: out.ok, products: out.products, total: out.total, relatedTags: out.relatedTags, message: out.message,
      });
    } catch (e) { /* noop */ }
  }

  // 백그라운드 상품분석 진행률 수신 → 로딩 화면 갱신
  try {
    chrome.runtime.onMessage.addListener((msg) => {
      if (msg && msg.type === '__reviewProgress' && state.product && state.product.loading) {
        // 재시도로 %가 되돌아가지 않게 비감소로 유지
        if (typeof msg.pct === 'number') state.product.progressPct = Math.max(state.product.progressPct || 0, msg.pct);
        render();
      }
    });
  } catch (e) { /* noop */ }

  // 상품분석 잡 상태(storage) 구독/복원 — 분석 요청 후 다른 페이지로 이동해도
  // 어느 검색 페이지에서든 진행률·결과를 이어서 보여준다(채팅 위젯처럼).
  function applyReviewJob(job) {
    if (!job) return;
    if (job.status === 'running') {
      state.tab = 'product'; // 진행 중 잡은 상품분석 탭에서 이어 보여준다
      if (!state.product.loading && !state.product.html) {
        state.product = { loading: true, error: null, html: '', targetTitle: job.title || '', targetLink: job.url || '', progressPct: job.pct || 0 };
      } else if (state.product.loading && typeof job.pct === 'number') {
        state.product.progressPct = Math.max(state.product.progressPct || 0, job.pct);
      }
      render();
      return;
    }
    if (job.status === 'done') {
      if (job.ok && job.html) {
        state.tab = 'product';
        state.product = { loading: false, error: null, html: job.html, targetTitle: job.name || job.title || '', targetLink: job.url || '', id: job.id || null, shareToken: job.share_token || '' };
      } else if (state.product.loading) {
        state.product = { loading: false, error: job.message || '상품 분석에 실패했습니다.', html: '', targetTitle: job.title || '', targetLink: job.url || '' };
      }
      try { chrome.storage.local.remove('rfReviewJob'); } catch (e) { /* noop */ } // 1회 소비
      render();
    }
  }
  try {
    chrome.storage.onChanged.addListener((changes, area) => {
      if (area === 'local' && changes.rfReviewJob) applyReviewJob(changes.rfReviewJob.newValue);
    });
    chrome.storage.local.get('rfReviewJob', (r) => {
      const job = r && r.rfReviewJob;
      if (!job) return;
      // 6분 이상 무활동 running 잡은 브라우저 종료 등 잔재 → 정리
      if (job.status === 'running' && Date.now() - (job.startedAt || 0) > 360000) {
        try { chrome.storage.local.remove('rfReviewJob'); } catch (e) { /* noop */ }
        return;
      }
      applyReviewJob(job);
    });
  } catch (e) { /* noop */ }

  if (location.hash.indexOf('#rfcollect') === 0) {
    runCollect();
  } else {
    mount();
  }
})();
