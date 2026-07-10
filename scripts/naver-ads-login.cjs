// 네이버 검색광고 웹 콘솔 자동 로그인 → 세션 쿠키를 JSON 으로 stdout 출력.
//   호출: artisan searchadweb:login 이 env(NAVER_ADS_LOGIN_ID/PW, RANKFREE_PLAYWRIGHT) 주입 후 실행.
//   출력(마지막 줄): {"ok":true,"cookie":"name=value; ..."}  또는  {"ok":false,"reason":"..."}
//   ⚠️ 쿠키는 민감정보 — 호출한 command 가 즉시 암호화 저장하고 로그로 남기지 않는다.
const path = require('path');

function loadPlaywright() {
    const cands = [process.env.RANKFREE_PLAYWRIGHT, 'playwright', path.join(__dirname, '..', 'node_modules', 'playwright')].filter(Boolean);
    for (const c of cands) { try { return require(c); } catch (e) { /* next */ } }
    console.log(JSON.stringify({ ok: false, reason: 'playwright_not_found' }));
    process.exit(10);
}

(async () => {
    const ID = process.env.NAVER_ADS_LOGIN_ID, PW = process.env.NAVER_ADS_LOGIN_PW;
    if (!ID || !PW) { console.log(JSON.stringify({ ok: false, reason: 'no_credentials' })); process.exit(2); }
    const UA = process.env.NAVER_ADS_WEB_UA || 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36';
    const { chromium } = loadPlaywright();
    const browser = await chromium.launch({ headless: true, args: ['--disable-blink-features=AutomationControlled', '--no-sandbox'] });
    try {
        const ctx = await browser.newContext({ userAgent: UA, viewport: { width: 1440, height: 1000 }, locale: 'ko-KR' });
        await ctx.addInitScript(() => Object.defineProperty(navigator, 'webdriver', { get: () => undefined }));
        const page = await ctx.newPage();

        await page.goto('https://nid.naver.com/nidlogin.login?url=https%3A%2F%2Fads.naver.com%2F', { waitUntil: 'domcontentloaded', timeout: 30000 });
        await page.waitForTimeout(1000);
        await page.click('#id'); await page.type('#id', ID, { delay: 70 });
        await page.click('#pw'); await page.type('#pw', PW, { delay: 80 });
        await page.click('.btn_login, button[type=submit]').catch(() => {});
        await page.waitForTimeout(4000);

        const url = page.url();
        if (/nidlogin|captcha|deviceConfirm|need2/i.test(url)) {
            console.log(JSON.stringify({ ok: false, reason: 'blocked_or_captcha' }));
            await browser.close(); process.exit(3);
        }
        // ads 세션 확립
        await page.goto('https://ads.naver.com/', { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {});
        await page.waitForTimeout(1500);

        const cookies = await ctx.cookies();
        const nidAut = cookies.find(c => c.name === 'NID_AUT');
        const nidSes = cookies.find(c => c.name === 'NID_SES');
        if (!nidAut || !nidSes) { console.log(JSON.stringify({ ok: false, reason: 'no_session_cookie' })); await browser.close(); process.exit(4); }

        const cookieStr = cookies.map(c => c.name + '=' + c.value).join('; ');
        console.log(JSON.stringify({ ok: true, cookie: cookieStr }));
        await browser.close(); process.exit(0);
    } catch (e) {
        console.log(JSON.stringify({ ok: false, reason: String((e && e.message) || e) }));
        await browser.close().catch(() => {}); process.exit(5);
    }
})();
