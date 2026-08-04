// 네이버 쇼핑 검색결과 다중 페이지 수집 — 서버 실행용.
//
//   왜 브라우저인가(2026-08-04 실측):
//     · 1페이지는 ns-portal `/api/v2/shopping-paged-slot` 으로 curl 도 200 이지만 **page 파라미터를 무시**한다.
//       (page=1·2·5·10 모두 같은 응답, 응답의 page 는 항상 1, pageSize 상한 20)
//     · 2페이지부터는 `search.shopping.naver.com/ns/v1/search/paged-composite-cards` 를 쓰고
//       `x-wtm-ncaptcha-token` 이 필수다. 그런데 이 호스트는 **순수 curl 을 전부 418 로 차단**한다
//       (쿠키·토큰·헤더를 그대로 붙여도 418 — TLS 지문 기반 차단).
//     → 따라서 실제 브라우저(Playwright/chromium)로만 2페이지 이상을 수집할 수 있다.
//
//   쿠키/세션: persistent profile 에 유지된다. 만료되면 `--headful` 로 1회 열어 갱신(크론으로 주기 실행 가능).
//
//   실행:
//     node scripts/naver-shop-serp.cjs --query 여름브라 --pages 5 --out-file storage/app/shop-serp/여름브라.json
//   옵션:
//     --query <검색어>       (필수)
//     --pages <N>            수집 깊이 — 구 UI "80개씩 보기" 기준 페이지 수 (5 = 400위, 기본 3)
//     --out-file <path>      결과 JSON 저장 경로 (없으면 stdout 으로만 출력)
//     --profile <path>       브라우저 프로필 디렉터리 (기본 scripts/.naver-shop-profile)
//     --headful              창을 띄운다(최초 세션 확보·디버깅용)
//     --timeout <ms>         전체 제한시간 (기본 120000)
//   환경변수:
//     RANKFREE_PLAYWRIGHT    playwright 모듈 경로

const path = require('path');
const fs = require('fs');

// (UA 는 프로필 Chrome 의 실제 값을 그대로 쓴다 — 덮어쓰면 418)

// 깊이 기준 — 구 UI "80개씩 보기" 1페이지. 네이버 신 UI 의 내부 페이지는 30~50개로 들쭉날쭉해
// 페이지 수로 세면 순위 깊이가 키워드마다 달라진다(실측: 5페이지가 166위). 그래서 개수로 센다.
const PAGE_SIZE = 80;

function log(...a) { console.error('[' + new Date().toISOString() + ']', ...a); }

function parseArgs(argv) {
    const o = { pages: 3, timeout: 120000, headful: false };
    for (let i = 2; i < argv.length; i++) {
        const a = argv[i];
        if (a === '--query') o.query = argv[++i];
        else if (a === '--pages') o.pages = Math.max(1, parseInt(argv[++i], 10) || 3);
        else if (a === '--out-file') o.outFile = argv[++i];
        else if (a === '--profile') o.profile = argv[++i];
        else if (a === '--timeout') o.timeout = parseInt(argv[++i], 10) || 120000;
        else if (a === '--headful') o.headful = true;
    }
    return o;
}

function loadPlaywright() {
    const cands = [process.env.RANKFREE_PLAYWRIGHT, 'playwright', path.join(__dirname, '..', 'node_modules', 'playwright')].filter(Boolean);
    for (const c of cands) {
        try { return require(c); } catch (e) { /* next */ }
    }
    log('playwright 를 찾지 못했습니다. npm i -D playwright 또는 RANKFREE_PLAYWRIGHT 로 경로를 지정하세요.');
    process.exit(10);
}

/** 두 API 의 상품 객체를 하나의 스키마로 — 필드가 없으면 null 로 둔다(추측해 채우지 않는다). */
function normalize(p, page, seq) {
    if (!p || typeof p !== 'object') return null;
    const img = Array.isArray(p.images) && p.images[0] ? p.images[0].imageUrl : null;
    const url = p.productUrl && (p.productUrl.pcUrl || p.productUrl.mobileUrl) || null;
    const ad = (p.sourceType && p.sourceType !== 'SAS') || p.cardType === 'AD' || p.cardType === 'SUPER_POINT_CARD';
    return {
        page,
        seq,                                   // 수집 순서(1부터) — 광고 포함
        apiRank: p.rank ?? null,               // 1페이지 API 에만 있다
        isAd: !!ad,
        sourceType: p.sourceType ?? null,
        cardType: p.cardType ?? null,
        nvMid: p.nvMid != null ? String(p.nvMid) : null,
        channelProductId: p.channelProductId != null ? String(p.channelProductId) : null,
        productName: typeof p.productName === 'string' ? p.productName.replace(/<[^>]*>/g, '').trim() : null,
        mallName: p.mallName ?? null,
        salePrice: p.salePrice ?? null,
        discountedSalePrice: p.discountedSalePrice ?? null,
        discountedRatio: p.discountedRatio ?? null,
        averageReviewScore: p.averageReviewScore ?? null,
        totalReviewCount: p.totalReviewCount ?? null,
        purchaseCount: p.purchaseCount ?? null,
        keepCount: p.keepCount ?? null,
        isSmartStoreProduct: p.isSmartStoreProduct ?? null,
        isBrandStore: p.isBrandStore ?? null,
        imageUrl: img,
        productUrl: url,
    };
}

(async () => {
    const opt = parseArgs(process.argv);
    if (!opt.query) { log('--query 는 필수입니다.'); process.exit(2); }

    const profile = opt.profile || process.env.SHOP_RANK_PROFILE || path.join(__dirname, '.naver-shop-profile');
    const { chromium } = loadPlaywright();

    const deadline = Date.now() + opt.timeout;
    const items = [];
    const seenNvMid = new Set();
    const pagesSeen = new Set();
    let total = null, lastCursor = null, hasMore = null;

    // ⚠️ 항상 headful 이어야 한다 — headless 는 `--headless=new` 까지 전부 418 로 차단된다(실측 2026-08-04).
    //    리눅스 서버에서는 이 프로세스를 xvfb-run 으로 감싼다(config: shopping.server_collect.xvfb).
    // userAgent 도 덮어쓰지 않는다 — 설치된 Chrome 의 실제 UA 와 어긋나면 즉시 418.
    const ctx = await chromium.launchPersistentContext(profile, {
        headless: false,
        locale: 'ko-KR',
        viewport: { width: 1440, height: 900 },
        args: ['--disable-blink-features=AutomationControlled', '--no-sandbox'],
    });
    const page = ctx.pages()[0] || await ctx.newPage();

    /** 응답의 data 를 목록에 담는다(중복 nvMid 제외). 리스너·커서 직접호출이 같은 경로를 쓴다. */
    function absorb(d) {
        if (!d || !Array.isArray(d.data)) return 0;
        if (d.total != null) total = d.total;
        if (d.cursor != null) lastCursor = d.cursor;
        if (d.hasMore != null) hasMore = d.hasMore;
        let added = 0;
        for (const it of d.data) {
            const prod = it && it.card && it.card.product;
            const n = normalize(prod, it.page ?? null, items.length + 1);
            if (!n || !n.nvMid || seenNvMid.has(n.nvMid)) continue;
            seenNvMid.add(n.nvMid);
            items.push(n);
            added++;
            if (n.page != null) pagesSeen.add(n.page);
        }
        return added;
    }

    // 2페이지 이후는 이 응답으로 들어온다. 부트스트랩(버튼 1회 클릭) 동안만 켜 두고,
    // 이후 커서 직접호출 구간에서는 끈다 — 켜 두면 같은 응답을 두 번 담으려다 진행 판정이 꼬인다.
    let listenerOn = true;
    let lastReq = null;      // 커서 직접호출에 쓸 요청 형태(URL·토큰 헤더)
    page.on('request', (req) => {
        if (req.url().includes('paged-composite-cards')) lastReq = { url: req.url(), headers: req.headers() };
    });
    page.on('response', async (res) => {
        if (!listenerOn || !res.url().includes('paged-composite-cards')) return;
        try { absorb((await res.json())?.data); } catch (e) { /* 파싱 실패는 무시 */ }
    });

    // ⚠️ 검색 전에 쇼핑 홈을 먼저 거친다 — nstore_session 은 **세션 쿠키**라 브라우저를 새로 띄우면 사라지고,
    //    홈을 방문해야 재발급된다. 바로 검색으로 가면 로그인이 살아 있어도 405 다(실측 2026-08-04).
    await page.goto('https://shopping.naver.com/ns/home', { waitUntil: 'domcontentloaded', timeout: 45000 }).catch(() => {});
    await page.waitForTimeout(2500);

    const target = 'https://search.shopping.naver.com/ns/search?query=' + encodeURIComponent(opt.query);
    log('열기:', target);
    const resp = await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch((e) => { log('goto 실패:', e.message); return null; });
    const status = resp ? resp.status() : 0;
    log('status =', status);
    // 405 = 로그인 세션 없음 / 418·429 = 차단. 어느 쪽이든 shoprank:login 으로 세션을 다시 심어야 한다.
    if (status === 405 || status === 418 || status === 429) {
        log('차단(status=' + status + ') — 프로필 세션이 만료됐습니다. artisan shoprank:login 으로 갱신하세요.');
        await ctx.close();
        console.log(JSON.stringify({ ok: false, blocked: true, status, query: opt.query, items: [] }));
        process.exit(3);
    }
    await page.waitForTimeout(2000);

    // 1페이지 — ns-portal slot API (토큰 불필요). 페이지 컨텍스트에서 부르면 418 을 피한다.
    try {
        const first = await page.evaluate(async (q) => {
            const u = 'https://ns-portal.shopping.naver.com/api/v2/shopping-paged-slot?query=' + encodeURIComponent(q) + '&source=shp_gui&pageSize=20';
            const r = await fetch(u, { credentials: 'include' });
            return r.ok ? await r.json() : null;
        }, opt.query);
        const slots = first && first.data && first.data[0] && first.data[0].slots || [];
        for (const s of slots) {
            const n = normalize(s.data, 1, items.length + 1);
            if (!n || !n.nvMid || seenNvMid.has(n.nvMid)) continue;
            seenNvMid.add(n.nvMid);
            items.push(n);
            pagesSeen.add(1);
        }
        log('1페이지 수집:', slots.length, '개');
    } catch (e) { log('1페이지 수집 실패:', e.message); }

    // 2페이지 이후 — "다음 리스트 보기" 클릭 + 스크롤로 유도하고 응답 리스너가 담는다
    // 버튼은 목록 맨 아래에 lazy 로 붙는다 — 매 회차 바닥까지 내린 뒤 찾는다.
    const NEXT_SELECTORS = ['button:has-text("다음 리스트")', 'a:has-text("다음 리스트")', 'text=다음 리스트 보기'];
    const targetItems = opt.pages * PAGE_SIZE;      // --pages 5 → 400개(=400위)까지

    // ⏱ 고정 대기 대신 **일이 끝나는 즉시** 넘어간다 — 페이지당 4.4초 고정이 실제 소요(약 1초)의 4배였다.
    /** 조건이 참이 될 때까지 100ms 간격 폴링. 참이 되면 즉시 true. */
    const until = async (fn, ms) => {
        const end = Date.now() + ms;
        while (Date.now() < end) {
            if (await fn()) return true;
            await page.waitForTimeout(100);
        }
        return false;
    };

    // (1) 부트스트랩 — 요청 형태(커서 URL + nCaptcha 토큰)를 얻으려고 버튼을 **한 번만** 누른다.
    for (let i = 0; i < 3 && !lastReq && Date.now() < deadline; i++) {
        await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight)).catch(() => {});
        await page.waitForTimeout(1200);            // 버튼이 목록 맨 아래에 lazy 로 붙을 시간

        let btn = null;
        for (const sel of NEXT_SELECTORS) {
            const l = page.locator(sel).first();
            if (await l.count().catch(() => 0)) { btn = l; break; }
        }
        if (btn) {
            await btn.scrollIntoViewIfNeeded({ timeout: 3000 }).catch(() => {});
            await btn.click({ timeout: 5000 }).catch(() => {});
        } else {
            log('버튼 못 찾음 — 스크롤로 유도');
            await page.mouse.wheel(0, 12000).catch(() => {});
        }
        await until(async () => !!lastReq, 5000);
    }
    await until(async () => items.length > 20, 2000);   // 부트스트랩 응답이 담길 여유
    listenerOn = false;
    if (!lastReq) log('커서 요청을 확보하지 못했습니다 — 1페이지만 수집됩니다.');

    // (2) 이후는 커서만 올려 페이지 컨텍스트에서 직접 호출한다.
    //   · 버튼 클릭 방식은 네이버 앱이 4초 간격으로만 받아줘 페이지당 5초가 걸렸다(실측 2026-08-04)
    //   · 직접 호출은 0.25초. 토큰은 재사용이 통하고, pageSize 는 50 을 넘기면 418 이다(실측)
    const HDR = ['x-wtm-ncaptcha-token', 'x-nstore-pagesession-id', 'content-type', 'accept-language', 'referer'];
    while (lastReq && items.length < targetItems && hasMore !== false && Date.now() < deadline) {
        const u = new URL(lastReq.url);
        u.searchParams.set('cursor', String(lastCursor != null ? lastCursor : items.length));
        u.searchParams.set('pageSize', '50');
        u.searchParams.delete('duplicatedNvMids');   // 커서가 올라가면 의미 없는 목록 — URL 만 길어진다
        const headers = {};
        for (const k of HDR) if (lastReq.headers[k]) headers[k] = lastReq.headers[k];

        const r = await page.evaluate(async ({ url, headers }) => {
            try {
                const res = await fetch(url, { headers, credentials: 'include' });
                return { status: res.status, json: res.ok ? await res.json() : null };
            } catch (e) { return { status: -1, json: null }; }
        }, { url: u.toString(), headers });

        if (r.status !== 200 || !r.json) { log('커서 호출 중단 status=' + r.status); break; }
        const added = absorb(r.json.data);
        log('누적', items.length, '/', targetItems, '개');
        if (!added) break;                          // 더 줄 게 없다
        await page.waitForTimeout(250);             // 페이싱 — 과속으로 막히지 않게
    }

    await ctx.close();

    const out = {
        ok: items.length > 0,
        query: opt.query,
        requestedPages: opt.pages,
        collectedPages: [...pagesSeen].sort((a, b) => a - b),
        total,                       // 네이버가 보고한 전체 상품 수
        lastCursor,
        hasMore,
        count: items.length,
        items,
        collectedAt: new Date().toISOString(),
    };
    if (opt.outFile) {
        fs.mkdirSync(path.dirname(opt.outFile), { recursive: true });
        fs.writeFileSync(opt.outFile, JSON.stringify(out, null, 1), 'utf8');
        log('저장:', opt.outFile);
    }
    console.log(JSON.stringify(out));
    process.exit(out.ok ? 0 : 4);
})().catch((e) => { log('실패:', e && e.stack || e); process.exit(1); });
