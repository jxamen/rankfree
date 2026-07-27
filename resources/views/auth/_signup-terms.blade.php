{{-- 회원가입 약관 동의(2026-07-27) — 항목·본문·필수여부는 관리자 환경설정에서 관리. register·social-complete 공용 --}}
@php $__terms = \App\Domain\Member\SignupTerms::active(); @endphp
@if ($__terms !== [])
    <div class="rf-terms" style="border:1px solid var(--color-hairline);border-radius:12px;padding:14px 16px;">
        <label class="flex items-center gap-2 text-ink" style="font-size:var(--fs-sm);font-weight:600;">
            <input type="checkbox" id="rf-term-all"> 전체 동의
        </label>
        <p class="text-muted-soft mt-1" style="font-size:var(--fs-xs);">아래 항목에 모두 동의합니다.</p>
        <div style="border-top:1px solid var(--color-hairline-soft);margin:10px 0;"></div>

        @foreach ($__terms as $t)
            <div class="mb-2">
                <div class="flex items-center gap-2 flex-wrap">
                    <label class="flex items-center gap-2 text-ink" style="font-size:var(--fs-xs);">
                        <input type="checkbox" class="rf-term-one" name="terms[{{ $t['key'] }}]" value="1"
                               @checked(old('terms.'.$t['key'])) @if ($t['required']) data-required="1" @endif>
                        <span>
                            {{ $t['title'] }}
                            @if ($t['required'])
                                <b class="text-ink">(필수)</b>
                            @else
                                <span class="text-muted-soft">(선택)</span>
                            @endif
                        </span>
                    </label>
                    @if (trim($t['body']) !== '')
                        <button type="button" class="btn btn-ghost btn-sm rf-term-toggle" data-target="rf-term-body-{{ $t['key'] }}"
                                style="margin-left:auto;height:24px;padding:0 8px;font-size:var(--fs-xs);">보기</button>
                    @endif
                </div>
                @if (trim($t['body']) !== '')
                    <div id="rf-term-body-{{ $t['key'] }}" class="hidden text-muted mt-1.5"
                         style="font-size:var(--fs-xs);line-height:1.65;white-space:pre-wrap;max-height:180px;overflow-y:auto;background:var(--color-surface);border-radius:8px;padding:10px 12px;">{{ $t['body'] }}</div>
                @endif
                @error('terms.'.$t['key'])
                    <div style="color:var(--color-error);font-size:var(--fs-xs);margin-top:4px;">{{ $message }}</div>
                @enderror
            </div>
        @endforeach
    </div>

    <script>
    (function () {
        // 전체 동의 ↔ 개별 항목 연동 + 약관 본문 펼쳐보기
        var all = document.getElementById('rf-term-all');
        var ones = Array.prototype.slice.call(document.querySelectorAll('.rf-term-one'));
        if (all && ones.length) {
            var sync = function () { all.checked = ones.every(function (c) { return c.checked; }); };
            all.addEventListener('change', function () {
                ones.forEach(function (c) { c.checked = all.checked; });
            });
            ones.forEach(function (c) { c.addEventListener('change', sync); });
            sync();
        }
        document.querySelectorAll('.rf-term-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var el = document.getElementById(btn.dataset.target);
                if (!el) return;
                var open = el.classList.toggle('hidden');
                btn.textContent = open ? '보기' : '접기';
            });
        });
    })();
    </script>
@endif
