{{-- 대시보드 홍보 배너 — 신규 상품/업체/프로모션. 입력: $banners (Banner 컬렉션). --}}
@if ($banners->isNotEmpty())
    <div class="grid grid-cols-1 {{ $banners->count() > 1 ? 'lg:grid-cols-2' : '' }} gap-4 mb-6">
        @foreach ($banners as $b)
            @php
                // 기본 배경은 테마 불변 다크(surface-dark) — ink는 다크모드에서 밝게 반전되어 흰 글씨가 묻힘
                $bg = $b->bg_color ?: 'var(--color-surface-dark)';
                $fg = $b->text_color ?: '#ffffff';
                $hasImg = ! empty($b->image_url);
            @endphp
            <a href="{{ $b->link_url ?: 'javascript:void(0)' }}" @if ($b->link_url) target="_blank" rel="noopener" @endif
               class="rf-banner" style="position:relative;display:block;border-radius:14px;overflow:hidden;min-height:132px;padding:22px 24px;
                      background:{{ $hasImg ? 'center/cover no-repeat url('.e($b->image_url).')' : $bg }};color:{{ $fg }};">
                @if ($hasImg)<span style="position:absolute;inset:0;background:linear-gradient(90deg,rgba(0,0,0,.55),rgba(0,0,0,.15));"></span>@endif
                <span style="position:relative;display:flex;flex-direction:column;height:100%;gap:6px;">
                    <span class="badge" style="align-self:flex-start;font-size:var(--fs-xs);padding:2px 9px;background:rgba(255,255,255,.22);color:{{ $fg }};border:0;">{{ $b->typeLabel() }}</span>
                    <span class="font-display" style="font-size:var(--fs-lg);line-height:1.25;">{{ $b->title }}</span>
                    @if ($b->subtitle)<span style="font-size:var(--fs-xs);opacity:.9;">{{ $b->subtitle }}</span>@endif
                    @if ($b->link_label)
                        <span style="margin-top:auto;font-size:var(--fs-xs);font-weight:700;">{{ $b->link_label }} →</span>
                    @endif
                </span>
            </a>
        @endforeach
    </div>
@endif
