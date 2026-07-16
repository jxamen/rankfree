// 네이버 카페 인기글 크롤러 — 제목·본문·작성일·댓글·댓글작성일 수집 봇.
//
//   사용:
//     node scripts/naver-cafe-crawler.cjs                          # 기본: 아프니까사장이다(23611966) 인기글 전체
//     node scripts/naver-cafe-crawler.cjs --cafe 23611966 --max 30 # 카페/개수 지정
//     node scripts/naver-cafe-crawler.cjs --url "https://cafe.naver.com/f-e/cafes/23611966/popular?t=..."
//     node scripts/naver-cafe-crawler.cjs --headful               # 자동로그인 실패 시 창에서 수동 로그인
//     node scripts/naver-cafe-crawler.cjs --reset --headful       # 저장된 세션 삭제 후 다른 계정으로 로그인
//     node scripts/naver-cafe-crawler.cjs --out-file <경로>        # 지정 경로에 JSON 만 저장(artisan cafe:crawl 연동용)
//
//   로그인:
//     .env 의 NAVER_CAFE_LOGIN_ID/PW (없으면 NAVER_ADS_LOGIN_ID/PW) 로 자동 로그인.
//     세션은 scripts/.naver-cafe-profile 에 유지되어 다음 실행부터 로그인 생략.
//     자동 로그인이 캡차 등으로 막히면 --headful 로 실행해 창에서 직접 로그인(최대 5분 대기).
//
//   수집 구조 (2026-07 실측):
//     - 인기글 목록: apis.naver.com/cafe-web/cafe2/WeeklyPopularArticleListV3.json
//       → UI 의 페이지네이션과 무관하게 전체(약 195건)가 한 번에 내려옴 = 모든 페이지 커버
//     - 본문: cafe-articleapi/v3/cafes/{cafeId}/articles/{id} → result.article.contentHtml (멤버 세션 필요.
//       비멤버는 미리보기 형태라 article 키가 없음 → body 는 null 로 저장)
//     - 댓글: cafe-articleapi/v2/cafes/{cafeId}/articles/{id}/comments/pages/{n}?requestFrom=A&orderBy=asc
//       → result.comments.items[] + result.hasNext(페이징). 401=미로그인, 403/4004=카페 멤버 아님
//     ⚠️ 아프니까사장이다(23611966) 등 멤버 공개 카페는 "카페에 가입된 계정"으로 로그인해야
//        댓글을 읽을 수 있다. 멤버 계정은 --headful 로 창을 띄워 직접 로그인(세션 유지됨).
//
//   출력: storage/app/cafe-crawl/cafe-{cafeId}-popular-{일시}.json / .csv (CSV 는 엑셀용 UTF-8 BOM)
//   ⚠️ 수집 데이터에 닉네임 등 개인정보 포함 — 외부 공유·재배포 금지, 분석 용도로만 사용.
const path = require('path');
const fs = require('fs');

function loadPlaywright() {
    const cands = [process.env.RANKFREE_PLAYWRIGHT, 'playwright', path.join(__dirname, '..', 'node_modules', 'playwright')].filter(Boolean);
    for (const c of cands) { try { return require(c); } catch (e) { /* next */ } }
    console.error('[실패] playwright 를 찾지 못했습니다. 프로젝트 루트에서 npm i 후 재시도하세요.');
    process.exit(10);
}

// ---------- 옵션 ----------
function parseArgs(argv) {
    const opt = { cafeId: 23611966, max: 0, headful: false, out: '', delayMs: 700 };
    for (let i = 2; i < argv.length; i++) {
        const a = argv[i];
        if (a === '--cafe') opt.cafeId = parseInt(argv[++i], 10);
        else if (a === '--url') {
            const m = String(argv[++i]).match(/cafes\/(\d+)/);
            if (m) opt.cafeId = parseInt(m[1], 10);
        }
        else if (a === '--max') opt.max = parseInt(argv[++i], 10) || 0;
        else if (a === '--out') opt.out = argv[++i];
        else if (a === '--out-file') opt.outFile = argv[++i];
        else if (a === '--delay') opt.delayMs = parseInt(argv[++i], 10) || 700;
        else if (a === '--headful') opt.headful = true;
        else if (a === '--reset') opt.reset = true;
    }
    if (!opt.cafeId) { console.error('[실패] cafeId 를 확인할 수 없습니다 (--cafe 또는 --url).'); process.exit(2); }
    return opt;
}

// ---------- .env 간이 파서 (시크릿은 메모리에서만 사용, 출력 금지) ----------
function loadEnv() {
    const envPath = path.join(__dirname, '..', '.env');
    const out = {};
    try {
        for (const line of fs.readFileSync(envPath, 'utf8').split(/\r?\n/)) {
            const m = line.match(/^([A-Z0-9_]+)=(.*)$/);
            if (!m) continue;
            let v = m[2].trim();
            if ((v.startsWith('"') && v.endsWith('"')) || (v.startsWith("'") && v.endsWith("'"))) v = v.slice(1, -1);
            out[m[1]] = v;
        }
    } catch (e) { /* .env 없어도 동작(수동 로그인) */ }
    return out;
}

const sleep = (ms) => new Promise(r => setTimeout(r, ms));
const jitter = (base) => base + Math.floor(Math.random() * base);

function kst(ts) {
    if (!ts) return '';
    return new Date(ts).toLocaleString('sv-SE', { timeZone: 'Asia/Seoul' }); // YYYY-MM-DD HH:mm:ss
}

// ---------- 페이지 컨텍스트에서 API 호출 (쿠키 자동 포함, CORS 허용 오리진) ----------
async function apiGet(page, url) {
    return page.evaluate(async (u) => {
        const r = await fetch(u, { credentials: 'include', headers: { 'x-cafe-product': 'pc' } });
        const t = await r.text();
        try { return { status: r.status, json: JSON.parse(t) }; } catch (e) { return { status: r.status, text: t.slice(0, 200) }; }
    }, url);
}

async function isLoggedIn(page) {
    const r = await apiGet(page, 'https://apis.naver.com/cafe-home-web/cafe-home/v1/member/identifier').catch(() => null);
    return !!(r && r.json && r.json.message && r.json.message.result && r.json.message.result.loggedIn);
}

// ---------- 로그인 (자동 → 수동 폴백) ----------
async function ensureLogin(page, opt, env) {
    if (await isLoggedIn(page)) { console.log('[로그인] 기존 세션 재사용'); return true; }

    // --reset --headful = 다른 계정으로 직접 로그인하려는 의도 → env 자동 로그인 건너뜀
    const manualOnly = opt.reset && opt.headful;
    const ID = env.NAVER_CAFE_LOGIN_ID || env.NAVER_ADS_LOGIN_ID;
    const PW = env.NAVER_CAFE_LOGIN_PW || env.NAVER_ADS_LOGIN_PW;

    if (ID && PW && !manualOnly) {
        console.log('[로그인] 자동 로그인 시도: ' + ID.slice(0, 3) + '***');
        await page.goto('https://nid.naver.com/nidlogin.login?mode=form&url=https%3A%2F%2Fcafe.naver.com%2F', { waitUntil: 'domcontentloaded', timeout: 30000 });
        await sleep(900);
        await page.click('#id'); await page.type('#id', ID, { delay: 60 });
        await page.click('#pw'); await page.type('#pw', PW, { delay: 70 });
        // 로그인 상태 유지 ON — 미체크 세션은 하루 내 만료(smartplace 스크립트 실측)
        await page.check('#keep').catch(() => page.click('label[for="keep"]').catch(() => {}));
        await page.click('#log\\.login').catch(() => page.click('.btn_login, button[type=submit]').catch(() => {}));
        await sleep(4000);
        if (/deviceConfirm/i.test(page.url())) { // 새 기기 등록 확인 → 등록안함
            await page.click('#new\\.dontsave')
                .catch(() => page.click('a:has-text("등록안함")'))
                .catch(() => page.click('text=등록안함'))
                .catch(() => {});
            await sleep(3000);
        }
        await page.goto('https://cafe.naver.com/', { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {});
        await sleep(1200);
        if (await isLoggedIn(page)) { console.log('[로그인] 자동 로그인 성공'); return true; }
        console.log('[로그인] 자동 로그인 실패(캡차/2단계 인증 가능성)');
    } else {
        console.log('[로그인] .env 에 NAVER_CAFE_LOGIN_ID/PW 없음');
    }

    if (!opt.headful) {
        console.error('[실패] 로그인 필요 — --headful 로 실행해 창에서 직접 로그인하세요. (세션은 프로필에 저장되어 1회면 됩니다)');
        return false;
    }
    // 수동 로그인 대기 (최대 5분)
    console.log('[로그인] 브라우저 창에서 직접 로그인해 주세요 (최대 5분 대기)...');
    await page.goto('https://nid.naver.com/nidlogin.login?mode=form&url=https%3A%2F%2Fcafe.naver.com%2F', { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {});
    const deadline = Date.now() + 5 * 60 * 1000;
    while (Date.now() < deadline) {
        await sleep(3000);
        if (await isLoggedIn(page).catch(() => false)) { console.log('[로그인] 수동 로그인 확인'); return true; }
    }
    console.error('[실패] 로그인 대기 시간 초과');
    return false;
}

// ---------- 인기글 목록 (전체 페이지 분량이 한 번에 내려옴) ----------
async function fetchPopularList(page, cafeId) {
    const url = `https://apis.naver.com/cafe-web/cafe2/WeeklyPopularArticleListV3.json?cafeId=${cafeId}&mobileWeb=true&adUnit=PC_CAFE_BOARD&ad=false`;
    const r = await apiGet(page, url);
    const result = r.json && r.json.message && r.json.message.result;
    if (!result || !Array.isArray(result.articleList)) {
        throw new Error('인기글 목록 응답 형식이 예상과 다릅니다 (status=' + r.status + ')');
    }
    return result.articleList.map(it => ({
        articleId: it.articleId,
        title: it.subject,
        writeDate: kst(it.writeDateTimestamp),
        writer: it.nickname || '',
        commentCount: it.commentCount || 0,
        readCount: it.readCount || 0,
    }));
}

// ---------- 본문 (v3 article — 멤버 세션에서만 article 객체가 내려옴) ----------
function htmlToText(html) {
    return String(html || '')
        .replace(/<br\s*\/?>/gi, '\n')
        .replace(/<\/(p|div|li|h[1-6])>/gi, '\n')
        .replace(/<[^>]*>/g, '')
        .replace(/&nbsp;/g, ' ')
        .replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>')
        .replace(/&#39;/g, "'").replace(/&quot;/g, '"')
        .replace(/[​⠀﻿]/g, '') // 폭 없는 문자·점자 공백(카페 에디터 잔재)
        .replace(/[ \t]+\n/g, '\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

async function fetchBody(page, cafeId, articleId) {
    const url = `https://apis.naver.com/cafe-web/cafe-articleapi/v3/cafes/${cafeId}/articles/${articleId}?useCafeId=true&requestFrom=A`;
    const r = await apiGet(page, url);
    if (r.status === 401) throw Object.assign(new Error('unauthorized'), { code: 401 });
    const art = r.json && r.json.result && r.json.result.article;
    if (!art || !art.contentHtml) return null; // 비멤버 미리보기/삭제 글 등
    return htmlToText(art.contentHtml);
}

// ---------- 카페 멤버 여부 ----------
async function isCafeMember(page, cafeId) {
    const r = await apiGet(page, `https://apis.naver.com/cafe-web/cafe-cafeinfo-api/v1.0/cafes/${cafeId}/members`).catch(() => null);
    return !!(r && r.json && r.json.result && r.json.result.cafeMember);
}

// ---------- 댓글 (v2 comments/pages 순회, hasNext 기반) ----------
function mapComment(c) {
    const w = c.writer || {};
    return {
        commentId: c.id ?? null,
        parentId: (c.refId && c.refId !== c.id) ? c.refId : null, // 대댓글이면 원댓글 id
        writer: w.nick || '',
        content: String(c.content || '').replace(/<[^>]*>/g, '').trim(),
        writeDate: kst(c.updateDate),
        isDeleted: !!c.isDeleted,
    };
}

async function fetchComments(page, cafeId, articleId) {
    const all = [];
    const seen = new Set(); // 댓글 수가 페이지 크기(100)의 배수면 서버가 마지막 페이지를 반복 반환함 — id 로 중복 차단
    for (let p = 1; p <= 100; p++) {
        const url = `https://apis.naver.com/cafe-web/cafe-articleapi/v2/cafes/${cafeId}/articles/${articleId}/comments/pages/${p}?requestFrom=A&orderBy=asc`;
        const r = await apiGet(page, url);
        if (r.status === 401) throw Object.assign(new Error('unauthorized'), { code: 401 });
        const res = (r.json && r.json.result) || {};
        if (res.errorCode === '4004') throw Object.assign(new Error('members_only'), { code: 4004 });
        if (r.status !== 200) throw new Error('댓글 API 오류 status=' + r.status);
        const items = (res.comments && res.comments.items) || [];
        const fresh = items.filter(c => !seen.has(c.id));
        fresh.forEach(c => seen.add(c.id));
        all.push(...fresh.map(mapComment));
        if (!res.hasNext || !fresh.length) break;
        await sleep(250);
    }
    return all;
}

// ---------- CSV ----------
function csvEsc(v) {
    const s = String(v ?? '');
    return /[",\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
}

function toCsv(articles) {
    const head = ['articleId', '제목', '작성자', '작성일', '조회수', '댓글수', 'commentId', '댓글작성자', '댓글내용', '댓글작성일'];
    const rows = [head.join(',')];
    for (const a of articles) {
        const base = [a.articleId, a.title, a.writer, a.writeDate, a.readCount, a.commentCount];
        if (!a.comments || !a.comments.length) rows.push([...base, '', '', '', ''].map(csvEsc).join(','));
        else for (const c of a.comments) rows.push([...base, c.commentId, c.writer, c.content, c.writeDate].map(csvEsc).join(','));
    }
    return '﻿' + rows.join('\r\n');
}

// ---------- 메인 ----------
(async () => {
    const opt = parseArgs(process.argv);
    const env = Object.assign(loadEnv(), process.env); // 호출측(artisan) 주입 env 우선
    const { chromium } = loadPlaywright();

    const profileDir = path.join(__dirname, '.naver-cafe-profile');
    if (opt.reset) { fs.rmSync(profileDir, { recursive: true, force: true }); console.log('[초기화] 저장된 로그인 세션 삭제'); }
    const outDir = opt.out || path.join(__dirname, '..', 'storage', 'app', 'cafe-crawl');
    fs.mkdirSync(outDir, { recursive: true });

    const ctx = await chromium.launchPersistentContext(profileDir, {
        headless: !opt.headful,
        args: ['--disable-blink-features=AutomationControlled'],
        userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36',
        viewport: { width: 1400, height: 900 },
        locale: 'ko-KR',
    });
    await ctx.addInitScript(() => Object.defineProperty(navigator, 'webdriver', { get: () => undefined }));
    const page = ctx.pages()[0] || await ctx.newPage();

    try {
        // 카페 페이지 방문(오리진 확립) 후 로그인 확인
        await page.goto(`https://cafe.naver.com/f-e/cafes/${opt.cafeId}/popular`, { waitUntil: 'domcontentloaded', timeout: 45000 });
        await sleep(1500);
        if (!await ensureLogin(page, opt, env)) { await ctx.close(); process.exit(3); }

        const member = await isCafeMember(page, opt.cafeId);
        if (!member) console.log('[주의] 현재 계정은 이 카페 멤버가 아닙니다 — 멤버 공개 카페면 댓글 수집이 거부됩니다.');

        console.log(`[목록] 카페 ${opt.cafeId} 인기글 수집 중...`);
        let list = await fetchPopularList(page, opt.cafeId);
        console.log(`[목록] ${list.length}건 (UI 전체 페이지 분량)`);
        if (opt.max > 0) list = list.slice(0, opt.max);

        const articles = [];
        let commentTotal = 0, failed = 0, membersOnly = 0;
        for (let i = 0; i < list.length; i++) {
            const a = list[i];
            let comments = [];
            let body = null;
            try {
                body = await fetchBody(page, opt.cafeId, a.articleId);
            } catch (e) {
                if (e.code === 401) { console.error('[중단] 세션이 만료되었습니다. 다시 실행해 재로그인하세요.'); break; }
                console.log(`  ! ${a.articleId} 본문 수집 실패: ${e.message}`);
            }
            if (a.commentCount > 0) {
                try {
                    comments = await fetchComments(page, opt.cafeId, a.articleId);
                } catch (e) {
                    if (e.code === 401) { console.error('[중단] 세션이 만료되었습니다. 다시 실행해 재로그인하세요.'); break; }
                    if (e.code === 4004) {
                        membersOnly++;
                        if (membersOnly >= 3 && commentTotal === 0) {
                            console.error('[중단] 카페 멤버만 읽을 수 있는 글입니다. 이 카페에 가입된 계정으로 로그인하세요:');
                            console.error('       node scripts/naver-cafe-crawler.cjs --headful   ← 창에서 본인(멤버) 계정 로그인, 이후 세션 유지');
                            break;
                        }
                    } else failed++;
                    console.log(`  ! ${a.articleId} 댓글 수집 실패: ${e.message}`);
                }
            }
            commentTotal += comments.length;
            articles.push({ ...a, body, url: `https://cafe.naver.com/f-e/cafes/${opt.cafeId}/articles/${a.articleId}`, comments });
            console.log(`  [${i + 1}/${list.length}] ${String(a.articleId)} 본문 ${body ? body.length + '자' : '없음'} 댓글 ${comments.length}/${a.commentCount} | ${a.title.slice(0, 40)}`);
            await sleep(jitter(opt.delayMs)); // 과도한 요청 방지
        }

        const payload = JSON.stringify({ cafeId: opt.cafeId, crawledAt: kst(Date.now()), articleCount: articles.length, commentCount: commentTotal, articles }, null, 2);
        console.log(`\n[완료] 글 ${articles.length}건, 댓글 ${commentTotal}건${failed ? `, 실패 ${failed}건` : ''}${membersOnly ? `, 멤버 전용 ${membersOnly}건` : ''}`);
        if (opt.outFile) { // 연동 모드 — 지정 경로에 JSON 만
            fs.mkdirSync(path.dirname(opt.outFile), { recursive: true });
            fs.writeFileSync(opt.outFile, payload, 'utf8');
            console.log('  JSON: ' + opt.outFile);
        } else {
            const stamp = new Date().toLocaleString('sv-SE', { timeZone: 'Asia/Seoul' }).replace(/[: ]/g, '-');
            const base = path.join(outDir, `cafe-${opt.cafeId}-popular-${stamp}`);
            fs.writeFileSync(base + '.json', payload, 'utf8');
            fs.writeFileSync(base + '.csv', toCsv(articles), 'utf8');
            console.log('  JSON: ' + base + '.json');
            console.log('  CSV : ' + base + '.csv');
        }
        await ctx.close();
        process.exit(0);
    } catch (e) {
        console.error('[실패] ' + String((e && e.message) || e));
        await ctx.close().catch(() => {});
        process.exit(5);
    }
})();
