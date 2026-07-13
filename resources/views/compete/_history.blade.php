{{-- 업체별 순위·지표 추이 — crm _history_render.php 이식. $rows(ymd asc), $name. --}}
@php
    $he = fn ($s) => e((string) $s);
    $hv = function ($v, $dec = null) {
        if ($v === null || $v === '') return '<span class="mut">–</span>';
        return $dec === null ? number_format((int) $v) : number_format((float) $v, $dec);
    };
    $hd = function ($cur, $prev, $invert = false, $dec = 0) {
        if ($prev === null || $prev === '' || $cur === null || $cur === '') return '';
        $d = round((float) $cur - (float) $prev, $dec ?: 0);
        if ($d == 0) return '';
        $good = $invert ? ($d < 0) : ($d > 0);
        $txt = ($d > 0 ? '+' : '').($dec ? number_format($d, $dec) : number_format($d));
        return ' <span class="'.($good ? 'hup' : 'hdn').'">'.$txt.'</span>';
    };
    $METRICS = [
        ['k' => 'rnk', 'label' => '순위', 'invert' => true, 'dec' => null, 'rank' => true],
        ['k' => 'visitor_cnt', 'label' => '영수증 리뷰', 'invert' => false, 'dec' => null],
        ['k' => 'blog_cnt', 'label' => '블로그 리뷰', 'invert' => false, 'dec' => null],
        ['k' => 'save_cnt', 'label' => '저장수', 'invert' => false, 'dec' => null],
        ['k' => 'image_cnt', 'label' => '사진수', 'invert' => false, 'dec' => null],
        ['k' => 'review_score', 'label' => '평점', 'invert' => false, 'dec' => 2],
        ['k' => 'd6', 'label' => '사진충실 D6', 'invert' => false, 'dec' => 2],
        ['k' => 'd7', 'label' => '정보충실성', 'invert' => false, 'dec' => 2],
        ['k' => 'd9', 'label' => '최근활동 D9', 'invert' => false, 'dec' => 2],
        ['k' => 'd10', 'label' => '영향력 D10', 'invert' => false, 'dec' => 2],
        ['k' => 'n1', 'label' => 'N1 유사도', 'invert' => false, 'dec' => 2],
        ['k' => 'n2', 'label' => 'N2 관련성', 'invert' => false, 'dec' => 2],
    ];
    $rows = array_values($rows);
    $desc = array_reverse($rows); // 최신 → 과거
@endphp
<style>
.hx{font-size:.875rem;color:#333}
.hx .mut{color:#8a94a6}
.hx .sec{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:14px 18px;margin-bottom:12px}
.hx .sec .tt{font-weight:700;color:#1f2937;margin-bottom:10px;font-size:.95rem}
.hx .hup{color:#12b886;font-weight:700;font-size:.78rem}.hx .hdn{color:#e5484d;font-weight:700;font-size:.78rem}
.hx .hgrid{display:flex;flex-wrap:wrap;gap:8px}
.hx .hcard{border:1px solid #eef0f2;border-radius:8px;background:#fafbfc;padding:9px 12px;min-width:128px;text-align:center}
.hx .hcard .hd{color:#8a94a6;font-size:.8rem}
.hx .hcard .hr{font-weight:800;font-size:1.15rem;color:#111;margin:2px 0 4px}
.hx .hcard .hm{font-size:.8rem;color:#5b6472;line-height:1.8;white-space:nowrap}
.hx .hcard .hm b{color:#1f2937;font-weight:700}
.hx .hcard .lb{color:#adb5bd}
.hx table.xtb{width:100%;border-collapse:collapse}
.hx table.xtb th{background:#fafbfc;color:#8a94a6;font-weight:600;font-size:.8rem;padding:8px 12px;border-bottom:1px solid #eef0f2;text-align:center;white-space:nowrap}
.hx table.xtb td{padding:9px 12px;border-bottom:1px solid #f4f6f8;vertical-align:middle;text-align:center;white-space:nowrap;font-variant-numeric:tabular-nums;font-size:.86rem}
.hx table.xtb td:first-child{text-align:left;font-weight:600;color:#555}
.hx .note{color:#8a94a6;font-size:.8rem;margin-top:8px}
.hx .pplus2{display:inline-block;font-size:.66rem;font-weight:700;color:#fff;background:#03c75a;border-radius:4px;padding:0 4px;vertical-align:middle}
.hx .htabs{display:flex;gap:4px;border-bottom:1px solid #e5e7eb;margin-bottom:14px}
.hx .htabs button{padding:9px 20px;border:0;background:none;font-weight:700;color:#888;cursor:pointer;border-bottom:2px solid transparent;font-size:.875rem}
.hx .htabs button.on{color:#111;border-bottom-color:#03c75a}
</style>
<div class="hx">
  @if (count($rows) < 1)
    <div class="sec"><span class="mut">아직 누적 데이터가 없습니다. 분석이 매일 실행되면 자동으로 쌓입니다.</span></div>
  @else
    @if (count($rows) >= 2)
      @php
          $W = 900; $H = 110; $pL = 36; $pR = 12; $pT = 12; $pB = 20; $n = count($rows);
          $rks = array_map(fn ($rr) => min(300, max(1, (int) ($rr['rnk'] ?? 300))), $rows);
          $mn = min($rks); $mxv = max($rks); if ($mxv == $mn) $mxv = $mn + 1;
          $xO = fn ($i) => round($pL + ($n <= 1 ? 0 : ($W - $pL - $pR) * $i / ($n - 1)), 1);
          $yO = fn ($v) => round($pT + ($v - $mn) / ($mxv - $mn) * ($H - $pT - $pB), 1);
          $pts = [];
          foreach ($rks as $i => $v) { $pts[] = $xO($i).','.$yO($v); }
      @endphp
      <div class="sec"><div class="tt">순위 추이 <span class="mut">— 위쪽일수록 상위</span></div>
        <svg viewBox="0 0 {{ $W }} {{ $H }}" style="width:100%;height:auto;max-height:150px;font-size:var(--fs-xs)">
          <text x="{{ $pL - 5 }}" y="{{ $yO($mn) + 3 }}" text-anchor="end" fill="#94a0ac">{{ $mn }}위</text>
          <text x="{{ $pL - 5 }}" y="{{ $yO($mxv) + 3 }}" text-anchor="end" fill="#c0c6cd">{{ $mxv }}위</text>
          <polyline points="{{ implode(' ', $pts) }}" fill="none" stroke="#03c75a" stroke-width="2"/>
          @foreach ($rks as $i => $v)<circle cx="{{ $xO($i) }}" cy="{{ $yO($v) }}" r="2.8" fill="#03c75a"/>@endforeach
        </svg>
      </div>
    @endif

    <div class="sec">
      <div class="htabs">
        <button type="button" class="on" onclick="var s=this.closest('.sec');s.querySelector('.hv-d').style.display='';s.querySelector('.hv-m').style.display='none';s.querySelectorAll('.htabs button').forEach(function(x){x.classList.remove('on')});this.classList.add('on')">일자별</button>
        <button type="button" onclick="var s=this.closest('.sec');s.querySelector('.hv-d').style.display='none';s.querySelector('.hv-m').style.display='';s.querySelectorAll('.htabs button').forEach(function(x){x.classList.remove('on')});this.classList.add('on')">지표별</button>
      </div>
      <div class="hv-d">
      <div class="tt">일자별 <span class="mut">— 최신부터 · 증감은 직전 기록 대비</span></div>
      <div class="hgrid">
        @foreach ($desc as $di => $rr)
        @php $old = $desc[$di + 1] ?? null; $rk = (int) ($rr['rnk'] ?? 0); @endphp
        <div class="hcard">
          <div class="hd">{{ substr($rr['ymd'] ?? '', 5) }}@if (! empty($rr['place_plus'])) <span class="pplus2">p+</span>@endif</div>
          <div class="hr">{{ $rk >= 300 ? '300+' : $rk.'위' }}{!! $old ? $hd($rk, (int) ($old['rnk'] ?? 0), true, 0) : '' !!}</div>
          <div class="hm">
            <span class="lb">영</span> <b>{!! $hv($rr['visitor_cnt'] ?? null) !!}</b>{!! $old ? $hd($rr['visitor_cnt'] ?? null, $old['visitor_cnt'] ?? null) : '' !!}<br>
            <span class="lb">블</span> <b>{!! $hv($rr['blog_cnt'] ?? null) !!}</b>{!! $old ? $hd($rr['blog_cnt'] ?? null, $old['blog_cnt'] ?? null) : '' !!}<br>
            <span class="lb">저장</span> <b>{!! $hv($rr['save_cnt'] ?? null) !!}</b>{!! $old ? $hd($rr['save_cnt'] ?? null, $old['save_cnt'] ?? null) : '' !!}
          </div>
        </div>
        @endforeach
      </div>
      </div>

      <div class="hv-m" style="display:none">
      <div class="tt">지표별 <span class="mut">— 지표 행 × 날짜 열(좌=최신)</span></div>
      <div style="overflow-x:auto">
        <table class="xtb"><thead><tr>
          <th style="text-align:left">지표</th>@foreach ($desc as $rr)<th>{{ substr($rr['ymd'], 5) }}</th>@endforeach
        </tr></thead><tbody>
        @foreach ($METRICS as $m)
          <tr>
            <td>{{ $m['label'] }}</td>
            @foreach ($desc as $di => $rr)
              @php $old = $desc[$di + 1] ?? null; $v = $rr[$m['k']] ?? null; $ov = $old[$m['k']] ?? null; @endphp
              <td>@if (! empty($m['rank']))<b>{{ (int) $v >= 300 ? '300+' : (int) $v.'위' }}</b>@else{!! $hv($v, $m['dec']) !!}@endif{!! $old ? $hd($v, $ov, $m['invert'], $m['dec'] ?: 0) : '' !!}</td>
            @endforeach
          </tr>
        @endforeach
        </tbody></table>
      </div>
      </div>
      <div class="note">지표 상승(<span class="hup">+</span>)·순위 개선(<span class="hup">-N</span>)은 녹색, 반대는 빨강. D9·D10은 리뷰 수집 대상(내 매장+상위10), 저장수는 맛집 전용, 사진수·D6은 전 업종 공통.</div>
    </div>
  @endif
</div>
