{{-- 점수 근거 상세 — crm _explain_render.php 이식. $x = 컨트롤러 explain() 결과. --}}
@php
    $ee = fn ($s) => e((string) $s);
    $num = fn ($v) => ($v === null || $v === '') ? '–' : number_format((float) $v, 2);
    $bar = function ($v, $color) {
        $w = ($v === null || $v === '') ? 0 : max(0, min(100, (float) $v));
        return '<span class="xb"><span class="xf" style="width:'.$w.'%;background:'.$color.'"></span></span>';
    };
    $grade = function ($g) {
        $g = (float) $g;
        return $g >= 0.99 ? '<b class="ok">충족</b>' : ($g <= 0.01 ? '<span class="no">미흡</span>' : '<span class="mid">'.round($g * 100).'%</span>');
    };
@endphp
<style>
.xw{font-size:.875rem;color:#333}
.xw .mut{color:#8a94a6}
.xw .xh{margin-bottom:12px}.xw .xh b{font-size:17px}
.xw .minetag{background:#fffbe6;border:1px solid #f0d98a;color:#a37b00;border-radius:4px;font-size:.74rem;padding:1px 6px}
.xw .xsum{display:flex;gap:12px;margin-bottom:16px}
.xw .xsc{flex:1;background:#fafbfc;border:1px solid #eef0f2;border-radius:8px;padding:12px 14px;text-align:center}
.xw .xsc .l{display:block;color:#8a94a6;font-size:.78rem}.xw .xsc .v{font-size:24px;font-weight:800;color:#111}
.xw .xsc.n3{background:#eefcf3;border-color:#b7e9cd}.xw .xsc.n3 .v{color:#03a54a}
.xw .xsec{border:1px solid #eef0f2;border-radius:8px;padding:12px 16px;margin-bottom:12px}
.xw .xt{font-weight:700;color:#1f2937;margin-bottom:9px;font-size:.95rem}
.xw .xrow{font-family:monospace;background:#fafbfc;border-radius:6px;padding:8px 12px}
.xw .xnote{color:#8a94a6;font-size:.78rem;margin-top:7px}
.xw .xnote2{color:#adb5bd;font-size:.77rem;margin-top:6px}
.xw table.xtb{width:100%;border-collapse:collapse}
.xw table.xtb th{background:#fafbfc;color:#8a94a6;font-weight:600;font-size:.8rem;padding:7px 10px;border-bottom:1px solid #eef0f2;text-align:left}
.xw table.xtb td{padding:9px 10px;border-bottom:1px solid #f4f6f8;vertical-align:middle;font-size:.86rem}
.xw table.xtb td.c,.xw table.xtb th.c{text-align:center}
.xw table.xtb .sub{color:#adb5bd;font-size:.75rem;margin-top:2px}
.xw .ok{color:#03a54a}.xw .no{color:#e5484d}.xw .mid{color:#c17a00}
.xw .xb{display:inline-block;width:60%;height:8px;background:#eef0f2;border-radius:4px;vertical-align:middle;overflow:hidden}
.xw .xf{display:block;height:100%;border-radius:4px}
.xw .xchk{display:grid;grid-template-columns:1fr 1fr;gap:6px 18px}
.xw .ck{display:flex;justify-content:space-between;border-bottom:1px dashed #f0f2f4;padding:5px 0;font-size:.86rem}
.xw .ck .ckl{color:#555}.xw .ck .ckr{color:#8a94a6}
.xw .pplus2{display:inline-block;font-size:.74rem;font-weight:700;color:#fff;background:#03c75a;border-radius:4px;padding:1px 6px;letter-spacing:-.3px}
.xw .repkw{margin-top:8px;display:flex;flex-wrap:wrap;gap:5px;align-items:center}
.xw .repkw>.mut{font-weight:700;margin-right:2px}
.xw .rkg{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-bottom:8px}
.xw .rkg .rkl{font-size:.78rem;color:#8a94a6;font-weight:700;min-width:84px}
.xw .rkc{display:inline-block;font-size:.8rem;padding:3px 9px;border-radius:12px;border:1px solid #eef0f2;background:#fafbfc;color:#444}
.xw .rkc b{color:#12b886;font-weight:700}
.xw .rkc.voted{background:#eefcf3;border-color:#c3ecd4}.xw .rkc.voted b{color:#03a54a}
.xw .rkc.menu{background:#eff4ff;border-color:#d3e0fb}.xw .rkc.menu b{color:#3d6fe0}
.xw .rkc.theme{background:#fff6ec;border-color:#f3ddba}.xw .rkc.theme b{color:#c17a00}
@media(max-width:640px){.xw .xchk{grid-template-columns:1fr}.xw .xsum{flex-wrap:wrap}.xw .rkg .rkl{min-width:auto;width:100%}}
</style>
<div class="xw">
  <div class="xh">
    <div><b>{{ $x['name'] }}</b> <span class="mut">{{ $x['category'] }}</span>@if ($x['is_mine']) <span class="minetag">내 매장</span>@endif @if (! empty($x['place_plus']))<span class="pplus2" title="네이버 커넥트 POS 연동">place+</span>@endif</div>
    <div class="mut">검색어 「{{ $x['keyword'] }}」 · {{ $x['rnk'] >= 300 ? '300위+' : (int) $x['rnk'].'위' }} · {{ substr($x['ymd'], 5) }} 기준</div>
    @if (! empty($x['rep_keywords']))
    <div class="repkw"><span class="mut">대표키워드</span> @foreach ($x['rep_keywords'] as $kw)<span class="rkc">{{ $kw }}</span> @endforeach</div>
    @endif
  </div>
  <div class="xsum">
    <div class="xsc"><span class="l">N1 유사도</span><span class="v">{{ $num($x['n1']) }}</span></div>
    <div class="xsc"><span class="l">N2 관련성</span><span class="v">{{ $num($x['n2']) }}</span></div>
    <div class="xsc n3"><span class="l">N3 랭킹</span><span class="v">{{ $num($x['n3']) }}</span></div>
  </div>
  <div class="xsec"><div class="xt">N3 랭킹 <span class="mut">— 실제 순위 기반</span></div>
    <div class="xrow"><span class="mut">{{ $x['n3formula'] }}</span> <b>= {{ $num($x['n3']) }}</b></div>
    <div class="xnote">순위가 곧 N3입니다(1위=100, 낮아질수록 로그 감쇠). N1·N2를 올리면 순위(N3)가 따라 오릅니다.</div>
  </div>
  <div class="xsec"><div class="xt">N1 유사도 <span class="mut">— 검색어↔업체정보 정합 (= {{ $num($x['n1']) }})</span></div>
    <table class="xtb"><thead><tr><th>요소</th><th class="c">매칭</th><th class="c">가중</th><th style="width:36%">기여</th></tr></thead><tbody>
    @php
        $kc = $x['kc'];
        $n1rows = [
            ['지역(L)', $kc['L'], 0.30, '「'.($kc['region'] ?: $kc['core']).'」 주소/상호 포함'],
            ['업종(B)', $kc['B'], 0.30, '「'.$kc['bizterm'].'」 ↔ 검색 카테고리'],
            ['대표키워드(T)', $kc['T'], 0.30, '검색어가 대표키워드에 포함'],
            ['상호(M)', $kc['M'], 0.10, '상호에 지역/업종 포함'],
        ];
    @endphp
    @foreach ($n1rows as $r)
      @php $g = $r[1]; $s = ($g === null) ? null : round($g * 100, 1); @endphp
      <tr><td>{{ $r[0] }}<div class="sub">{{ $r[3] }}</div></td>
          <td class="c">@if ($g === null)<span class="mut">해당없음</span>@else{!! $grade($g) !!}@endif</td>
          <td class="c mut">{{ (int) ($r[2] * 100) }}%</td>
          <td>{!! $bar($s, '#03c75a') !!} <span class="mut">{{ $s === null ? '–' : $s }}</span></td></tr>
    @endforeach
    </tbody></table>
  </div>
  <div class="xsec"><div class="xt">N2 관련성 <span class="mut">— 리뷰·저장·정보 종합 (= {{ $num($x['n2']) }})</span></div>
    <table class="xtb"><thead><tr><th>차원</th><th class="c">가중</th><th style="width:46%">점수(0~100)</th></tr></thead><tbody>
    @foreach ($x['n2parts'] as $p)
      @php $v = $p['v']; $alt = $p['alt'] ?? null; @endphp
      <tr><td>{{ $p['label'] }} <span class="mut">{{ $p['code'] }}</span></td>
          <td class="c mut">{{ (int) ($p['w'] * 100) }}%</td>
          <td>{!! $bar($v, '#4C7EF3') !!} <span class="mut">{{ ($v === null || $v === '') ? ($alt !== null ? $alt : '결측(제외)') : $num($v) }}</span></td></tr>
    @endforeach
    </tbody></table>
    <div class="xnote">예약자 리뷰 미제공 업종(음식점 등)은 영수증 리뷰의 「예약 후 이용」 언급 수를 경쟁셋 대비 정규화해 D3에 반영합니다. D9·D10은 리뷰를 수집한 매장(내 매장+상위 10)만 산출됩니다.</div>
  </div>
  <div class="xsec"><div class="xt">정보충실성 <span class="mut">— 상세정보 완성도 (= {{ $num($x['d7']) }})</span></div>
    <div class="xchk">
    @foreach ($x['seo'] as $it)@if ($it['avail'])
      <div class="ck"><span class="ckl">{{ $it['label'] }}</span><span class="ckr">{{ $it['raw'] }} {!! $grade($it['grade']) !!}</span></div>
    @endif @endforeach
    </div>
  </div>
  @if (! empty($x['review_kw']))
  @php $rk = $x['review_kw']; @endphp
  <div class="xsec"><div class="xt">리뷰 키워드 <span class="mut">— 방문자 리뷰 AI 분석(언급 횟수)</span></div>
    @if (! empty($rk['voted']))
    <div class="rkg"><span class="rkl">👍 방문자 투표</span>@foreach ($rk['voted'] as $it) <span class="rkc voted">{{ $it['l'] }} <b>{{ (int) $it['c'] }}</b></span>@endforeach</div>
    @endif
    @if (! empty($rk['menus']))
    <div class="rkg"><span class="rkl">🔑 리뷰 대표 키워드</span>@foreach ($rk['menus'] as $it) <span class="rkc menu">{{ $it['l'] }} <b>{{ (int) $it['c'] }}</b></span>@endforeach</div>
    @endif
    @if (! empty($rk['themes']))
    <div class="rkg"><span class="rkl">🏷 평가 테마</span>@foreach ($rk['themes'] as $it) <span class="rkc theme">{{ $it['l'] }} <b>{{ (int) $it['c'] }}</b></span>@endforeach</div>
    @endif
  </div>
  @endif
  @php $rq = is_array($x['review_quality'] ?? null) ? $x['review_quality'] : []; @endphp
  @if (isset($rq['photo_total']) || ! empty($rq['authority']) || ! empty($rq['ctx']) || ! empty($rq['bloggers']))
  <div class="xsec"><div class="xt">리뷰 품질 <span class="mut">— 최근 4주 방문자(영수증) 리뷰 기준</span></div>
    @if (isset($rq['photo_total']) && (int) $rq['photo_total'] > 0)
    <div class="xrow">사진 포함 <b>{{ (int) ($rq['photo_n'] ?? 0) }}/{{ (int) $rq['photo_total'] }}</b> = <b>{{ round(($rq['photo_ratio'] ?? 0) * 100) }}%</b> <span class="mut">· 사진 포함 리뷰 비율(신뢰도 참고 지표)</span></div>
    @endif
    @if (! empty($rq['authority']))
    @php $au = $rq['authority']; @endphp
    <div class="xrow" style="margin-top:6px">인플루언서(팔로워≥100) <b>{{ (int) $au['infl'] }}명</b>@if (! empty($au['hi_infl']))(고평점 <b>{{ (int) $au['hi_infl'] }}</b>)@endif · 파워리뷰어(리뷰≥100) <b>{{ (int) $au['power'] }}명</b> · 평균 팔로워 <b>{{ number_format((int) $au['avg_fol']) }}</b></div>
    @php $tops = array_filter($au['top'] ?? [], fn ($t) => $t['f'] > 0 || $t['r'] > 0); @endphp
    @if ($tops)
    <div class="rkg" style="margin-top:6px"><span class="rkl">⭐ 주요 리뷰어</span>@foreach ($tops as $t) <span class="rkc">{{ $t['n'] }} <b>팔{{ number_format((int) $t['f']) }}</b>·리{{ number_format((int) $t['r']) }}{{ $t['rt'] !== null ? '·★'.$t['rt'] : '' }}</span>@endforeach</div>
    @endif
    @endif
    @if (! empty($rq['ctx']))
    <div class="rkg" style="margin-top:8px"><span class="rkl">🧭 방문 맥락</span>@foreach ($rq['ctx'] as $l => $c) <span class="rkc">{{ $l }} <b>{{ (int) $c }}</b></span>@endforeach</div>
    @endif
    @if (! empty($rq['bloggers']))
    <div class="rkg" style="margin-top:8px"><span class="rkl">✍ 블로그 리뷰어</span>@foreach ($rq['bloggers'] as $bl) <a class="rkc" href="https://blog.naver.com/{{ $bl['id'] }}" target="_blank" style="text-decoration:none">{{ $bl['n'] ?: $bl['id'] }}</a>@endforeach</div>
    @endif
  </div>
  @endif
  <div class="xnote2">※ N1·N2·N3은 관측 신호 기반 자체 추정치이며 네이버 공식 값이 아닙니다. place+·리뷰 키워드는 restaurant·hairshop 업종에서 제공됩니다.</div>
</div>
