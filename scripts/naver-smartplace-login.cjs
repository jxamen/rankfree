// 네이버 스마트플레이스 자동 로그인 → 세션 쿠키를 JSON 으로 stdout 출력.
//   호출: SmartplaceLoginService 가 env(SP_LOGIN_ID/PW, SP_PLACE_SEQ, RANKFREE_PLAYWRIGHT) 주입 후 실행.
//   출력(마지막 줄): {"ok":true,"cookie":"name=value; ..."}  또는  {"ok":false,"reason":"..."}
//   ⚠️ 쿠키는 민감정보 — 호출한 서비스가 즉시 암호화 저장하고 로그로 남기지 않는다.
//   원본: crm ads/smartplace/sp_auto_runner.mjs (로그인 상태 유지 + deviceConfirm 처리 + 세션 보강)
const path = require('path');

function loadPlaywright() {
    const cands = [process.env.RANKFREE_PLAYWRIGHT, 'playwright', path.join(__dirname, '..', 'node_modules', 'playwright')].filter(Boolean);
    for (const c of cands) { try { return require(c); } catch (e) { /* next */ } }
    console.log(JSON.stringify({ ok: false, reason: 'playwright_not_found' }));
    process.exit(10);
}

(async () => {
    const ID = process.env.SP_LOGIN_ID, PW = process.env.SP_LOGIN_PW;
    const PLACE_SEQ = process.env.SP_PLACE_SEQ || '';
    if (!ID || !PW) { console.log(JSON.stringify({ ok: false, reason: 'no_credentials' })); process.exit(2); }
    const UA = process.env.SP_LOGIN_UA || 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36';
    const { chromium } = loadPlaywright();
    let browser;
    try {
        browser = await chromium.launch({ headless: true, args: ['--disable-blink-features=AutomationControlled', '--no-sandbox'] });
    } catch (e) {
        // 브라우저 바이너리 미설치 등 — JSON 으로 사유 보고(무출력 방지)
        console.log(JSON.stringify({ ok: false, reason: 'browser_launch_failed: ' + String((e && e.message) || e) }));
        process.exit(6);
    }
    try {
        const ctx = await browser.newContext({ userAgent: UA, viewport: { width: 1280, height: 900 }, locale: 'ko-KR' });
        await ctx.addInitScript(() => Object.defineProperty(navigator, 'webdriver', { get: () => undefined }));
        const page = await ctx.newPage();

        await page.goto('https://nid.naver.com/nidlogin.login?mode=form', { waitUntil: 'domcontentloaded', timeout: 30000 });
        await page.waitForTimeout(900);
        await page.click('#id'); await page.type('#id', ID, { delay: 60 });
        await page.click('#pw'); await page.type('#pw', PW, { delay: 60 });
        // 로그인 상태 유지 ON — 미체크 세션은 하루 내 만료됨(원본 실측)
        await page.check('#keep').catch(() => page.click('label[for="keep"]').catch(() => {}));
        await page.click('#log\\.login').catch(() => page.click('.btn_login, button[type=submit]').catch(() => {}));
        await page.waitForTimeout(4000);

        // 새 기기 등록 확인 페이지 → "등록안함"
        if (/deviceConfirm/i.test(page.url())) {
            await page.click('#new\\.dontsave')
                .catch(() => page.click('a:has-text("등록안함")'))
                .catch(() => page.click('text=등록안함'))
                .catch(() => {});
            await page.waitForTimeout(3000);
        }

        const cookies0 = await ctx.cookies();
        const loggedIn = cookies0.some(c => c.name === 'NID_AUT') && !/nidlogin|captcha|need2/i.test(page.url());
        if (!loggedIn) {
            console.log(JSON.stringify({ ok: false, reason: 'blocked_or_captcha: ' + page.url() }));
            await browser.close(); process.exit(3);
        }

        // 스마트플레이스 + 통계(bizadvisor) 방문으로 세션 쿠키 보강(ba_access_token 등)
        await page.goto('https://new.smartplace.naver.com/', { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {});
        await page.waitForTimeout(1600);
        await page.goto('https://bizadvisor.naver.com/auth/naver/from/smartplace', { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {});
        await page.waitForTimeout(1800);
        if (PLACE_SEQ) {
            await page.goto('https://new.smartplace.naver.com/bizes/place/' + PLACE_SEQ, { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {});
            await page.waitForTimeout(1600);
        }

        const cookies = await ctx.cookies();
        const cookieStr = cookies.map(c => c.name + '=' + c.value).join('; ');
        console.log(JSON.stringify({ ok: true, cookie: cookieStr }));
        await browser.close(); process.exit(0);
    } catch (e) {
        console.log(JSON.stringify({ ok: false, reason: String((e && e.message) || e) }));
        await browser.close().catch(() => {}); process.exit(5);
    }
})();
