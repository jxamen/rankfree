{{-- 키워드 결과 테이블 한 행 — index 초기 렌더 + '다음 페이지 수집' AJAX 응답 공용. $b = 블로거 1건, $savedIds = 이 키워드로 저장된 blog_id 목록 --}}
@php
    $savedIds = $savedIds ?? [];
    $isSaved = in_array($b['blog_id'] ?? '', $savedIds, true);
    $gradeColor = fn ($g) => match ($g) {
        'S' => 'var(--color-success)', 'A' => 'var(--color-accent)', 'B' => 'var(--color-badge-violet)',
        'C' => 'var(--color-warning)', 'D' => 'var(--color-muted)', default => 'var(--color-muted)',
    };
    $nf = fn ($v) => $v === null ? '—' : number_format((int) $v);
    $p = $b['profile'] ?? [];
    $q = $b['quality'] ?? [];
    $v5 = array_reverse($p['visitor5'] ?? []); // 오늘 먼저
    $vlab = ['오늘', '어제', '2일 전', '3일 전', '4일 전'];
    $today = $v5[0]['count'] ?? 0;      // 오늘(집계중일 수 있음)
    $yesterday = $v5[1]['count'] ?? 0;  // 어제
    $searchStr = mb_strtolower(trim(
        ($p['blog_name'] ?? '').' '.($b['blog_id'] ?? '').' '.($b['featured']['title'] ?? '').' '
        .implode(' ', array_map(fn ($w) => $w['word'], $q['top_words'] ?? []))
    ));
@endphp
<tr class="bi-row" style="border-top:1px solid var(--color-hairline-soft);"
    data-rank="{{ $b['search_rank'] ?? 0 }}" data-score="{{ $b['score'] ?? 0 }}"
    data-grade="{{ $b['grade'] ?? '' }}"
    data-posts="{{ $p['post_total'] ?? 0 }}" data-vis="{{ $p['day_visitor_avg'] ?? 0 }}"
    data-today="{{ (int) $today }}" data-yesterday="{{ (int) $yesterday }}"
    data-photo="{{ $q['avg_photos'] ?? 0 }}" data-body="{{ $q['avg_length'] ?? 0 }}"
    data-search="{{ $searchStr }}" data-blogid="{{ $b['blog_id'] ?? '' }}" data-saved="{{ $isSaved ? 1 : 0 }}">
    <td class="px-3 py-3 text-center" style="width:36px;"><input type="checkbox" class="bi-sel" value="{{ $b['blog_id'] ?? '' }}" style="width:15px;height:15px;accent-color:var(--color-ink);cursor:pointer;"></td>
    <td class="px-2 py-3 text-center" style="width:40px;">
        <button type="button" class="bi-star" title="{{ $isSaved ? '저장 해제' : '블로거 저장 (키워드+ID)' }}">{{ $isSaved ? '★' : '☆' }}</button>
    </td>
    <td class="px-3 py-3 text-muted bi-rank" style="font-size:var(--fs-xs);">{{ $b['search_rank'] ?? 0 }}</td>
    <td class="px-3 py-3" style="max-width:320px;">
        <a href="https://blog.naver.com/{{ $b['blog_id'] }}" target="_blank" class="text-ink font-semibold hover:underline" style="font-size:var(--fs-xs);">{{ $p['blog_name'] ?: $b['blog_id'] }}</a>
        <div class="text-muted-soft" style="font-size:var(--fs-xs);"><span class="bi-analyze"><a href="{{ route('console.blog-single', ['q' => $b['blog_id']]) }}" class="bi-analyze-link hover:text-ink hover:underline">{{ $b['blog_id'] }}</a><span class="bi-analyze-tip">🔍 블로그 지수분석 보기</span></span>@if ($p['power_blog'] ?? false) · <span style="color:var(--color-badge-orange);">파워</span>@endif</div>
        @if (! empty($b['featured']['title']))
            <a href="https://blog.naver.com/{{ $b['blog_id'] }}/{{ $b['featured']['log_no'] }}" target="_blank" class="text-muted truncate block hover:underline" style="font-size:var(--fs-xs);margin-top:2px;">📄 {{ $b['featured']['title'] }}</a>
            @if (! empty($b['featured']['date']))
                <span class="text-muted-soft" style="font-size:var(--fs-xs);">🗓 {{ \Illuminate\Support\Carbon::hasFormat($b['featured']['date'], 'Ymd') ? \Illuminate\Support\Carbon::createFromFormat('Ymd', $b['featured']['date'])->format('Y-m-d') : $b['featured']['date'] }}</span>
            @endif
        @endif
    </td>
    <td class="px-3 py-3 text-center"><span class="badge" style="font-size:var(--fs-xs);padding:2px 10px;background:color-mix(in srgb,{{ $gradeColor($b['grade']) }} 14%,var(--color-canvas));color:{{ $gradeColor($b['grade']) }};font-weight:700;">{{ $b['grade'] }}</span></td>
    <td class="px-3 py-3 text-right font-display" style="font-size:var(--fs-base);color:{{ $gradeColor($b['grade']) }};">{{ $b['score'] }}</td>
    <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ $nf($p['post_total'] ?? 0) }}</td>
    <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ $nf($p['day_visitor_avg'] ?? 0) }}</td>
    <td class="px-3 py-3">
        @if ($v5)
            <div class="flex items-end justify-center gap-2.5">
                @foreach (array_slice($v5, 0, 5) as $i => $v)
                    <div class="text-center" style="min-width:34px;">
                        <div class="text-body" style="font-size:var(--fs-xs);font-weight:600;line-height:1.1;">{{ number_format((int) $v['count']) }}</div>
                        <div class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $vlab[$i] ?? '' }}</div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="text-center text-muted-soft" style="font-size:var(--fs-xs);">—</div>
        @endif
    </td>
    <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ $q['avg_photos'] ?? 0 }}</td>
    <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">{{ $nf($q['avg_length'] ?? 0) }}자</td>
    <td class="px-5 py-3">
        <div class="flex flex-wrap gap-1" style="max-width:280px;">
            @foreach (array_slice($q['top_words'] ?? [], 0, 5) as $w)
                <span class="badge" style="font-size:var(--fs-xs);padding:1px 7px;">{{ $w['word'] }}</span>
            @endforeach
        </div>
    </td>
</tr>
