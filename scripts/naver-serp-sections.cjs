// 네이버 통합검색 PC/모바일 섹션 배치 순서 수집 → JSON stdout.
//   호출: node naver-serp-sections.cjs "키워드"
//   출력(마지막 줄): {"ok":true,"pc":["플레이스",…],"mobile":[…]}  또는  {"ok":false,"reason":"…"}
const path = require('path');
function loadPlaywright() {
    const cands = [process.env.RANKFREE_PLAYWRIGHT, 'playwright', path.join(__dirname, '..', 'node_modules', 'playwright')].filter(Boolean);
    for (const c of cands) { try { return require(c); } catch (e) {} }
    console.log(JSON.stringify({ ok: false, reason: 'playwright_not_found' })); process.exit(10);
}
const kw = (process.argv[2] || '').trim();
if (!kw) { console.log(JSON.stringify({ ok: false, reason: 'no_keyword' })); process.exit(2); }
const PC_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36';
const MO_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

async function autoScroll(page) {
    // 지연로딩 섹션 트리거
    await page.evaluate(async () => {
        await new Promise((res) => {
            let y = 0; const step = 800;
            const t = setInterval(() => {
                window.scrollBy(0, step); y += step;
                if (y >= document.body.scrollHeight - window.innerHeight - 100 || y > 12000) { clearInterval(t); res(); }
            }, 150);
        });
    });
    await page.waitForTimeout(700);
    await page.evaluate(() => window.scrollTo(0, 0));
}

async function sections(page) {
    return await page.evaluate(() => {
        const clean = (s) => (s || '').replace(/\s+/g, ' ').trim();
        const root = document.querySelector('#main_pack') || document.querySelector('#ct') || document.body;
        const drop = /^(정렬|기간|도움말|검색결과|연관|자동완성|옵션|신고|더보기|접기|필터|이전|다음|관련|이 광고)/;
        const KNOWN = ['플레이스', '파워링크', '비즈사이트', '파워컨텐츠', '네이버 클립', '블로그', '카페', '인기글', '지식iN',
            '이미지', '동영상', '뉴스', '지식백과', '웹사이트', '가격비교', '네이버플러스 스토어', '스토어', '쇼핑',
            '어학사전', '함께 많이 찾는', '새로 오픈했어요', '라운지', '메이트', '지도', '오디오클립', '방송사',
            'VIEW', '통합웹', '인플루언서', '스마트블록'];
        // 헤더 텍스트에 알려진 섹션명이 포함되면 그 이름으로 정규화(가장 긴 매치 우선)
        const byHeader = (name) => {
            let best = '';
            for (const k of KNOWN) { if (name.includes(k) && k.length > best.length) best = k; }
            return best;
        };
        const typeByLinks = (sec) => {
            const hosts = {};
            sec.querySelectorAll('a[href]').forEach((a) => { try { const h = new URL(a.href).hostname; hosts[h] = (hosts[h] || 0) + 1; } catch (e) {} });
            const has = (re) => Object.keys(hosts).some((h) => re.test(h));
            if (has(/ader\.naver|adcr\.naver|gfa\.naver|saedu\.naver/)) return '파워링크';
            if (has(/place\.naver|pcmap|map\.naver/)) return '플레이스';
            if (has(/kin\.naver/)) return '지식iN';
            if (has(/cafe\.naver/)) return '카페';
            if (has(/blog\.naver|post\.naver|in\.naver/)) return '블로그';
            if (has(/news\.naver|n\.news\.naver/)) return '뉴스';
            if (has(/shopping\.naver|smartstore|brand\.naver/)) return '쇼핑';
            if (has(/tv\.naver|video\.naver|m\.tv\.naver/)) return '동영상';
            if (has(/dict\.naver/)) return '어학사전';
            if (has(/terms\.naver/)) return '지식백과';
            return '';
        };
        // 외부(비네이버) 링크가 주된 섹션 = 웹문서 결과
        const hasExternal = (sec) => {
            for (const a of sec.querySelectorAll('a[href]')) {
                try { const h = new URL(a.href).hostname; if (h && !/\.naver\.com$|^naver\.com$|naver\.me$/.test(h)) return true; } catch (e) {}
            }
            return false;
        };
        // 콘텐츠 출처(고신뢰) — 헤더 substring 오탐(예: "쇼핑용어사전"→쇼핑)보다 우선.
        const sourceType = (sec) => {
            const hosts = {};
            sec.querySelectorAll('a[href]').forEach((a) => { try { const h = new URL(a.href).hostname; hosts[h] = (hosts[h] || 0) + 1; } catch (e) {} });
            const cnt = (re) => Object.entries(hosts).reduce((s, [h, c]) => s + (re.test(h) ? c : 0), 0);
            if (cnt(/terms\.naver/) >= 1) return '지식백과';
            if (cnt(/dict\.naver/) >= 1) return '어학사전';
            if (cnt(/kin\.naver/) >= 2) return '지식iN';
            return '';
        };
        // 섹션명 판별: 콘텐츠 출처 → 헤더 → 링크 호스트 → 외부링크(웹문서) → 짧은 헤더 그대로
        const classify = (raw, el) => {
            const src = sourceType(el);
            if (src) return src;
            const byH = byHeader(raw);
            if (byH) return byH;
            const byL = typeByLinks(el);
            if (byL) return byL;
            if (hasExternal(el)) return '웹사이트';
            if (raw && raw.length <= 14 && !drop.test(raw)) return raw;
            return '';
        };

        // 섹션 내 콘텐츠(항목) 개수 추정 — leaf li(앵커 포함, 하위 li 없음) 우선, 없으면 제목 링크 계열
        const countItems = (sec) => {
            let n = Array.from(sec.querySelectorAll('li')).filter(
                (li) => li.querySelector('a[href]') && !li.querySelector('li')
            ).length;
            if (!n) {
                n = sec.querySelectorAll(
                    '.title_link, .total_tit, [class*="title"] a[href], .api_txt_lines[href], strong.tit a, .fds-comps-right-image-text-title'
                ).length;
            }
            if (!n) {
                n = sec.querySelectorAll('.thumb, .card_item, [class*="item"] a[href]').length;
            }
            return n;
        };

        // #main_pack 직계 자식을 문서 순서대로 순회 → 최상위 섹션을 그대로 잡음(순서·누락 정확).
        const headerSel = '.api_title, .mod_title, [class*="header-headline"], [class*="sds-comps-text-type-headline"], h2, h3';
        const skipCls = /_scrollLog|_search_option|api_sc_page_wrap|ct_feed_wrap|api_disp|_ac_|related_srch|_lazy_loading_wrap|api_flow/i;
        const out = [], seen = new Set();
        Array.from(root.children).forEach((el) => {
            const tag = el.tagName.toLowerCase();
            if (['script', 'link', 'style', 'noscript', 'template'].includes(tag)) return;
            const cls = (el.className || '').toString();
            if (skipCls.test(cls)) return;
            // 실제 섹션 컨테이너: place-app-root / sc_new / api_subject_bx / sp_* / <section>, 또는 헤더 보유
            const isSection = /place-app-root|sc_new|api_subject_bx|\bsp_/.test(cls) || tag === 'section' || el.querySelector(headerSel);
            if (!isSection) return;
            if (!el.querySelector('a[href]')) return;   // 링크 없는 장식 블록 제외
            const h = el.querySelector(headerSel);
            let raw = h ? clean(h.innerText || h.textContent).split('\n')[0] : '';
            raw = raw.replace(/검색결과 안내.*$/, '').replace(/\s*(광고|AD|더보기)\s*$/, '').trim();
            const name = classify(raw, el);
            if (!name || drop.test(name) || name.length < 2) return;
            if (seen.has(name)) return;   // 배치 순서용 — 동일 섹션 중복은 첫 등장만
            seen.add(name);
            out.push({ name: name, count: countItems(el) });
        });
        return out;
    });
}

(async () => {
    const { chromium } = loadPlaywright();
    const browser = await chromium.launch({ headless: true, args: ['--disable-blink-features=AutomationControlled', '--no-sandbox'] });
    try {
        const res = { ok: true, pc: [], mobile: [] };
        // PC
        const ctxPc = await browser.newContext({ userAgent: PC_UA, viewport: { width: 1440, height: 2200 }, locale: 'ko-KR' });
        const pPc = await ctxPc.newPage();
        await pPc.goto('https://search.naver.com/search.naver?query=' + encodeURIComponent(kw), { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {});
        await autoScroll(pPc);
        res.pc = await sections(pPc);
        await ctxPc.close();
        // Mobile
        const ctxMo = await browser.newContext({ userAgent: MO_UA, viewport: { width: 390, height: 2600 }, locale: 'ko-KR', isMobile: true, hasTouch: true });
        const pMo = await ctxMo.newPage();
        await pMo.goto('https://m.search.naver.com/search.naver?query=' + encodeURIComponent(kw), { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {});
        await autoScroll(pMo);
        res.mobile = await sections(pMo);
        await ctxMo.close();

        console.log(JSON.stringify(res));
        await browser.close(); process.exit(0);
    } catch (e) {
        console.log(JSON.stringify({ ok: false, reason: String((e && e.message) || e) }));
        await browser.close().catch(() => {}); process.exit(1);
    }
})();
