// 순위체크용 nCaptcha 토큰 발급 도구 — 로컬 PC(주거 IP)에서 실행.
//   네이버 pcmap 순위 graphql 은 브라우저 nCaptcha SDK 가 만든 x-wtm-ncaptcha-token 없으면 405/429.
//   이 도구가 브라우저로 pcmap 목록 페이지를 1회 열어 그 토큰(세션 범용·키워드무관)을 뽑아
//   `php artisan place:set-token` 으로 저장 → 이후 서버는 순수 PHP curl 로 전 키워드 순위체크 가능.
//   토큰 만료 시 이 도구만 재실행(작업 스케줄러/크론 주기 실행 가능).
//
//   실행:  node scripts/ncaptcha-token.cjs
//   의존:  playwright — 없으면 RANKFREE_PLAYWRIGHT 로 경로 지정(예: 형제 프로젝트 node_modules/playwright)
//   환경변수(선택):
//     RANKFREE_PLAYWRIGHT  playwright 모듈 경로 (기본: 로컬 node_modules → 실패 시 안내)
//     RANKFREE_PHP         php 실행 파일 경로 (기본: 'php')
//     RANKFREE_ARTISAN     artisan 경로 (기본: <이 스크립트>/../artisan)

const path = require('path');
const { execFileSync } = require('child_process');

const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36';
const CAT = 'hairshop';
const SEED_KW = '홍성미용실'; // 발급용 아무 검색(토큰은 세션 범용)

function log(...a) { console.log('[' + new Date().toISOString() + ']', ...a); }

function loadPlaywright() {
    const candidates = [
        process.env.RANKFREE_PLAYWRIGHT,
        'playwright',
        path.join(__dirname, '..', 'node_modules', 'playwright'),
    ].filter(Boolean);
    for (const c of candidates) {
        try { return require(c); } catch (e) { /* next */ }
    }
    log('❌ playwright 를 찾지 못했습니다. `npm i -D playwright` 후 재실행하거나 RANKFREE_PLAYWRIGHT 로 경로를 지정하세요.');
    process.exit(10);
}

function findItems(json) {
    for (const root of (Array.isArray(json) ? json : [json])) {
        const d = root && root.data;
        if (!d) continue;
        for (const k of Object.keys(d)) {
            const n = d[k];
            if (n && Array.isArray(n.items) && n.items[0] && ('id' in n.items[0])) return { key: k, items: n.items };
        }
    }
    return null;
}

(async () => {
    const { chromium } = loadPlaywright();
    const browser = await chromium.launch({ headless: true, args: ['--disable-blink-features=AutomationControlled'] });
    const ctx = await browser.newContext({ userAgent: UA, viewport: { width: 1400, height: 1000 }, locale: 'ko-KR' });
    await ctx.addInitScript(() => Object.defineProperty(navigator, 'webdriver', { get: () => undefined }));
    const page = await ctx.newPage();

    const reqMap = new Map();
    page.on('request', req => { if (/pcmap-api.*graphql/.test(req.url())) reqMap.set((req.postData() || '').slice(0, 120), { headers: req.headers() }); });
    let hit = null;
    page.on('response', async res => {
        if (hit || !/pcmap-api.*graphql/.test(res.url()) || res.status() !== 200) return;
        let j; try { j = JSON.parse(await res.text()); } catch (e) { return; }
        const f = findItems(j); if (!f || /adBusiness/i.test(f.key)) return;
        const pd = res.request().postData() || '';
        hit = { headers: (reqMap.get(pd.slice(0, 120)) || { headers: res.request().headers() }).headers };
    });

    const url = `https://pcmap.place.naver.com/${CAT}/list?query=${encodeURIComponent(SEED_KW)}`;
    log('토큰 발급용 페이지 로드:', url);
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(e => log('goto', e.message));
    await page.waitForTimeout(4000);
    for (let i = 0; i < 4 && !hit; i++) { await page.mouse.wheel(0, 900).catch(() => {}); await page.waitForTimeout(1500); }
    if (!hit) { const n = await page.$('a.mBN2s:has-text("2")').catch(() => null); if (n) { await n.click().catch(() => {}); await page.waitForTimeout(3500); } }
    await browser.close();

    if (!hit) { log('❌ 토큰 캡처 실패(리스트 응답 못 잡음). 잠시 후 재시도.'); process.exit(1); }
    const nctoken = hit.headers['x-wtm-ncaptcha-token'] || '';
    if (!nctoken) { log('❌ x-wtm-ncaptcha-token 없음'); process.exit(2); }
    log('토큰 확보 len=' + nctoken.length + ' → 저장 중…');

    const php = process.env.RANKFREE_PHP || 'php';
    const artisan = process.env.RANKFREE_ARTISAN || path.join(__dirname, '..', 'artisan');
    try {
        const out = execFileSync(php, [artisan, 'place:set-token', nctoken], { encoding: 'utf8' });
        log(out.trim());
        process.exit(0);
    } catch (e) {
        log('❌ artisan 저장 실패:', e.message);
        log('   토큰을 직접 저장하려면:  ' + php + ' artisan place:set-token "' + nctoken + '"');
        process.exit(3);
    }
})();
