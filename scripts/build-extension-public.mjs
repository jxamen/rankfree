/**
 * 웹스토어 공개 배포본 생성 — extension/ 에서 사내 전용 코드를 걷어내 extension-public/ 을 만든다.
 *
 * 방향이 왜 이런가: extension/ 은 **평소 크롬에 로드해 쓰는 폴더**다(운영자가 매일 쓴다).
 * 여기를 건드리면 일상 작업이 깨지므로, 공개본을 생성물로 뺐다.
 *
 * extension-public/ 은 **git 에 커밋한다.** 관리자 화면의 [웹스토어에 게시] 버튼
 * (app/Support/CwsPublisher.php)은 운영 서버에서 도는데 거기엔 node 가 없어
 * 빌드를 못 돌린다 — 커밋된 결과물을 그대로 압축해야 한다.
 *
 *   node scripts/build-extension-public.mjs
 *
 * ⚠️ extension/ 을 고쳤으면 **게시 전에 반드시 이걸 다시 돌려야 한다.**
 *    안 돌리면 낡은 코드가 게시된다. 두 게시 경로 모두 manifest 버전이 어긋나면 거부한다.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SRC = path.join(ROOT, 'extension');
const OUT = path.join(ROOT, 'extension-public');

/** 공개본에서 빠지는 것들. */
const DROP_FILES = ['content/admin-bridge.js', 'content/console-bridge.js'];
const DROP_DIRS = ['internal'];
const DROP_PERMISSIONS = ['alarms'];
const DROP_HOSTS = [
    'http://127.0.0.1:8000/*',
    'http://localhost:8000/*',
    'https://m.search.naver.com/*',
    'https://s.search.naver.com/*',
];
/** 이 문자열이 공개본 JS 에 남아 있으면 빌드를 실패시킨다(유출 방지 마지막 방어선). */
const FORBIDDEN = [
    'drainShopRankQueue', 'drainShopKeywordProductQueue', 'drainShopKeywordCheckQueue',
    'bulkShopStart', 'collectShopSerp', 'solveQuiz', 'sellerCaptcha', 'rfWorkerId',
    'chrome.alarms', 'shop-rank/claim', 'quiz/solve',
];

function copyDir(from, to, rel = '') {
    fs.mkdirSync(to, { recursive: true });
    for (const e of fs.readdirSync(from, { withFileTypes: true })) {
        const r = rel ? `${rel}/${e.name}` : e.name;
        if (e.isDirectory()) {
            if (DROP_DIRS.includes(r)) continue;
            copyDir(path.join(from, e.name), path.join(to, e.name), r);
        } else {
            if (DROP_FILES.includes(r)) continue;
            if (e.name.endsWith('.md')) continue;   // 개발 문서는 공개 패키지에 넣지 않는다
            fs.copyFileSync(path.join(from, e.name), path.join(to, e.name));
        }
    }
}

fs.rmSync(OUT, { recursive: true, force: true });
copyDir(SRC, OUT);

// background.js 끝의 오버레이 로드 블록 제거 — 파일이 없으면 서비스워커가 부팅에 실패한다.
const bgPath = path.join(OUT, 'background.js');
let bg = fs.readFileSync(bgPath, 'utf8');
const marker = '\n\n// ── 사내 전용 오버레이 ';
const at = bg.indexOf(marker);
if (at === -1) {
    console.error('❌ background.js 에서 오버레이 로드 블록을 찾지 못했습니다. extension/background.js 끝을 확인하세요.');
    process.exit(1);
}
bg = bg.slice(0, at) + '\n';
fs.writeFileSync(bgPath, bg);

const manifest = JSON.parse(fs.readFileSync(path.join(OUT, 'manifest.json'), 'utf8'));
manifest.permissions = manifest.permissions.filter((p) => !DROP_PERMISSIONS.includes(p));
manifest.host_permissions = manifest.host_permissions.filter((h) => !DROP_HOSTS.includes(h));
manifest.content_scripts = manifest.content_scripts
    .filter((c) => !(c.js || []).some((j) => DROP_FILES.includes(j)));
fs.writeFileSync(path.join(OUT, 'manifest.json'), JSON.stringify(manifest, null, 4) + '\n');

// 유출 검사 — 공개본의 모든 JS 를 훑는다.
const leaks = [];
(function scan(dir) {
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
        const p = path.join(dir, e.name);
        if (e.isDirectory()) { scan(p); continue; }
        if (!e.name.endsWith('.js')) continue;
        const t = fs.readFileSync(p, 'utf8');
        for (const f of FORBIDDEN) if (t.includes(f)) leaks.push(`${path.relative(OUT, p)} → ${f}`);
    }
})(OUT);
if (leaks.length) {
    console.error('❌ 공개본에 사내 코드가 남아 있습니다:\n   ' + leaks.join('\n   '));
    process.exit(1);
}

console.log(`✅ 공개 배포본 v${manifest.version} → ${OUT}`);
console.log(`   권한: ${manifest.permissions.join(', ')}`);
console.log(`   host_permissions ${manifest.host_permissions.length}개 · content_scripts ${manifest.content_scripts.length}개`);
console.log('   ⚠️ 이 디렉터리는 git 에 커밋해야 운영 서버의 [웹스토어에 게시] 버튼이 최신본을 올립니다.');
