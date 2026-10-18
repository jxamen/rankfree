<?php
if (!defined('_GNUBOARD_') || !$is_admin || $campaign_status != 'waiting') return;
if (empty($_SESSION['rankfree_batch_token'])) $_SESSION['rankfree_batch_token'] = bin2hex(random_bytes(24));
// Same filters/order as the list, without its 50-row page limit. Freeze order before updates.
$rf_orders = array();
// Read existing progress only; opening the list never starts analysis or creates links.
$rf_analyses = array(); $rf_read_ok = true; $rf_last_page = 1;
for ($rf_page = 1; $rf_page <= $rf_last_page; $rf_page++) {
    $rf_response = rankfree_api_call('GET', "/shop-keywords?page={$rf_page}&per_page=100");
    if (isset($rf_response['error']) || !isset($rf_response['analyses'])) { $rf_read_ok = false; break; }
    $rf_analyses = array_merge($rf_analyses, $rf_response['analyses']);
    $rf_last_page = max(1, (int)ceil(($rf_response['total'] ?? 0) / 100));
}
$rf_details = array();
$rf_result = sql_query("select bc.no, bc.product_name, bc.keyword, bc.link, bc.fr_date, bc.to_date {$sql_common} {$sql_search} {$sql_order}");
while ($rf_row = sql_fetch_array($rf_result)) {
    $rf_existing = null; $rf_product_id = '';
    if (preg_match('#/([0-9]{6,})(?:[/?\#]|$)#', trim($rf_row['link']), $rf_match)) $rf_product_id = $rf_match[1];
    foreach ($rf_analyses as $rf_analysis) {
        if (trim($rf_analysis['core_keyword'] ?? '') !== trim($rf_row['keyword'])) continue;
        if (trim($rf_analysis['product_url'] ?? '') === trim($rf_row['link']) || ($rf_product_id !== '' && ($rf_analysis['product_id'] ?? '') == $rf_product_id)) { $rf_existing = $rf_analysis; break; }
    }
    $rf_top = $rf_read_ok ? 0 : null; $rf_links = $rf_read_ok ? 0 : null;
    if ($rf_existing) {
        $rf_top = $rf_existing['top_five_count'] ?? null; $rf_links = null;
        $rf_id = (int)$rf_existing['id'];
        if (!isset($rf_details[$rf_id])) $rf_details[$rf_id] = rankfree_api_call('GET', "/shop-keywords/{$rf_id}");
        $rf_detail = $rf_details[$rf_id];
        if (isset($rf_detail['analysis']['top_five_count'])) $rf_top = $rf_detail['analysis']['top_five_count'];
        if (isset($rf_detail['short_links']) && is_array($rf_detail['short_links'])) $rf_links = count($rf_detail['short_links']);
    }
    $rf_period = rankfree_order_period($rf_row);
    $rf_orders[] = array('no' => (int)$rf_row['no'], 'name' => $rf_row['product_name'] ?: $rf_row['keyword'],
        'top_five_count' => $rf_top, 'short_link_cnt' => $rf_links,
        'days' => $rf_period ? $rf_period['order_days'] : null,
        'target' => $rf_period ? 50 * $rf_period['order_days'] : null,
        'url_goal' => $rf_period ? $rf_period['order_days'] : null,
        'url_limit' => $rf_period ? $rf_period['group_count'] : null);
}
?>
<div class="card p-3 mb-3" id="rf-batch">
    <div class="d-flex flex-wrap align-items-center gap-2">
        <button type="button" class="btn btn-primary" id="rf-start">전체 유입키워드 분석 시작</button>
        <button type="button" class="btn btn-outline-primary" id="rf-resume" hidden>유입키워드 분석 이어서</button>
        <button type="button" class="btn btn-outline-secondary" id="rf-pause" disabled>현재 건 후 일시정지</button>
        <label>실행 건수 <input id="rf-limit" type="number" min="1" max="<?php echo max(1, count($rf_orders)); ?>" value="<?php echo count($rf_orders); ?>" style="width:80px" aria-label="실행 건수"></label>
    </div>
    <div class="small mt-2">현재 검색·정렬의 전체 페이지를 순서대로 처리합니다. 랭크프리 확장을 켜 주세요. 네이버 막힘은 풀릴 때까지 같은 건에서 대기합니다. 연결 오류는 30초 뒤 최대 10회 재시도하며, 실패하면 일괄 처리를 멈춥니다. 이 화면을 열어 두세요. 닫거나 새로고침한 경우 ‘유입키워드 분석 이어서’를 누르면 저장된 순서로 계속합니다.</div>
    <div id="rf-summary" class="mt-2" role="status" aria-live="polite">대기 <?php echo count($rf_orders); ?>건 · 목표 합계 <?php echo array_sum(array_column($rf_orders, 'target')); ?>개 · 단축 URL 합계 <?php echo array_sum(array_column($rf_orders, 'url_goal')); ?>개</div>
    <details class="mt-2" open><summary>건별 처리 상태</summary><ol id="rf-log" style="max-height:300px;overflow:auto"><?php foreach ($rf_orders as $rf_item) { ?><li><?php echo htmlspecialchars('#'.$rf_item['no'].' '.$rf_item['name'].' · 광고 '.($rf_item['days'] === null ? '기간 확인 필요' : $rf_item['days'].'일').' · 최대 조합(목표) '.($rf_item['target'] ?? '—').'개 · 지금까지 5위 이내 '.($rf_item['top_five_count'] ?? '조회 불가').' · 만들 단축 URL '.($rf_item['url_goal'] ?? '—').'개 / 만든 수 '.($rf_item['short_link_cnt'] ?? '조회 불가').' · 대기', ENT_QUOTES, 'UTF-8'); ?></li><?php } ?></ol></details>
</div>
<script>
(function () {
    const orders = <?php echo json_encode($rf_orders, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const token = <?php echo json_encode($_SESSION['rankfree_batch_token']); ?>;
    const key = 'rankfree-batch-v1-<?php echo (int)$member['mb_no']; ?>';
    const $ = id => document.getElementById(id);
    let job = null, running = false, stop = false;
    try { job = JSON.parse(localStorage.getItem(key)); } catch (_) {}
    if (!job || !Array.isArray(job.items)) job = null;
    function hydrate() {
        if (!job) return;
        job.items.forEach(item => { const latest = orders.find(x => x.no === item.no); if (latest) Object.assign(item, latest); });
    }
    hydrate();
    function save() {
        // Keep the live cursor stable during async processing; persist only waiting rows.
        const saved = job && {...job, items:job.items.filter(x => !x.skipped),
            index:job.items.slice(0, job.index).filter(x => !x.skipped).length};
        localStorage.setItem(key, JSON.stringify(saved));
    }
    function render() {
        $('rf-resume').hidden = !job || (job.index >= job.items.length && !job.items.some(x => x.failed && !x.skipped));
        $('rf-start').disabled = running;
        $('rf-resume').disabled = running;
        $('rf-limit').disabled = running;
        $('rf-pause').disabled = !running;
        $('rf-log').replaceChildren();
        const items = (job ? job.items : orders).filter(x => !x.skipped);
        const processed = job ? job.items.slice(0, job.index).filter(x => !x.skipped).length : 0;
        const totals = '대기 ' + (job ? Math.max(0, items.length - processed) : items.length) + '건 · 목표 합계 ' + items.reduce((n,x) => n + (x.target || 0), 0) + '개 · 단축 URL 합계 ' + items.reduce((n,x) => n + (x.url_goal || 0), 0) + '개';
        $('rf-summary').textContent = totals;
        if (job) {
        const current = job.items[job.index];
        $('rf-summary').textContent = totals + ' · ' + items.length + '건 중 ' + processed + '건 처리 · ' +
            (running && current ? '현재 #' + current.no + ' ' + current.name : (current ? '일시정지 · 유입키워드 분석 이어서 가능' : '전체 처리 종료'));
        }
        items.forEach(item => {
            const li = document.createElement('li');
            li.textContent = '#' + item.no + ' ' + item.name + ' · ' + '광고 ' + (item.days == null ? '기간 확인 필요' : item.days + '일') + ' · 최대 조합(목표) ' + (item.target ?? '—') + '개 · 지금까지 5위 이내 ' + (item.top_five_count ?? '—') + ' · 만들 단축 URL ' + (item.url_goal ?? '—') + '개 / 만든 수 ' + (item.short_link_cnt ?? '—') + (item.days > item.url_limit ? ' (현재 생성 상한 ' + item.url_limit + '개)' : '') + ' · ' + (item.message || '대기');
            if (item.failed) li.className = 'text-danger';
            $('rf-log').appendChild(li);
        });
    }
    async function request(action, item) {
        const body = new URLSearchParams({action, campaign_no:item.no, rankfree_batch:'1', batch_token:token});
        const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 150000);
        try {
            const response = await fetch('/ads/ajax.action.php', {method:'POST', body, signal:controller.signal, credentials:'same-origin'});
            if (!response.ok) throw Object.assign(new Error('HTTP ' + response.status), {retryable:response.status >= 500 || [408,429].includes(response.status)});
            const result = await response.json();
            if (result.order_skipped) { item.skipped = true; item.failed = false; save(); render(); throw Object.assign(new Error('상태 변경 — 건너뜀'), {orderSkipped:true}); }
            if (result.status !== 100) throw Object.assign(new Error(result.result || 'API 요청 실패'), {retryable:!!result.retryable});
            if (Object.prototype.hasOwnProperty.call(result, 'top_five_count')) item.top_five_count = result.top_five_count;
            if (Object.prototype.hasOwnProperty.call(result, 'short_link_cnt')) item.short_link_cnt = result.short_link_cnt;
            if (result.analysis_status === 'missing') { item.top_five_count = 0; item.short_link_cnt = 0; }
            save(); render();
            return result;
        } finally { clearTimeout(timer); }
    }
    const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
    function connectionError(error) {
        return error.retryable || error.name === 'AbortError' || error.name === 'TypeError' || error.name === 'SyntaxError' ||
            /Failed to fetch|Server Error|Bad Gateway|Service Unavailable|network|timeout|timed out|시간 초과|타임아웃|연결하지 못|응답을 해석하지 못|목록 조회 실패|HTTP 5\d\d/i.test(error.message);
    }
    async function retry(item, operation) {
        for (let attempt = 0; ; attempt++) {
            try { return await operation(); }
            catch (error) {
                if (error.orderSkipped || !connectionError(error) || attempt >= 10) throw error;
                note(item, '연결 오류 — 30초 뒤 같은 건 재시도 (' + (attempt + 1) + '/10)');
                await sleep(30000);
            }
        }
    }
    async function call(action, item) { return retry(item, () => request(action, item)); }
    async function refreshStatus() {
        const items = [...orders, ...(job ? job.items : [])];
        if (!items.length) return;
        const body = new URLSearchParams({action:'rankfree_batch_status', rankfree_batch:'1', batch_token:token, campaign_nos:items.map(x => x.no).join(',')});
        const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 150000);
        let response, result;
        try {
            response = await fetch('/ads/ajax.action.php', {method:'POST', body, signal:controller.signal, credentials:'same-origin'});
            if (!response.ok) throw Object.assign(new Error('주문 상태 조회 실패: HTTP ' + response.status), {retryable:response.status >= 500 || [408,429].includes(response.status)});
            result = await response.json();
        } finally { clearTimeout(timer); }
        if (result.status !== 100 || !Array.isArray(result.waiting)) throw new Error(result.result || '주문 상태 조회 실패');
        items.forEach(item => {
            if (!result.waiting.includes(item.no)) { item.skipped = true; item.failed = false; }
        });
        for (let i = orders.length - 1; i >= 0; i--) {
            if (orders[i].skipped) orders.splice(i, 1);
        }
        document.querySelectorAll('[data-rf-order]').forEach(row => {
            if (!result.waiting.includes(Number(row.dataset.rfOrder))) row.remove();
        });
        $('rf-limit').max = Math.max(1, orders.length);
        $('rf-limit').value = Math.min(Number($('rf-limit').value), orders.length);
        save(); render();
    }
    function note(item, message) { item.message = message; save(); render(); }
    async function process(item) {
        // Always inspect first, including after interrupted start/link requests.
        note(item, '기존 분석·단축 URL 확인');
        let result = await call('rankfree_analysis_result', item);
        if (result.short_link_cnt > 0) { note(item, '건너뜀 · 기존 분석 / 단축 URL ' + result.short_link_cnt + '개'); return; }
        if (result.analysis_status !== 'missing' && !Object.prototype.hasOwnProperty.call(result, 'keyword_target')) throw new Error('랭크프리 상한 기능 반영 확인 필요');
        // 재사용 분석에도 이번 주문 기간과 목표를 전달한다(생성 API는 멱등).
        if (!['paused', 'blocked', 'captcha_wait'].includes(result.analysis_status)) {
            note(item, '분석 시작 · 주문 기간/목표 전달');
            result = await call('rankfree_analysis_start', item);
        }
        result = await call('rankfree_analysis_result', item);
        if (!result.keyword_target) throw new Error('랭크프리 목표 상한 기능 반영 대기 · PR 병합 후 다시 실행해 주세요.');
        while (!['done', 'target_reached'].includes(result.analysis_status)) {
            const now = Date.now();
            const checked = Number(result.checked || 0);
            // A rise in checked proves that Rankfree saved results, including non-exposed keywords.
            if (item.analysisId === result.analysis_id && checked > (item.lastChecked || 0)) item.lastResultAt = now;
            if (item.analysisId !== result.analysis_id) item.lastResultAt = 0;
            item.analysisId = result.analysis_id; item.lastChecked = checked;
            const savedAt = Number(result.last_result_at_ms || 0);
            if (savedAt > 0 && savedAt <= now) item.lastResultAt = Math.max(item.lastResultAt || 0, savedAt);
            const blocked = ['paused', 'blocked', 'captcha_wait'].includes(result.analysis_status) || result.analysis_blocked === true;
            if (blocked) {
                note(item, '네이버 막힘 — 풀리면 이 건부터 이어서');
            } else {
                if (['failed', 'error', 'cancelled'].includes(result.analysis_status)) throw new Error('분석 상태: ' + result.analysis_status + ' · 랭크프리에서 확인해 주세요.');
                const active = item.lastResultAt > 0 && now - item.lastResultAt <= 120000;
                if (!active) note(item, '결과 저장 대기 · 랭크프리 자동 복구를 기다리는 중');
                if (active) note(item, '분석 대기 ' + checked + '/' + (result.total || 0) + ' · 5위 이내 ' + (result.top_five_count || 0) + '/' + result.keyword_target + ' · ' + result.order_days + '일');
            }
            await sleep(10000);
            result = await call('rankfree_analysis_result', item);
        }
        if (result.short_link_cnt > 0) { note(item, '건너뜀 · 분석 완료 / 기존 단축 URL ' + result.short_link_cnt + '개'); return; }
        if (!result.exposed_cnt) throw new Error('분석 완료했으나 노출 키워드가 없어 단축 URL을 생성할 수 없습니다.');
        note(item, '주문 기간(시작~종료일 포함, 최대 100일)으로 단축 URL 생성');
        await call('rankfree_short_links', item);
        result = await call('rankfree_analysis_result', item);
        if (!result.short_link_cnt) throw new Error('단축 URL 생성 결과가 비어 있습니다.');
        note(item, '완료 · 단축 URL ' + result.short_link_cnt + '개 저장');
    }
    async function run(fresh) {
        if (running) return;
        if (!navigator.locks) { $('rf-summary').textContent = 'Chrome HTTPS 화면에서 실행해 주세요.'; return; }
        await navigator.locks.request(key, {ifAvailable:true}, async lock => {
            if (!lock) { $('rf-summary').textContent = '다른 탭에서 처리 중입니다.'; return; }
            if (fresh) {
                const limit = Math.max(1, Math.min(orders.length, parseInt($('rf-limit').value, 10) || orders.length));
                job = {items:orders.slice(0, limit).map(x => ({...x})), index:0};
            } else {
                try { job = JSON.parse(localStorage.getItem(key)); } catch (_) {}
            }
            hydrate();
            if (!job) return;
            if (!fresh) {
                job.items.forEach(item => { if (item.failed && !item.skipped) { item.failed = false; item.message = '대기'; } });
                // Revisit all unfinished rows, including failures saved beyond the old index.
                job.index = job.items.findIndex(item => !item.skipped && !/^(완료|건너뜀)/.test(item.message || ''));
                if (job.index < 0) job.index = job.items.length;
            }
            running = true; stop = false;
            try {
                save(); render();
                while (job.index < job.items.length) {
                    const item = job.items[job.index];
                    try { await retry(item, refreshStatus); if (!item.skipped && !/^(완료|건너뜀)/.test(item.message || '')) await process(item); }
                    catch (error) { if (!error.orderSkipped) { item.failed = true; note(item, '실패 · ' + (error.name === 'AbortError' ? '요청 시간 초과 · 이어서 실행 시 기존 결과부터 확인합니다.' : error.message)); } }
                    if (item.failed) { save(); render(); }  // 실패 건은 빨간 줄로 남기고 다음 주문 계속(대표님 10/18 「연속 처리를 해야하는데」)
                    job.index++; save(); render();
                    if (stop) break;
                }
            } finally { running = false; render(); }
        });
    }
    $('rf-start').addEventListener('click', () => run(true).catch(e => { $('rf-summary').textContent = e.message; }));
    $('rf-resume').addEventListener('click', () => run(false).catch(e => { $('rf-summary').textContent = e.message; }));
    $('rf-pause').addEventListener('click', () => { stop = true; $('rf-pause').disabled = true; });
    render();
    refreshStatus().catch(e => { $('rf-summary').textContent = e.message; });
    // The approval handlers use jQuery AJAX, including the bulk approval button.
    if (window.jQuery) window.jQuery(document).ajaxComplete(function (_event, xhr, settings) {
        const data = typeof settings.data === 'string' ? settings.data : new URLSearchParams(settings.data || {}).toString();
        if (/action=ads_status_change(?:_only)?(?:&|$)/.test(data)) {
            refreshStatus().catch(e => { $('rf-summary').textContent = e.message; });
        }
    });
})();
</script>
