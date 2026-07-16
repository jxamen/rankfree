{{-- 관련 분석 리포트 추천(내부 링크) — RelatedDocsService 결과를 렌더.
     $related = [['title' => 섹션명, 'items' => [['type','title','meta','url'], …]], …] --}}
@php $__secs = array_values(array_filter(($related ?? []), fn ($s) => ! empty($s['items']))); @endphp
@if ($__secs)
    <div style="margin-top:56px;" data-related-docs>
        <h2 class="font-display text-ink" style="font-size:var(--fs-xl);line-height:1.3;">함께 보면 좋은 분석</h2>
        <p class="text-muted" style="margin-top:4px;font-size:var(--fs-xs);">랭크프리가 분석한 관련 리포트입니다. 모두 무료로 열람할 수 있습니다.</p>
        @foreach ($__secs as $__s)
            <div class="text-muted-soft" style="margin-top:24px;font-size:var(--fs-xs);font-weight:600;">{{ $__s['title'] }}</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3" style="margin-top:10px;">
                @foreach ($__s['items'] as $__it)
                    <a href="{{ $__it['url'] }}" class="card p-4" style="display:block;text-decoration:none;">
                        <span class="badge border border-hairline">{{ $__it['type'] }}</span>
                        <div class="text-ink font-semibold" style="margin-top:10px;font-size:var(--fs-sm);line-height:1.45;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">{{ $__it['title'] }}</div>
                        <div class="text-muted font-mono" style="margin-top:4px;font-size:var(--fs-xs);">{{ $__it['meta'] }}</div>
                    </a>
                @endforeach
            </div>
        @endforeach
    </div>
@endif
