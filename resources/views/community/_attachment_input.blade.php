{{-- 첨부파일 등록 필드 — 운영자에게만 보인다(글쓰기·수정 공용). 서버도 같은 권한으로 다시 막는다. --}}
@if (auth()->user()?->isOperator())
    @php $att = \App\Models\CommunityPostAttachment::class; @endphp
    <div class="mb-5">
        <label class="text-muted block mb-1" style="font-size:var(--fs-xs);">첨부파일 <span class="text-muted-soft">(운영자 전용)</span></label>
        <input type="file" name="attachments[]" multiple accept="{{ $att::acceptAttribute() }}" class="input" style="padding:8px 10px;">
        <div class="text-muted-soft mt-1" style="font-size:var(--fs-xs);">
            최대 {{ $att::MAX_COUNT }}개 · 개당 {{ (int) ($att::MAX_KB / 1024) }}MB · {{ str_replace(',', ', ', $att::EXTENSIONS) }}
        </div>
    </div>
@endif
