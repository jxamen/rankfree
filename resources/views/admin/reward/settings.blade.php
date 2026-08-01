{{-- 리워드 설정(운영자) — 미션 안내 문구·정답 소스. 환경 설정에서 분리(미션 타입이 늘어나는 축) --}}
@extends('admin.layout')
@section('title', '리워드 설정')

@section('admin-content')
<x-console.page-head title="리워드 설정"
    desc="매체(오퍼월·미니앱)에 API 응답으로 내려가는 안내 문구와 정답 기준 · 저장하면 매체 배포 없이 즉시 반영됩니다" />

@if (session('status'))
    <div class="alert alert-success mb-4" style="font-size:var(--fs-xs);">{{ session('status') }}</div>
@endif

@if ($poolVendorId <= 0)
    <div class="card p-4 mb-4" style="border-color:var(--color-error);">
        <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">리워드 풀 벤더가 지정되지 않았습니다</div>
        <p class="text-muted mt-1" style="font-size:var(--fs-xs);">
            지정 전에는 세부주문서가 미션으로 만들어지지 않습니다. 환경 설정에서 리워드 풀 벤더를 먼저 지정하세요.
        </p>
    </div>
@endif

<form method="POST" action="{{ route('admin.reward.settings.update') }}">
    @csrf @method('PUT')

    <div class="card p-4 mb-4">
        <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">정답 기준</div>
        <p class="text-muted mb-3" style="font-size:var(--fs-xs);">
            새로 만들어지는 미션이 <b>무엇을 정답으로 낼지</b>입니다. 이미 만들어진 미션은 각자 저장된 값을 그대로 씁니다.
        </p>
        <select name="answer_source" class="input" style="max-width:320px;">
            @foreach ($answerSources as $__v => $__label)
                <option value="{{ $__v }}" @selected(old('answer_source', $answerSource) === $__v)>{{ $__label }}</option>
            @endforeach
        </select>
        <p class="text-muted mt-2" style="font-size:var(--fs-xs);">
            <b>상품 해시태그</b>는 참여자마다 다른 번호를 물어 정답 공유를 막습니다.
            <b>고정 정답</b>은 미션마다 값을 직접 입력해야 하며, 비어 있으면 태그로 채점합니다.
        </p>
    </div>

    <div class="card p-4 mb-4">
        <div class="text-ink font-semibold mb-1" style="font-size:var(--fs-sm);">상품 사진이 없을 때</div>
        <p class="text-muted mb-3" style="font-size:var(--fs-xs);">
            참여자는 사진으로 같은 상품인지 확인합니다. 수집된 사진이 없는 미션에는 이 이미지를 대신 내려줍니다.
        </p>
        <div class="flex items-start gap-3" style="flex-wrap:wrap;">
            <img src="{{ $defaultProductImageLive }}" alt="대체 이미지 미리보기"
                style="width:96px;height:96px;object-fit:cover;border-radius:var(--radius-md);border:1px solid var(--color-hairline);flex:none;"
                onerror="this.style.opacity=.3">
            <input type="url" name="default_product_image" class="input" style="flex:1;min-width:280px;"
                placeholder="{{ config('reward.default_product_image') }}"
                value="{{ old('default_product_image', $defaultProductImage) }}">
        </div>
    </div>

    @foreach ($kinds as $__kind => $__meta)
        @php $__c = $copy[$__kind] ?? []; @endphp
        <div class="card p-4 mb-4">
            <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">{{ $__meta['label'] }}</div>
            <p class="text-muted mb-3" style="font-size:var(--fs-xs);">{{ $__meta['desc'] }} · 비워두면 기본 문구를 씁니다</p>

            <label class="form-label" style="font-size:var(--fs-xs);">참여 방법 <span class="text-muted">(한 줄에 한 단계, 순서대로 노출)</span></label>
            <textarea name="copy[{{ $__kind }}][guide]" class="input" spellcheck="false"
                style="width:100%;height:120px;font-size:var(--fs-xs);line-height:1.7;resize:vertical;"
                placeholder="{{ implode("\n", (array) config('reward.copy.'.$__kind.'.guide', [])) }}">{{ old('copy.'.$__kind.'.guide', is_array($__c['guide'] ?? null) ? implode("\n", $__c['guide']) : '') }}</textarea>

            <div class="grid gap-3 mt-3" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr));">
                <div>
                    <label class="form-label" style="font-size:var(--fs-xs);">질문 문구</label>
                    <input type="text" name="copy[{{ $__kind }}][question]" class="input" style="width:100%;"
                        placeholder="{{ config('reward.copy.'.$__kind.'.question') }}"
                        value="{{ old('copy.'.$__kind.'.question', $__c['question'] ?? '') }}">
                </div>
                <div>
                    <label class="form-label" style="font-size:var(--fs-xs);">입력칸 안내</label>
                    <input type="text" name="copy[{{ $__kind }}][placeholder]" class="input" style="width:100%;"
                        placeholder="{{ config('reward.copy.'.$__kind.'.placeholder') }}"
                        value="{{ old('copy.'.$__kind.'.placeholder', $__c['placeholder'] ?? '') }}">
                </div>
            </div>

            <div class="mt-3">
                <label class="form-label" style="font-size:var(--fs-xs);">주의 문구</label>
                <input type="text" name="copy[{{ $__kind }}][notice]" class="input" style="width:100%;"
                    placeholder="{{ config('reward.copy.'.$__kind.'.notice') }}"
                    value="{{ old('copy.'.$__kind.'.notice', $__c['notice'] ?? '') }}">
            </div>

            <div class="mt-3">
                <label class="form-label" style="font-size:var(--fs-xs);">미션 설명 <span class="text-muted">(동기화가 미션을 만들 때 채우는 문장)</span></label>
                <input type="text" name="copy[{{ $__kind }}][description]" class="input" style="width:100%;"
                    placeholder="{{ config('reward.copy.'.$__kind.'.description') }}"
                    value="{{ old('copy.'.$__kind.'.description', $__c['description'] ?? '') }}">
            </div>
        </div>
    @endforeach

    <div class="card p-4 mb-4">
        <div class="text-ink font-semibold mb-2" style="font-size:var(--fs-sm);">쓸 수 있는 변수</div>
        <p class="text-muted" style="font-size:var(--fs-xs);line-height:1.9;">
            <code>{tagIndex}</code> 물어볼 태그 번호(참여자마다 다름) ·
            <code>{tagCount}</code> 태그 개수 ·
            <code>{shop_name}</code> 상점명 ·
            <code>{product_title}</code> 상품명 ·
            <code>{keyword}</code> 검색어 ·
            <code>{price}</code> 판매가 ·
            <code>{reward_item}</code> 보상 종류 ·
            <code>{reward_count}</code> 보상 개수
        </p>
        <p class="text-muted mt-2" style="font-size:var(--fs-xs);">
            쇼핑 미션은 안내에 <b>상품 사진 · 상품명 · 판매가</b>가 함께 내려갑니다(응답의 <code>quiz.product</code>).
            문구에 상품명·가격을 또 넣을 필요는 없습니다.
        </p>
    </div>

    <div class="flex items-center gap-2">
        <button type="submit" class="btn btn-primary">저장</button>
        <a href="{{ route('admin.reward.api-test') }}" class="btn btn-secondary">미션 API 테스트</a>
    </div>
</form>
@endsection
