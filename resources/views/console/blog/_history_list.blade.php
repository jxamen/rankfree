{{--
    최근 분석 내역 리스트 — blog-collect(index) / blog-index(single) 공용.
    입력: $history (BlogIndexAnalysis 컬렉션). 각 행 클릭 시 /console/blog-index/{id} 로 이동.
--}}
<div class="card overflow-hidden">
    <div class="px-5 py-4 text-ink font-semibold" style="font-size:var(--fs-xs);">최근 분석 내역 <span class="text-muted-soft" style="font-weight:400;">클릭하면 저장된 분석을 다시 봅니다 · 새로 수집하려면 위에서 재검색하세요</span></div>
    <div style="overflow-x:auto;">
        <table class="w-full" style="min-width:1000px;">
            <thead>
                <tr class="text-muted" style="font-size:var(--fs-xs);border-top:1px solid var(--color-hairline-soft);">
                    <th class="text-left px-5 py-2.5 font-semibold" style="width:64px;">No</th>
                    <th class="text-left px-3 py-2.5 font-semibold">대상</th>
                    <th class="text-left px-3 py-2.5 font-semibold" style="width:520px;">전문성</th>
                    <th class="text-right px-3 py-2.5 font-semibold" style="width:96px;">지수</th>
                    <th class="text-right px-5 py-2.5 font-semibold" style="width:200px;">수집일</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($history as $h)
                    <tr style="border-top:1px solid var(--color-hairline-soft);cursor:pointer;" onclick="location.href='{{ route('console.blog.show', $h) }}'">
                        @php
                            // 전문성 단어 — blog형: quality.top_words / keyword형: 블로거별 top_words 합산 상위
                            $snap = (array) $h->snapshot;
                            if ($h->type === 'blog') {
                                $words = array_column(array_slice((array) data_get($snap, 'quality.top_words', []), 0, 4), 'word');
                            } else {
                                $agg = [];
                                foreach ((array) ($snap['bloggers'] ?? []) as $bg) {
                                    foreach ((array) data_get($bg, 'quality.top_words', []) as $w) {
                                        $agg[$w['word']] = ($agg[$w['word']] ?? 0) + (int) ($w['count'] ?? 0);
                                    }
                                }
                                arsort($agg);
                                $words = array_keys(array_slice($agg, 0, 4, true));
                            }
                        @endphp
                        {{-- No — 최신이 위이므로 큰 번호부터 내림차순 --}}
                        <td class="px-5 py-3 text-muted-soft" style="font-size:var(--fs-xs);">{{ count($history) - $loop->index }}</td>
                        <td class="px-3 py-3"><a href="{{ route('console.blog.show', $h) }}" class="text-ink font-medium hover:underline" style="font-size:var(--fs-xs);">{{ $h->title }}</a></td>
                        <td class="px-3 py-3">
                            @if (count($words))
                                <div class="flex gap-1 flex-wrap">
                                    @foreach ($words as $wd)
                                        <span class="badge" style="font-size:var(--fs-xs);padding:1px 8px;">{{ $wd }}</span>
                                    @endforeach
                                </div>
                            @else
                                <span class="text-muted-soft" style="font-size:var(--fs-xs);">—</span>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-right text-body" style="font-size:var(--fs-xs);">
                            @if ($h->type === 'blog')
                                {{ $h->score !== null ? round($h->score).'점' : '—' }}
                            @else
                                {{ number_format($h->blogger_count) }}명
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right text-muted-soft" style="font-size:var(--fs-xs);">{{ $h->updated_at->format('Y-m-d H:i') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
