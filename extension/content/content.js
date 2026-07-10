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
    tab: 'market',
    pages: 2, // 80개 × N페이지
    includeAds: false,
    marginPct: 30,
    loading: false,
    error: null,
    products: [], // 정규화된 상품 목록 (광고 포함)
    totalCount: 0, // 검색결과 전체 상품수
    keywordData: undefined, // undefined=미조회, null=없음, object=데이터
    lastAnalyzedQuery: null,
  };

  // 페이지가 쓰는 nCaptcha 토큰 가로채기 (injected.js → CustomEvent)
  document.addEventListener('rankfree:ncaptcha-token', (e) => {
    if (e && e.detail) state.ncaptchaToken = String(e.detail);
  });

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
      const p = new URL(location.href).searchParams;
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
  // ------------------------------------------------------------------
  function normalizeItem(raw, fallbackRank) {
    if (!raw) return null;
    const item = raw.item || raw; // __NEXT_DATA__ 는 {item:{...}} 형태
    const price = num(item.price || item.lowPrice || item.mobileLowPrice);
    const title = stripTags(item.productTitle || item.productName || item.title || '');
    if (!title && !price) return null;
    return {
      id: item.id || item.nvMid || null,
      rank: num(item.rank) || fallbackRank,
      title,
      price,
      purchase6m: num(item.purchaseCnt),
      reviewCount: num(item.reviewCount),
      keepCount: num(item.keepCnt),
      mallName: item.mallName || (item.lowMallList && item.lowMallList[0] && item.lowMallList[0].name) || '',
      mallCount: num(item.mallCount),
      brand: item.brand || item.maker || '',
      category: [item.category1Name, item.category2Name, item.category3Name, item.category4Name]
        .filter(Boolean)
        .join(' > '),
      openDate: item.openDate ? String(item.openDate).slice(0, 8) : '',
      isAd: Boolean(item.adId || item.adcrUrl || raw.adId),
      link: item.mallProductUrl || item.crUrl || item.productUrl || '',
    };
  }

  function buildApiUrl(query, pageIndex) {
    const q = encodeURIComponent(query);
    return (
      location.origin +
      '/api/search/all?sort=rel' +
      '&pagingIndex=' + pageIndex +
      '&pagingSize=80' +
      '&viewType=list&productSet=total' +
      '&query=' + q + '&origQuery=' + q + '&adQuery=' + q +
      '&iq=&eq=&xq='
    );
  }

  async function fetchApiPage(query, pageIndex) {
    const headers = {
      accept: 'application/json, text/plain, */*',
      logic: 'PART',
    };
    if (state.ncaptchaToken) headers['x-wtm-ncaptcha-token'] = state.ncaptchaToken;

    const res = await fetch(buildApiUrl(query, pageIndex), {
      method: 'GET',
      credentials: 'include',
      headers,
    });
    if (!res.ok) {
      const err = new Error('네이버 API 응답 오류 (' + res.status + ')');
      err.status = res.status;
      throw err;
    }
    const json = await res.json();
    const sr = json.shoppingResult || json;
    return {
      total: num(sr.total),
      products: Array.isArray(sr.products) ? sr.products : [],
    };
  }

  /** API 실패 시 폴백 — 페이지에 이미 렌더된 __NEXT_DATA__(1페이지 분량) */
  function parseNextData() {
    try {
      const el = document.getElementById('__NEXT_DATA__');
      if (!el) return null;
      const data = JSON.parse(el.textContent);
      const st =
        data && data.props && data.props.pageProps && data.props.pageProps.initialState;
      if (!st || !st.products) return null;
      const list = Array.isArray(st.products.list) ? st.products.list : [];
      return {
        total: num(st.products.total),
        products: list,
      };
    } catch (e) {
      return null;
    }
  }

  async function collectProducts(query, pages) {
    const all = [];
    let total = 0;
    let apiFailed = false;

    for (let i = 1; i <= pages; i++) {
      try {
        const page = await fetchApiPage(query, i);
        total = page.total || total;
        for (const raw of page.products) {
          const it = normalizeItem(raw, all.length + 1);
          if (it) all.push(it);
        }
        if (page.products.length < 80) break; // 마지막 페이지
      } catch (e) {
        apiFailed = true;
        break;
      }
      if (i < pages) await new Promise((r) => setTimeout(r, 500)); // 과호출 방지
    }

    // API가 전부 실패했으면 __NEXT_DATA__ 폴백 (1페이지 분량)
    if (!all.length) {
      const nd = parseNextData();
      if (nd) {
        total = nd.total || total;
        for (const raw of nd.products) {
          const it = normalizeItem(raw, all.length + 1);
          if (it) all.push(it);
        }
      }
    }

    if (!all.length) {
      throw new Error(
        apiFailed
          ? '네이버 쇼핑 데이터를 불러오지 못했습니다. 페이지를 새로고침한 뒤 다시 시도해 주세요.'
          : '분석할 상품이 없습니다.'
      );
    }
    return { products: all, total };
  }

  // ------------------------------------------------------------------
  // 시장 분석 계산
  // ------------------------------------------------------------------
  function computeMarket(products, includeAds) {
    const items = includeAds ? products : products.filter((p) => !p.isAd);
    const withSales = items.filter((p) => p.price > 0);

    const sales6m = withSales.reduce((a, p) => a + p.purchase6m, 0);
    const revenue6m = withSales.reduce((a, p) => a + p.purchase6m * p.price, 0);
    const prices = withSales.map((p) => p.price).filter((v) => v > 0);
    const avgPrice = prices.length ? prices.reduce((a, b) => a + b, 0) / prices.length : 0;

    const byRevenue = withSales
      .slice()
      .sort((a, b) => b.purchase6m * b.price - a.purchase6m * a.price);
    const top10Revenue = byRevenue.slice(0, 10).reduce((a, p) => a + p.purchase6m * p.price, 0);

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

    panel.querySelector('.rf-query').textContent = state.query ? '“' + state.query + '”' : '';

    if (!state.loggedIn) {
      panel.querySelector('.rf-tabs').style.display = 'none';
      body.innerHTML = loginHtml();
      foot.innerHTML = '';
      bindLogin(body);
      return;
    }

    panel.querySelector('.rf-tabs').style.display = '';
    renderTabs(panel);

    if (state.tab === 'market') {
      body.innerHTML = marketHtml();
      bindMarket(body);
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

  const TABS = [
    { key: 'market', label: '시장 분석', ready: true },
    { key: 'rank', label: '순위 추적', ready: false },
    { key: 'review', label: '리뷰 분석', ready: false },
  ];

  function renderTabs(panel) {
    const wrap = panel.querySelector('.rf-tabs');
    wrap.innerHTML = TABS.map(
      (t) =>
        '<button type="button" class="rf-tab' +
        (state.tab === t.key ? ' is-active' : '') +
        '" data-tab="' + t.key + '">' +
        esc(t.label) +
        (t.ready ? '' : '<span class="rf-tab-soon">곧</span>') +
        '</button>'
    ).join('');
    wrap.querySelectorAll('.rf-tab').forEach((btn) => {
      btn.addEventListener('click', () => {
        state.tab = btn.dataset.tab;
        render();
      });
    });
  }

  // ---------- 로그인 ----------
  function loginHtml() {
    return (
      '<div class="rf-login">' +
      '<h3>RankFree 로그인</h3>' +
      '<p class="rf-muted">시장 분석은 rankfree.kr 회원만 이용할 수 있습니다.</p>' +
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
        analyze(); // 로그인 직후 바로 분석
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

  // ---------- 시장 분석 ----------
  function statTile(label, value, sub) {
    return (
      '<div class="rf-stat"><div class="rf-stat-label">' + esc(label) + '</div>' +
      '<div class="rf-stat-value">' + value + '</div>' +
      (sub ? '<div class="rf-stat-sub">' + sub + '</div>' : '') +
      '</div>'
    );
  }

  function keywordCardHtml() {
    if (state.keywordData === undefined) return ''; // 아직 조회 안 함
    if (state.keywordData === null) {
      return (
        '<div class="rf-card rf-kw"><div class="rf-card-title">키워드 분석</div>' +
        '<p class="rf-muted">이 키워드의 검색량 데이터를 가져오지 못했습니다.</p></div>'
      );
    }
    const k = state.keywordData;
    const total = num(k.monthly_total);
    const ratio = total > 0 && state.totalCount > 0 ? state.totalCount / total : null;
    return (
      '<div class="rf-card rf-kw">' +
      '<div class="rf-card-title">키워드 분석 <span class="rf-chip">네이버 검색광고 기준</span></div>' +
      '<div class="rf-stats rf-stats-4">' +
      statTile('월간 검색량', comma(total), 'PC ' + comma(num(k.monthly_pc)) + ' · 모바일 ' + comma(num(k.monthly_mobile))) +
      statTile('경쟁 강도', esc(k.comp_idx || '-')) +
      statTile('전체 상품수', comma(state.totalCount)) +
      statTile('상품수/검색량', ratio == null ? '-' : ratio.toFixed(2), ratio == null ? '' : '낮을수록 기회') +
      '</div></div>'
    );
  }

  function marketHtml() {
    if (state.loading) {
      return '<div class="rf-loading"><div class="rf-spinner"></div>상품 데이터를 수집하고 있습니다…</div>';
    }
    if (state.error) {
      return (
        '<div class="rf-error">' + esc(state.error) + '</div>' +
        '<button type="button" class="rf-btn-primary" data-act="retry">다시 시도</button>'
      );
    }
    if (!state.products.length) {
      return (
        '<div class="rf-empty"><p>아직 분석 결과가 없습니다.</p></div>' +
        '<button type="button" class="rf-btn-primary" data-act="retry">지금 분석하기</button>'
      );
    }

    const m = computeMarket(state.products, state.includeAds);
    const monthlyProfit = m.monthlyRevenue * (state.marginPct / 100);

    const controls =
      '<div class="rf-controls">' +
      '<label>수집 범위 <select class="rf-select" data-ctl="pages">' +
      [1, 2, 3, 5]
        .map(
          (p) =>
            '<option value="' + p + '"' + (state.pages === p ? ' selected' : '') + '>상위 ' + p * 80 + '개</option>'
        )
        .join('') +
      '</select></label>' +
      '<label class="rf-check"><input type="checkbox" data-ctl="ads"' + (state.includeAds ? ' checked' : '') + '> 광고 포함</label>' +
      '<button type="button" class="rf-btn-ghost" data-act="retry" title="다시 수집">↻</button>' +
      '</div>';

    const stats =
      '<div class="rf-card"><div class="rf-card-title">시장 규모 <span class="rf-chip">최근 6개월 · 상위 ' + m.itemCount + '개 기준</span></div>' +
      '<div class="rf-stats">' +
      statTile('6개월 시장 규모', krw(m.revenue6m) + '원', '판매량 × 판매가 합산') +
      statTile('월평균 매출', krw(m.monthlyRevenue) + '원') +
      statTile('6개월 판매량', comma(m.sales6m) + '건', '월평균 ' + comma(m.monthlySales) + '건') +
      statTile('평균 판매가', comma(m.avgPrice) + '원', '중앙값 ' + comma(m.medianPrice) + '원') +
      statTile('상위 10개 점유율', m.top10Share.toFixed(1) + '%', '매출 기준') +
      statTile('월 예상 수익', krw(monthlyProfit) + '원',
        '마진율 <input type="number" class="rf-margin" data-ctl="margin" min="1" max="90" value="' + state.marginPct + '">%') +
      '</div>' +
      '<p class="rf-note">* 구매건수는 네이버 노출값(최근 6개월)이며, 시장 규모는 rankfree 자체 추정치입니다.</p>' +
      '</div>';

    const rows = m.topProducts
      .map((p, i) => {
        const rev = p.purchase6m * p.price;
        return (
          '<tr>' +
          '<td class="rf-td-rank">' + (i + 1) + '</td>' +
          '<td class="rf-td-title"><a href="' + esc(p.link) + '" target="_blank" rel="noopener" title="' + esc(p.title) + '">' +
          esc(p.title.length > 34 ? p.title.slice(0, 34) + '…' : p.title) + '</a>' +
          (p.isAd ? ' <span class="rf-ad">AD</span>' : '') +
          '<div class="rf-td-mall">' + esc(p.mallName || p.brand || '') + '</div></td>' +
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

    return controls + keywordCardHtml() + stats + table;
  }

  function bindMarket(body) {
    body.querySelectorAll('[data-act="retry"]').forEach((b) =>
      b.addEventListener('click', () => analyze(true))
    );
    const pagesSel = body.querySelector('[data-ctl="pages"]');
    if (pagesSel)
      pagesSel.addEventListener('change', () => {
        state.pages = num(pagesSel.value) || 2;
        analyze(true);
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
  async function analyze(force) {
    if (!state.loggedIn || state.loading) return;
    const query = getQueryFromUrl();
    if (!query) {
      state.error = '검색어를 찾을 수 없습니다.';
      render();
      return;
    }
    if (!force && state.lastAnalyzedQuery === query && state.products.length) return;

    state.query = query;
    state.loading = true;
    state.error = null;
    render();

    // 키워드 분석(rankfree 서버)은 수집과 병렬로
    const kwPromise = sendBg('keywordAnalysis', { keyword: query })
      .then((res) => {
        state.keywordData = res && res.ok && res.data ? res.data : null;
      })
      .catch(() => {
        state.keywordData = null;
      });

    try {
      const { products, total } = await collectProducts(query, state.pages);
      state.products = products;
      state.totalCount = total;
      state.lastAnalyzedQuery = query;
    } catch (e) {
      state.error = String((e && e.message) || e);
      state.products = [];
    }

    await kwPromise;
    state.loading = false;
    render();
  }

  // ------------------------------------------------------------------
  // 마운트 + SPA 대응
  // ------------------------------------------------------------------
  function mount() {
    if (document.getElementById(FAB_ID)) return;

    const fab = h(
      '<button type="button" id="' + FAB_ID + '" title="RankFree 시장분석">' +
      '<span class="rf-fab-logo">R</span></button>'
    );
    const panel = h(
      '<div id="' + PANEL_ID + '" hidden>' +
      '<div class="rf-head">' +
      '<div class="rf-brand">Rank<b>Free</b><span class="rf-query"></span></div>' +
      '<button type="button" class="rf-close" title="닫기">×</button>' +
      '</div>' +
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
        await checkSession();
        render();
        if (state.loggedIn) analyze();
      }
    });
    panel.querySelector('.rf-close').addEventListener('click', () => {
      panel.hidden = true;
    });

    // SPA 쿼리 변경 감지
    let lastHref = location.href;
    setInterval(() => {
      if (location.href === lastHref) return;
      lastHref = location.href;
      const q = getQueryFromUrl();
      if (q && q !== state.query) {
        state.query = q;
        state.keywordData = undefined;
        if (!panel.hidden && state.loggedIn) analyze(true);
        else render();
      }
    }, 800);

    state.query = getQueryFromUrl();
  }

  async function checkSession() {
    const res = await sendBg('session');
    state.loggedIn = Boolean(res && res.loggedIn);
    state.user = (res && res.user) || null;
  }

  mount();
})();
