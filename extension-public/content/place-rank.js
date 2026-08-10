/**
 * RankFree — 네이버 지도(map.naver) 플레이스 리스트 순위 배지.
 * pcmap.place.naver.com/{cat}/list 리스트 iframe에 주입되어,
 * 각 업체 카드에 "검색 순위 N위" 배지를 붙인다.
 *
 * 순위는 서버(/api/ext/place-serp)가 pcmap GraphQL로 조회한 오가닉 순위
 * (광고 제외 · 서울 고정 좌표 — 기존 순위체크와 동일 기준).
 * 리스트 DOM에 placeId가 없으므로 업체명으로 매칭한다.
 * 스크롤 시 li가 동적 추가되므로 MutationObserver로 새 카드에도 배지를 주입한다.
 */
(function () {
  'use strict';
  if (window.__rfPlaceRank) return;
  window.__rfPlaceRank = true;

  const CATS = ['restaurant', 'place', 'hospital', 'hairshop', 'nailshop', 'accommodation'];
  // 업체명 정규화 — 공백·구두점·괄호 제거 후 소문자(양쪽 소스가 모두 네이버라 거의 일치)
  const normName = (s) =>
    String(s || '').toLowerCase().replace(/\s+/g, '').replace(/[·.,()[\]'"‘’“”\-–—&/]/g, '');

  let curKey = '';    // 현재 query|cat (재검색 감지)
  let rankMap = null; // normName -> rank
  let loading = false;
  let failed = false;
  let loadedTop = 0;  // 지금까지 조회한 순위 범위
  let noGain = false; // 조회 범위를 넓혀도 매칭이 안 늘면(순위권 밖) 더 넓히지 않음

  function ctx() {
    const m = location.pathname.match(/^\/([a-z]+)\/list/i);
    const cat = m && CATS.includes(m[1]) ? m[1] : 'place';
    const query = new URLSearchParams(location.search).get('query') || '';
    return { cat, query };
  }

  function sendBg(type, payload) {
    return new Promise((resolve) => {
      try {
        chrome.runtime.sendMessage({ type, payload }, (res) => {
          if (chrome.runtime.lastError) { resolve({ ok: false }); return; }
          resolve(res || { ok: false });
        });
      } catch (e) { resolve({ ok: false }); }
    });
  }

  async function loadRanks() {
    const { cat, query } = ctx();
    if (!query) return;
    const key = query + '|' + cat;
    if (key !== curKey) { // 재검색·카테고리 변경 → 초기화
      curKey = key; rankMap = null; loadedTop = 0; failed = false; noGain = false;
      clearBadges();
    }
    if (loading) return;
    // 현재 페이지의 오가닉 업체 중 아직 매칭 안 된 게 있으면 조회 범위를 넓힌다(300개를 한 번에 뒤지지 않음).
    const names = listItems().filter((li) => !isAd(li)).map((li) => normName(nameOf(li))).filter(Boolean);
    const unmatched = rankMap ? names.filter((n) => !rankMap.has(n)).length : names.length;
    if (rankMap && (unmatched === 0 || noGain || loadedTop >= 300) && !failed) { applyBadges(); return; }
    const wantTop = Math.min(300, (loadedTop || 0) + 50); // +50씩(1페이지 단위) 확장
    loading = true;
    const res = await sendBg('placeSerp', { keyword: query, cat, top: wantTop });
    loading = false;
    if (!res || !res.ok || !Array.isArray(res.items)) { failed = true; return; }
    failed = false;
    rankMap = new Map();
    res.items.forEach((it) => {
      const k = normName(it.name);
      if (k && !rankMap.has(k)) rankMap.set(k, it.rank);
    });
    loadedTop = wantTop;
    // 넓혔는데도 매칭 안 된 수가 안 줄면 남은 건 순위권 밖 → 더 넓히지 않음
    const nowUnmatched = names.filter((n) => !rankMap.has(n)).length;
    noGain = nowUnmatched > 0 && nowUnmatched >= unmatched;
    applyBadges();
  }

  function listItems() { return [...document.querySelectorAll('li.UEzoS')]; }

  // 업체명 요소 — pcmap 리스트의 상호 텍스트(카테고리별 클래스 변동에 대비해 다중 후보)
  function nameOf(li) {
    const el = li.querySelector('.YwYLL, .TYaxT, .place_bluelink, span[class*="TYaxT"], span[class*="YwYLL"]');
    if (el && el.textContent) return el.textContent;
    const a = li.querySelector('a[role="button"] span, a span, a');
    return a ? a.textContent || '' : '';
  }
  const isAd = (li) => /광고/.test(li.textContent || '');

  function badgeEl(li) {
    let b = li.querySelector(':scope .rf-rank-badge');
    if (!b) {
      b = document.createElement('span');
      b.className = 'rf-rank-badge';
      // 업체명 텍스트 바로 앞에 인라인 삽입(이름을 가리지 않도록)
      const nameEl = li.querySelector('.YwYLL, .TYaxT, .place_bluelink, span[class*="TYaxT"], span[class*="YwYLL"]');
      if (nameEl && nameEl.parentNode) nameEl.parentNode.insertBefore(b, nameEl);
      else li.insertBefore(b, li.firstChild);
    }
    return b;
  }

  function applyBadges() {
    if (!rankMap) return;
    listItems().forEach((li) => {
      const rank = rankMap.get(normName(nameOf(li)));
      const ad = isAd(li);
      const b = badgeEl(li);
      b.classList.remove('rf-rank-ad', 'rf-rank-none');
      if (ad) {
        b.classList.add('rf-rank-ad');
        b.textContent = rank ? rank + '위' : '광고';
        b.title = rank ? ('오가닉 순위 ' + rank + '위 · 현재 광고 노출') : '광고 노출';
      } else if (rank) {
        b.textContent = rank + '위';
        b.title = '검색 순위 ' + rank + '위 (광고 제외 · 서울 기준)';
      } else {
        b.classList.add('rf-rank-none');
        b.textContent = '–';
        b.title = '상위 100위 밖';
      }
    });
  }

  function clearBadges() {
    document.querySelectorAll('.rf-rank-badge').forEach((b) => b.remove());
  }

  // 리스트 변경(스크롤 동적 로드)·재검색을 300ms 디바운스로 처리
  let scheduled = false;
  function schedule() {
    if (scheduled) return;
    scheduled = true;
    setTimeout(() => {
      scheduled = false;
      loadRanks(); // 캐시 충분하면 배지만, 스크롤로 리스트가 늘었으면 확장 조회
    }, 300);
  }

  function start() {
    loadRanks();
    new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    // pcmap 내부 SPA로 query만 바뀌는 경우(iframe 리로드 없이) 대응
    let lastHref = location.href;
    setInterval(() => {
      if (location.href !== lastHref) { lastHref = location.href; loadRanks(); }
    }, 1000);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
