/**
 * 네이버 통합검색 플레이스 영역 노출 순위 수집 (2026-08-27)
 *
 * 왜 브라우저인가 — 실측:
 *   · 통합검색 HTML(curl)에는 플레이스 카드가 **첫 5개만** 들어 있고,
 *     "펼쳐서 더보기"로 나오는 나머지는 JS 로 그려져 HTML 에 없다.
 *   · HTML 에서 placeId 문자열만 찾으면 **거짓 양성** — 블로그 썸네일 데이터가
 *     "gdid":"blog_…","sid":"<placeId>" 로 같은 숫자를 싣고 있다.
 *   · pcmap 순위(순위추적 엔진)는 화면 순서와 어긋난다(pcmap 8위인데 화면 6번째 등).
 *   → 사용자가 실제로 보는 순서를 그대로 세려면 브라우저 렌더링이 필요하다.
 *
 * 사용법:
 *   node scripts/place-serp-rank.cjs --pid=2004558772 --keywords="초량미용실|단장헤어" [--expand=3]
 * 출력(마지막 줄):
 *   {"ok":true,"results":[{"keyword":"초량미용실","rank":6,"cards":6}, ...]}
 */
const { chromium } = require('playwright');

const arg = (name, def = '') => {
    const hit = process.argv.find((a) => a.startsWith(`--${name}=`));
    return hit ? hit.slice(name.length + 3) : def;
};

const PID = arg('pid').replace(/\D/g, '');
const KEYWORDS = arg('keywords').split('|').map((s) => s.trim()).filter(Boolean);
const EXPAND = parseInt(arg('expand', '3'), 10);
const MO_UA =
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

(async () => {
    if (!PID || !KEYWORDS.length) {
        console.log(JSON.stringify({ ok: false, error: 'pid/keywords 필요' }));
        process.exit(0);
    }

    let browser;
    try {
        browser = await chromium.launch({ args: ['--no-sandbox', '--disable-dev-shm-usage'] });
    } catch (e) {
        console.log(JSON.stringify({ ok: false, error: 'browser: ' + e.message }));
        process.exit(0);
    }

    const page = await browser.newPage({ viewport: { width: 420, height: 900 }, userAgent: MO_UA, locale: 'ko-KR' });
    const results = [];

    // 화면에 그려진 플레이스 카드의 placeId 를 등장 순서대로(중복 제거)
    const collect = () =>
        page.evaluate(() => {
            const out = [];
            const seen = new Set();
            document.querySelectorAll('a[href*="place.naver.com"]').forEach((a) => {
                const m = a.href.match(/place\.naver\.com\/[a-z]+\/(\d{6,})/i);
                if (!m || seen.has(m[1])) return;
                seen.add(m[1]);
                out.push(m[1]);
            });
            return out;
        });

    for (const keyword of KEYWORDS) {
        let rank = 0;
        let cards = 0;
        let err = null;
        try {
            await page.goto('https://m.search.naver.com/search.naver?query=' + encodeURIComponent(keyword), {
                waitUntil: 'networkidle',
                timeout: 25000,
            });
            await page.waitForTimeout(500);

            let list = await collect();
            // "펼쳐서 더보기" 를 눌러 목록을 넓힌다 — 대상을 찾으면 즉시 중단
            for (let i = 0; i < EXPAND && !list.includes(PID); i++) {
                const btn = page.locator('a:has-text("펼쳐서 더보기"), a:has-text("더보기")').first();
                if (!(await btn.count().catch(() => 0))) break;
                await btn.click({ timeout: 3000 }).catch(() => {});
                await page.waitForTimeout(1000);
                await page.mouse.wheel(0, 2500);          // 스크롤해야 지연 로딩분이 그려진다
                await page.waitForTimeout(600);
                const next = await collect();
                if (next.length === list.length) break;   // 더 늘지 않으면 끝
                list = next;
            }

            cards = list.length;
            const pos = list.indexOf(PID);
            rank = pos < 0 ? 0 : pos + 1;
        } catch (e) {
            // 키워드 하나가 실패해도 나머지는 계속 — 다만 '순위 없음' 과 구분되게 사유를 남긴다
            err = String(e.message || e).slice(0, 120);
        }
        results.push(err ? { keyword, rank: 0, cards, error: err } : { keyword, rank, cards });
    }

    await browser.close().catch(() => {});
    console.log(JSON.stringify({ ok: true, results }));
})();
