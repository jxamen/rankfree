<?php

namespace App\Domain\Place;

/**
 * 스마트플레이스 수집 리포트 렌더러 — crm ads/smartplace/report.php 이식.
 * last_result JSON → 5탭(리포트/플레이스/스마트콜/예약·주문/리뷰) HTML 섹션 생성.
 * 색상은 전부 디자인 토큰(var(--color-*)) — 하드코딩 hex 금지 규칙 준수.
 */
class SmartplaceReportPresenter
{
    private const C_MAIN = 'var(--color-accent)';
    private const C_GREEN = 'var(--color-success)';
    private const C_VIOLET = 'var(--color-badge-violet)';
    private const C_PINK = 'var(--color-badge-pink)';

    /** @return array<int, array{key:string,label:string,html:string}> */
    public static function tabs(array $result): array
    {
        $S = is_array($result['sections'] ?? null) ? $result['sections'] : [];
        $stats = is_array($S['stats'] ?? null) ? $S['stats'] : [];

        $dt = self::statData($stats, 'date_time');
        $placeIn = self::col($dt, 'pv');
        $rvTot = self::get($S, 'review_visitor.data.data.reviews.totalCount', 0);
        $blTot = self::get($S, 'review_blog.data.data.fsasReviews.total', 0);
        $bkCnt = self::get($S, 'booking_users.data.businessUserCount', '-');
        $scTot = self::get($S, 'smartcall_count.data.total', 0);

        // ── 리포트(요약) ──
        $tabReport = '<div class="sp-panel"><h3>방문 전 지표</h3><div class="sp-mrow">'
            .self::metric('플레이스 유입', self::n($placeIn), '회')
            .self::metric('예약·주문 고객', self::n($bkCnt === '-' ? 0 : $bkCnt), '명')
            .self::metric('스마트콜 통화(오늘)', self::n($scTot), '회')
            .'</div></div>'
            .'<div class="sp-panel"><h3>방문 후 지표</h3><div class="sp-mrow">'
            .self::metric('방문자 리뷰(누적)', self::n($rvTot), '건')
            .self::metric('블로그·카페 리뷰', self::n($blTot), '건')
            .'</div></div>'
            .'<div class="sp-cards"><div class="sp-card"><h4>유입 채널</h4>'.self::hbars(self::statData($stats, 'channel'), 'mapped_channel_name', 'pv', [self::C_GREEN, self::C_MAIN, self::C_VIOLET]).'</div>'
            .'<div class="sp-card"><h4>유입 검색어</h4>'.self::hbars(array_slice(self::statData($stats, 'keyword'), 0, 8), 'ref_keyword', 'pv').'</div></div>'
            .'<p class="sp-note">※ 전주 대비 증감·예약 매출 등은 예약 세부지표 연결 시 표시됩니다.</p>';

        // ── 플레이스(통계) ──
        $ag = self::age(self::statData($stats, 'age_gender'));
        $pyr = '<div class="sp-pyr">';
        foreach ($ag['arr'] as $a) {
            $pyr .= '<div class="sp-pyrow"><span class="sp-pv" style="color:'.self::C_MAIN.'">'.$a['mp'].'%</span>'
                .'<span class="sp-pbar"><span class="sp-pfill" style="width:'.$a['mw'].'%;background:'.self::C_MAIN.'"></span></span>'
                .'<span class="sp-pbk">'.e($a['bucket']).'</span>'
                .'<span class="sp-pbar r"><span class="sp-pfill" style="width:'.$a['fw'].'%;background:'.self::C_PINK.'"></span></span>'
                .'<span class="sp-pv" style="color:'.self::C_PINK.'">'.$a['fp'].'%</span></div>';
        }
        $pyr .= '</div>';
        $tabPlace = '<div class="sp-cards">'
            .'<div class="sp-card"><h4>유입 수 <b>'.self::n($placeIn).'회</b></h4>'.self::vbar($dt, 'date_time', 'pv', self::C_MAIN).'</div>'
            .'<div class="sp-card"><h4>유입 채널</h4>'.self::hbars(self::statData($stats, 'channel'), 'mapped_channel_name', 'pv', [self::C_GREEN, self::C_MAIN, self::C_VIOLET]).'</div>'
            .'<div class="sp-card"><h4>시간대별</h4>'.self::line(self::statData($stats, 'hour'), 'hour_all', 'pv', self::C_MAIN).'</div>'
            .'<div class="sp-card"><h4>요일별</h4>'.self::vbar(self::statData($stats, 'dow'), 'day_of_week', 'pv', self::C_GREEN).'</div>'
            .'<div class="sp-card"><h4>유입 검색어</h4>'.self::hbars(array_slice(self::statData($stats, 'keyword'), 0, 10), 'ref_keyword', 'pv').'</div>'
            .'<div class="sp-card"><h4>성별·연령</h4><div class="sp-genderline"><b style="color:'.self::C_MAIN.'">'.$ag['malePct'].'% 남</b> <b style="color:'.self::C_PINK.'">여 '.$ag['femalePct'].'%</b></div>'.$pyr.'</div>'
            .'</div>';

        // ── 스마트콜 ──
        $cc = self::get($S, 'smartcall_count.data', null);
        $cc = is_array($cc) ? $cc : null;
        $ccLabel = ['total' => '전체', 'success' => '연결', 'absence' => '부재', 'arsOnly' => 'ARS', 'busy' => '통화중', 'blocked' => '차단'];
        $ccBlock = '<div class="sp-empty">데이터 없음</div>';
        if (is_array($cc)) {
            $ccBlock = '<div class="sp-badges">';
            foreach ($ccLabel as $k => $v) {
                $ccBlock .= '<span class="sp-metric"><b>'.self::n($cc[$k] ?? 0).'</b>'.$v.'</span>';
            }
            $ccBlock .= '</div>';
        }
        $callers = self::get($S, 'smartcall_callers.data.docs', null);
        $callerRows = [];
        foreach (is_array($callers) ? $callers : [] as $d) {
            $callerRows[] = [
                '발신번호' => $d['callerTel'] ?? '',
                '통화수' => $d['callCount'] ?? '',
                '최근통화' => isset($d['lastCsTime']) ? substr(str_replace('T', ' ', $d['lastCsTime']), 0, 16) : '',
            ];
        }
        $tabCall = '<div class="sp-panel"><h3>통화 통계 (오늘)</h3>'.$ccBlock.'</div>'
            .'<div class="sp-card"><h4>발신자 <small>총 '.self::n(self::get($S, 'smartcall_callers.data.total', 0)).'명</small></h4>'.self::tbl($callerRows, ['발신번호', '통화수', '최근통화']).'</div>';

        // ── 예약·주문 (예약 고객 데이터 기반 세부지표: 요약/유입경로/고객분석) ──
        $tabBooking = self::bookingTab($S, $bkCnt);

        // ── 리뷰 ──
        $rvItems = self::get($S, 'review_visitor.data.data.reviews.items', null);
        $rvBlock = is_array($rvItems) && count($rvItems) ? '' : '<div class="sp-empty">데이터 없음</div>';
        foreach (array_slice(is_array($rvItems) ? $rvItems : [], 0, 25) as $it) {
            $rt = isset($it['rating']) ? (int) round($it['rating']) : (int) round($it['content']['rating'] ?? 0);
            $visit = substr((string) ($it['visitDateTime'] ?? $it['createdDateTime'] ?? ''), 0, 10);
            $rvBlock .= '<div class="sp-review"><b>'.e($it['author']['displayName'] ?? '').'</b> <span class="sp-star">'.str_repeat('★', max(0, $rt)).'</span>'
                .' <small>방문 '.e($visit).'</small><div>'.e($it['content']['text'] ?? '(사진/키워드 리뷰)').'</div></div>';
        }
        $blItems = self::get($S, 'review_blog.data.data.fsasReviews.items', null);
        $blBlock = is_array($blItems) && count($blItems) ? '' : '<div class="sp-empty">데이터 없음</div>';
        foreach (array_slice(is_array($blItems) ? $blItems : [], 0, 25) as $it) {
            $blBlock .= '<div class="sp-review"><span class="badge">'.e($it['type'] ?? '').'</span> '
                .'<a href="'.e($it['url'] ?? '#').'" target="_blank" rel="noopener">'.e($it['title'] ?? ($it['url'] ?? '')).'</a></div>';
        }
        $tabReview = '<h4 class="sp-sec">⭐ 방문자 리뷰 <small>총 '.self::n($rvTot).'건</small></h4><div class="sp-rvgrid">'.$rvBlock.'</div>'
            .'<h4 class="sp-sec">📝 블로그·카페 리뷰 <small>총 '.self::n($blTot).'건</small></h4><div class="sp-rvgrid">'.$blBlock.'</div>';

        return [
            ['key' => 'report', 'label' => '리포트', 'html' => $tabReport],
            ['key' => 'place', 'label' => '플레이스', 'html' => $tabPlace],
            ['key' => 'call', 'label' => '스마트콜', 'html' => $tabCall],
            ['key' => 'booking', 'label' => '예약·주문', 'html' => $tabBooking],
            ['key' => 'review', 'label' => '리뷰', 'html' => $tabReview],
        ];
    }

    // ── 예약·주문 탭 빌더 ──────────────────────────────────────────

    /**
     * 예약 세부지표 — 예약 고객 목록(booking_users)을 집계해 요약·유입경로·고객분석(성별·연령)을 렌더.
     * (매출·예약건수 통계는 별도 네이버 예약 통계 API 필요 — 데이터 없어 미표시)
     */
    private static function bookingTab(array $S, mixed $bkCnt): string
    {
        $bu = self::get($S, 'booking_users.data.businessUserList', null);
        $users = is_array($bu) ? $bu : [];
        $skip = self::get($S, 'booking_users.skip', '');
        $total = $bkCnt === '-' ? 0 : (int) $bkCnt;

        if (! count($users)) {
            $msg = is_string($skip) && $skip !== '' ? $skip : '예약 미사용 또는 데이터 없음';

            return '<div class="sp-panel"><h3>예약·주문 고객</h3><div class="sp-empty">'.e($msg).'</div></div>';
        }

        // 집계 — 성별·연령대·유입경로 분포
        $gender = self::countBy($users, 'sex');
        $age = self::countBy($users, 'ageGroup');
        $entry = self::countBy($users, 'initialEntry');
        $sampled = count($users);
        $male = ($gender['남성'] ?? 0) + ($gender['남'] ?? 0);
        $female = ($gender['여성'] ?? 0) + ($gender['여'] ?? 0);
        $gTot = $male + $female;
        $topAge = self::topKey($age);
        $topEntry = self::topKey($entry);

        // 요약 카드
        $summary = '<div class="sp-panel"><h3>예약·주문 요약</h3><div class="sp-mrow">'
            .self::metric('예약·주문 고객', self::n($total), '명')
            .self::metric('분석 표본', self::n($sampled), '명')
            .self::metric('여성 비율', $gTot ? (string) round($female / $gTot * 100) : '-', '%')
            .self::metric('최다 연령대', $topAge !== '' ? e($topAge) : '-', '')
            .self::metric('최다 유입', $topEntry !== '' ? e($topEntry) : '-', '')
            .'</div><p class="sp-note">※ 아래 분석은 최근 예약 고객 '.self::n($sampled).'명 표본 기준입니다.</p></div>';

        // 유입경로 · 고객분석(성별·연령) 카드
        $cards = '<div class="sp-cards">'
            .'<div class="sp-card"><h4>유입경로 <small>고객이 매장을 처음 접한 경로</small></h4>'
            .self::distBars($entry, [self::C_GREEN, self::C_MAIN, self::C_VIOLET, self::C_PINK]).'</div>'
            .'<div class="sp-card"><h4>연령대 분포</h4>'.self::distBars($age).'</div>'
            .'<div class="sp-card"><h4>성별 분포</h4>'.self::distBars($gender, [self::C_MAIN, self::C_PINK]).'</div>'
            .'<div class="sp-card"><h4>재방문·생일자</h4>'.self::bookingMiniStats($users).'</div>'
            .'</div>';

        // 고객 목록(개인정보 — 이름/전화는 표시하되 최대 40행)
        $rows = [];
        foreach (array_slice($users, 0, 40) as $u) {
            $rows[] = [
                '이름' => $u['name'] ?? '', '성별' => $u['sex'] ?? '', '연령' => $u['ageGroup'] ?? '',
                '전화' => $u['phone'] ?? '', '생일' => $u['birthday'] ?? '', '유입' => $u['initialEntry'] ?? '',
            ];
        }
        $list = '<h4 class="sp-sec">예약·주문 고객 <small>최근 '.self::n(count($rows)).'명</small></h4>'
            .self::tbl($rows, ['이름', '성별', '연령', '전화', '생일', '유입']);

        return $summary.$cards.$list;
    }

    /** 재방문·생일자 등 부가 미니 통계 (booking_users 필드에서 가능한 것만 방어적으로). */
    private static function bookingMiniStats(array $users): string
    {
        $revisit = 0;   // visitCount/bookingCount > 1
        $withBirth = 0; // 생일 등록 고객
        foreach ($users as $u) {
            $vc = (int) ($u['visitCount'] ?? $u['bookingCount'] ?? $u['reservationCount'] ?? 0);
            if ($vc > 1) {
                $revisit++;
            }
            if (trim((string) ($u['birthday'] ?? '')) !== '') {
                $withBirth++;
            }
        }
        $n = count($users);

        return '<div class="sp-badges">'
            .'<span class="sp-metric"><b>'.self::n($revisit).'</b>재방문 고객</span>'
            .'<span class="sp-metric"><b>'.($n ? round($withBirth / $n * 100) : 0).'%</b>생일 등록</span>'
            .'<span class="sp-metric"><b>'.self::n($withBirth).'</b>생일 확보</span>'
            .'</div>';
    }

    /** 배열의 한 필드 값별 개수 집계 (빈 값 제외). @return array<string,int> 내림차순 */
    private static function countBy(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $r) {
            $v = trim((string) ($r[$key] ?? ''));
            if ($v === '') {
                continue;
            }
            $out[$v] = ($out[$v] ?? 0) + 1;
        }
        arsort($out);

        return $out;
    }

    private static function topKey(array $counts): string
    {
        return $counts ? (string) array_key_first($counts) : '';
    }

    /** [label => count] 분포를 수평 막대로 (전체 대비 %). */
    private static function distBars(array $counts, ?array $colors = null): string
    {
        if (! count($counts)) {
            return '<div class="sp-empty">데이터 없음</div>';
        }
        $rows = [];
        foreach ($counts as $label => $cnt) {
            $rows[] = ['label' => $label, 'count' => $cnt];
        }

        return self::hbars($rows, 'label', 'count', $colors);
    }

    // ── 데이터 헬퍼 ────────────────────────────────────────────────

    private static function n(mixed $v): string
    {
        return $v === null || $v === '-' ? '-' : number_format((float) $v);
    }

    private static function col(array $arr, string $k): float
    {
        $t = 0;
        foreach ($arr as $b) {
            $t += (float) ($b[$k] ?? 0);
        }

        return $t;
    }

    /** 점 표기 경로로 중첩 배열 안전 접근 */
    private static function get(array $arr, string $path, mixed $def = '-'): mixed
    {
        $d = $arr;
        foreach (explode('.', $path) as $k) {
            if (is_array($d) && array_key_exists($k, $d)) {
                $d = $d[$k];
            } else {
                return $def;
            }
        }

        return is_scalar($d) || $d === null ? ($d ?? $def) : (is_array($d) ? $d : $def);
    }

    private static function statData(array $stats, string $k): array
    {
        return is_array($stats[$k]['data'] ?? null) ? $stats[$k]['data'] : [];
    }

    private static function metric(string $label, string $val, string $unit): string
    {
        return '<div class="sp-mtc"><span class="sp-mtl">'.e($label).'</span><span class="sp-mtv">'.$val.'<em>'.$unit.'</em></span></div>';
    }

    // ── 차트 빌더 (인라인 SVG) ─────────────────────────────────────

    private static function vbar(array $rows, string $lk, string $vk, string $color, int $w = 300, int $h = 130): string
    {
        if (! count($rows)) {
            return '<div class="sp-empty">데이터 없음</div>';
        }
        $max = 1;
        foreach ($rows as $r) {
            $max = max($max, (float) ($r[$vk] ?? 0));
        }
        $n = count($rows);
        $bw = $w / $n;
        $pad = $bw * 0.28;
        $out = '<svg viewBox="0 0 '.$w.' '.($h + 24).'" class="sp-chart">';
        $i = 0;
        foreach ($rows as $r) {
            $v = (float) ($r[$vk] ?? 0);
            $bh = $v / $max * $h;
            $full = (string) ($r[$lk] ?? '');
            $lbl = $full;
            if (strlen($lbl) > 6 && preg_match('/^\d{4}-/', $lbl)) {
                $lbl = substr($lbl, 5);
            }
            // 호버 시 값 표시 — 열 전체 투명 히트 rect + 커스텀 툴팁 데이터(report 뷰의 즉시 반응 툴팁이 읽음)
            $out .= '<g class="sp-hit" data-l="'.e($full).'" data-v="'.self::n($v).'" data-c="'.$color.'">'
                .'<rect x="'.round($i * $bw, 1).'" y="0" width="'.round($bw, 1).'" height="'.($h + 20).'" fill="transparent"/>'
                .'<rect class="sp-bar" x="'.round($i * $bw + $pad, 1).'" y="'.round($h - $bh, 1).'" width="'.round($bw - $pad * 2, 1).'" height="'.round($bh, 1).'" rx="2" fill="'.$color.'"/>'
                .'</g>';
            $out .= '<text x="'.round($i * $bw + $bw / 2, 1).'" y="'.($h + 15).'" text-anchor="middle" class="sp-axl">'.e($lbl).'</text>';
            $i++;
        }

        return $out.'</svg>';
    }

    private static function line(array $rows, string $lk, string $vk, string $color, int $w = 300, int $h = 120): string
    {
        if (! count($rows)) {
            return '<div class="sp-empty">데이터 없음</div>';
        }
        $max = 1;
        foreach ($rows as $r) {
            $max = max($max, (float) ($r[$vk] ?? 0));
        }
        $n = count($rows);
        $pts = [];
        $i = 0;
        foreach ($rows as $r) {
            $v = (float) ($r[$vk] ?? 0);
            $pts[] = [$i / max(1, $n - 1) * $w, $h - $v / $max * $h];
            $i++;
        }
        $d = '';
        foreach ($pts as $j => $p) {
            $d .= ($j ? 'L' : 'M').round($p[0], 1).' '.round($p[1], 1).' ';
        }
        $out = '<svg viewBox="0 0 '.$w.' '.($h + 22).'" class="sp-chart"><path d="'.$d.'" fill="none" stroke="'.$color.'" stroke-width="2"/>';
        $step = (int) ceil($n / 8);
        $i = 0;
        foreach ($rows as $r) {
            if ($i % $step === 0) {
                $out .= '<text x="'.round($i / max(1, $n - 1) * $w, 1).'" y="'.($h + 15).'" text-anchor="middle" class="sp-axl">'.e((string) ($r[$lk] ?? '')).'</text>';
            }
            $i++;
        }
        // 호버 시 값 표시 — 포인트마다 열 전체 투명 히트 rect + 커스텀 툴팁 데이터, 호버하면 점 확대
        $colW = $w / max(1, $n);
        $i = 0;
        foreach ($rows as $r) {
            $p = $pts[$i];
            $out .= '<g class="sp-hit" data-l="'.e((string) ($r[$lk] ?? '')).'" data-v="'.self::n((float) ($r[$vk] ?? 0)).'" data-c="'.$color.'">'
                .'<rect x="'.round($i * $colW, 1).'" y="0" width="'.round($colW, 1).'" height="'.$h.'" fill="transparent"/>'
                .'<circle cx="'.round($p[0], 1).'" cy="'.round($p[1], 1).'" r="2.2" fill="'.$color.'"/>'
                .'</g>';
            $i++;
        }

        return $out.'</svg>';
    }

    private static function hbars(array $rows, string $lk, string $vk, ?array $colors = null): string
    {
        if (! count($rows)) {
            return '<div class="sp-empty">데이터 없음</div>';
        }
        $tot = self::col($rows, $vk) ?: 1;
        $out = '<div class="sp-hbars">';
        $i = 0;
        foreach ($rows as $r) {
            $v = (float) ($r[$vk] ?? 0);
            $pct = $v / $tot * 100;
            $c = $colors[$i] ?? self::C_GREEN;
            $out .= '<div class="sp-hb"><span class="sp-hbl">'.($i + 1).'. '.e((string) ($r[$lk] ?? '')).'</span>'
                .'<span class="sp-hbtrack"><span class="sp-hbfill" style="width:'.round($pct, 1).'%;background:'.$c.'"></span></span>'
                .'<span class="sp-hbv">'.round($pct, 1).'% <em>'.self::n($v).'</em></span></div>';
            $i++;
        }

        return $out.'</div>';
    }

    private static function tbl(array $rows, ?array $cols = null): string
    {
        if (! count($rows)) {
            return '<div class="sp-empty">데이터 없음</div>';
        }
        $cols = $cols ?: array_keys($rows[0]);
        $h = '<div class="sp-tw"><table><thead><tr>';
        foreach ($cols as $c) {
            $h .= '<th>'.e($c).'</th>';
        }
        $h .= '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $h .= '<tr>';
            foreach ($cols as $c) {
                $val = $r[$c] ?? '';
                if (is_array($val)) {
                    $val = json_encode($val, JSON_UNESCAPED_UNICODE);
                }
                $h .= '<td>'.e((string) $val).'</td>';
            }
            $h .= '</tr>';
        }

        return $h.'</tbody></table></div>';
    }

    /** 연령·성별 피라미드 데이터 */
    private static function age(array $rows): array
    {
        $by = [];
        foreach ($rows as $r) {
            $b = $r['age_bucket_all'] ?? '';
            $by[$b] ??= ['bucket' => $b, 'male' => 0, 'female' => 0];
            if (($r['gender'] ?? '') === '남성') {
                $by[$b]['male'] += (float) ($r['pv'] ?? 0);
            } else {
                $by[$b]['female'] += (float) ($r['pv'] ?? 0);
            }
        }
        $arr = array_values(array_filter($by, fn ($a) => $a['bucket'] !== '(알수없음)'));
        $mt = self::col($arr, 'male');
        $ft = self::col($arr, 'female');
        $max = 1;
        foreach ($arr as $a) {
            $max = max($max, $a['male'], $a['female']);
        }
        $rows2 = [];
        foreach ($arr as $a) {
            $rows2[] = [
                'bucket' => $a['bucket'],
                'mp' => $mt ? round($a['male'] / $mt * 100) : 0,
                'fp' => $ft ? round($a['female'] / $ft * 100) : 0,
                'mw' => round($a['male'] / $max * 100),
                'fw' => round($a['female'] / $max * 100),
            ];
        }

        return [
            'arr' => $rows2,
            'malePct' => ($mt + $ft) ? round($mt / ($mt + $ft) * 100) : 0,
            'femalePct' => ($mt + $ft) ? round($ft / ($mt + $ft) * 100) : 0,
        ];
    }
}
