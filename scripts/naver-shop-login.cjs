// 쇼핑 순위수집용 네이버 자동 로그인 — 브라우저 프로필에 세션을 심는다.
//
//   호출: artisan shoprank:login 이 env(NAVER_SHOP_LOGIN_ID/PW, SHOP_RANK_PROFILE …) 주입 후 실행.
//
//   왜 이렇게까지 하나(2026-08-04 실측):
//     쇼핑 검색(search.shopping.naver.com)은 아래를 **모두** 갖춰야 200 이다. 하나라도 빠지면 405/418.
//       · headful — headless 는 `--headless=new` 까지 전부 418 (리눅스는 xvfb-run 으로 감싼다)
//       · launchPersistentContext(프로필) — 같은 쿠키라도 비영구 컨텍스트면 418
//       · 네이버 로그인 세션 — 비로그인 쿠키만으로는 405
//       · 기기등록(deviceConfirm) 통과 — 로그인만 하고 넘기면 세션이 미완성이라 418
//     그래서 쿠키를 뽑아 넘기지 않고 **프로필 자체를 세션 저장소로 쓴다**.
//
//   실행:  node scripts/naver-shop-login.cjs
//   옵션:  --check-only  로그인 시도 없이 현재 프로필 상태만 점검(크론 --if-stale 용)
//          --headful     창을 띄운다(로컬 디버깅용, 리눅스 서버는 xvfb-run 사용)
//   출력:  마지막 줄에 JSON {ok, loggedIn, shopStatus, reason?}

const path = require('path');

const SHOP_URL = 'https://search.shopping.naver.com/ns/search?query=%EC%BA%A0%ED%95%91%EC%9D%98%EC%9E%90';
const LOGIN_URL = 'https://nid.naver.com/nidlogin.login?url=https%3A%2F%2Fshopping.naver.com%2F';

function log(...a) { console.error('[' + new Date().toISOString() + ']', ...a); }
function out(o) { console.log(JSON.stringify(o)); }

function parseArgs(argv) {
    const o = { checkOnly: false, manual: false };
    for (let i = 2; i < argv.length; i++) {
        if (argv[i] === '--check-only') o.checkOnly = true;
        else if (argv[i] === '--manual') o.manual = true;
        else if (argv[i] === '--profile') o.profile = argv[++i];
    }
    return o;
}

function loadPlaywright() {
    const cands = [process.env.RANKFREE_PLAYWRIGHT, 'playwright', path.join(__dirname, '..', 'node_modules', 'playwright')].filter(Boolean);
    for (const c of cands) {
        try { return require(c); } catch (e) { /* next */ }
    }
    log('playwright 를 찾지 못했습니다.');
    process.exit(10);
}

/** 쇼핑 검색이 실제로 열리는지 = 세션이 쓸 만한지. 200 이 아니면 세션 재확보가 필요하다. */
async function probeShop(page) {
    const r = await page.goto(SHOP_URL, { waitUntil: 'domcontentloaded', timeout: 45000 }).catch(() => null);
    return r ? r.status() : 0;
}

(async () => {
    const opt = parseArgs(process.argv);
    const profile = opt.profile || process.env.SHOP_RANK_PROFILE || path.join(__dirname, '.naver-shop-profile');
    const ID = process.env.NAVER_SHOP_LOGIN_ID || '';
    const PW = process.env.NAVER_SHOP_LOGIN_PW || '';

    const { chromium } = loadPlaywright();
    // headless 는 네이버가 차단한다 — 리눅스에서는 이 프로세스를 xvfb-run 으로 감싼다.
    const ctx = await chromium.launchPersistentContext(profile, {
        headless: false,
        locale: 'ko-KR',
        viewport: { width: 1440, height: 1000 },
        args: ['--disable-blink-features=AutomationControlled', '--no-sandbox'],
    });
    const page = ctx.pages()[0] || await ctx.newPage();

    try {
        // 1) 현재 세션 점검 — ⚠️ 로그인 없이 쇼핑 "검색"을 먼저 찌르면 405 를 맞고 그 세션이 마킹된다(실측).
        //    그래서 로그인 쿠키가 있을 때만 검색으로 확인하고, 없으면 곧장 로그인부터 한다.
        let st = 0;
        const hasSession = async () => {
            const n = (await ctx.cookies()).map((c) => c.name);

            return n.includes('NID_AUT') && n.includes('NID_SES');
        };
        if (await hasSession()) {
            st = await probeShop(page);
            if (st === 200) {
                log('세션 유효 — 로그인 생략');
                out({ ok: true, loggedIn: true, shopStatus: st });

                return;
            }
            log('세션은 있으나 쇼핑 status=' + st + ' — 재로그인 진행');
        } else {
            log('로그인 쿠키 없음 — 검색 probe 생략하고 로그인부터');
        }
        if (opt.checkOnly) {
            out({ ok: false, loggedIn: false, shopStatus: st, reason: 'stale' });

            return;
        }
        if (ID === '' || PW === '') {
            out({ ok: false, loggedIn: false, shopStatus: st, reason: 'no_credentials' });

            return;
        }

        // 2-a) 수동 로그인 — 캡차가 걸렸을 때 사람이 직접 풀어 프로필에 세션을 심는다.
        //      (자동 로그인이 blocked_or_captcha 로 실패하면 이 모드로 1회 통과시켜 두면 이후 자동 유지된다)
        if (opt.manual) {
            log('수동 로그인 모드 — 열린 창에서 로그인하세요("로그인 상태 유지"를 반드시 켜세요). 최대 5분 대기합니다.');
            await page.goto(LOGIN_URL, { waitUntil: 'domcontentloaded', timeout: 30000 });
            const until = Date.now() + 300000;
            while (Date.now() < until) {
                const n = (await ctx.cookies()).map((c) => c.name);
                if (n.includes('NID_AUT') && n.includes('NID_SES')) {
                    break;
                }
                await page.waitForTimeout(3000);
            }
            const n = (await ctx.cookies()).map((c) => c.name);
            if (! n.includes('NID_AUT')) {
                out({ ok: false, loggedIn: false, shopStatus: 0, reason: 'manual_timeout' });

                return;
            }
            log('로그인 확인 — 쇼핑 접근 점검');
            await page.goto('https://shopping.naver.com/ns/home', { waitUntil: 'domcontentloaded', timeout: 45000 }).catch(() => {});
            await page.waitForTimeout(3000);
            st = await probeShop(page);
            const keep = (await ctx.cookies()).find((c) => c.name === 'NID_AUT');
            out({ ok: st === 200, loggedIn: true, shopStatus: st, persistent: !! (keep && keep.expires && keep.expires > 0), reason: st === 200 ? undefined : 'shop_blocked' });

            return;
        }

        // 2-b) 자동 로그인
        log('로그인 시도…');
        await page.goto(LOGIN_URL, { waitUntil: 'domcontentloaded', timeout: 30000 });
        await page.click('#id');
        await page.type('#id', ID, { delay: 70 });
        await page.click('#pw');
        await page.type('#pw', PW, { delay: 80 });
        // ⚠️ "로그인 상태 유지"를 켜야 NID_AUT 가 **영구 쿠키**로 발급된다.
        //    끄면 세션 쿠키라 브라우저를 닫는 순간 사라져, 다음 수집이 곧바로 405 가 된다(실측).
        for (const sel of ['#keep', 'input[name=keep]', 'label:has-text("로그인 상태 유지")']) {
            const el = page.locator(sel).first();
            if (! (await el.count().catch(() => 0))) {
                continue;
            }
            const on = await el.isChecked().catch(() => null);
            if (on === false) {
                await el.check({ timeout: 3000 }).catch(() => {});
            } else if (on === null) {
                await el.click({ timeout: 3000 }).catch(() => {});
            }
            break;
        }
        // 네이버 UI 변경(2026-08) — 예전 `.btn_login` 은 없다. 반응형이라 column/row 두 벌이 있다.
        await page.click('#loginBtn_column, #loginBtn_row').catch(async () => { await page.keyboard.press('Enter'); });
        await page.waitForTimeout(6000);

        // 3) 새 기기 등록 — 여기서 멈추면 세션이 미완성이라 쇼핑이 418 이 된다
        if (/deviceConfirm/i.test(page.url())) {
            log('기기 등록 화면 — 등록 진행');
            await page.locator('#new\\.save, a:has-text("등록")').first().click({ timeout: 8000 }).catch(() => {});
            await page.waitForTimeout(5000);
        }

        const url = page.url();
        if (/nidlogin|captcha|need2/i.test(url)) {
            log('로그인 실패/캡차:', url.slice(0, 80));
            out({ ok: false, loggedIn: false, shopStatus: 0, reason: 'blocked_or_captcha' });

            return;
        }

        const names = (await ctx.cookies()).map((c) => c.name);
        if (! names.includes('NID_AUT') || ! names.includes('NID_SES')) {
            out({ ok: false, loggedIn: false, shopStatus: 0, reason: 'no_session_cookie' });

            return;
        }

        // 4) 쇼핑 홈을 한 번 거친 뒤 검색 확인(홈 방문에서 nstore_session 이 발급된다)
        await page.goto('https://shopping.naver.com/ns/home', { waitUntil: 'domcontentloaded', timeout: 45000 }).catch(() => {});
        await page.waitForTimeout(3000);
        st = await probeShop(page);
        log('로그인 후 쇼핑 검색 status =', st);

        // 보안확인(캡차) 페이지인지 구분 — 사람이 한 번 풀어야 하므로 재시도로는 안 풀린다.
        let reason;
        if (st !== 200) {
            const body = await page.evaluate(() => document.body.innerText.slice(0, 300)).catch(() => '');
            reason = /보안\s*확인|자동입력|캡차|captcha/i.test(body) ? 'captcha_required' : 'shop_blocked';
        }
        const keep = (await ctx.cookies()).find((c) => c.name === 'NID_AUT');
        out({ ok: st === 200, loggedIn: true, shopStatus: st, persistent: !! (keep && keep.expires && keep.expires > 0), reason });
    } catch (e) {
        log('예외:', (e && e.message) || e);
        out({ ok: false, loggedIn: false, shopStatus: 0, reason: 'exception' });
    } finally {
        await ctx.close().catch(() => {});
    }
})();
