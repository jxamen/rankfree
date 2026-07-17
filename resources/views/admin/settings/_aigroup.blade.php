{{--
    AI 모델 API 키 — 공급자별 고정 입력칸(Claude·Gemini·OpenAI). 폼 필드: ai_key[{provider}].
    입력: $rows(기존 [{provider,api_key}]), $providers(코드→라벨), $live(적용중 수).
    저장은 SettingsController::saveAiKeys() 가 ai.keys=[{provider,api_key}] 로 유지(런타임 로직 불변).
--}}
@php
    // 기존 저장분 → provider 코드 => api_key 맵(고정칸 프리필)
    $__keys = collect($rows)->filter(fn ($r) => is_array($r) && ! empty($r['provider']))
        ->mapWithKeys(fn ($r) => [$r['provider'] => ($r['api_key'] ?? '')])->all();
    // 공급자별 발급처 안내
    $__hint = [
        'anthropic' => '발급: console.anthropic.com → API Keys',
        'google' => '발급: aistudio.google.com/apikey',
        'openai' => '발급: platform.openai.com/api-keys',
    ];
@endphp
<div class="card p-5 mb-4">
    <div class="flex items-center gap-2 mb-1">
        <span class="text-ink font-semibold" style="font-size:var(--fs-sm);">AI 모델 API 키</span>
        <span class="badge" style="font-size:var(--fs-xs);padding:2px 9px;{{ $live > 0 ? 'background:color-mix(in srgb,var(--color-success) 14%,var(--color-canvas));color:var(--color-success);' : '' }}">{{ $live }}개 적용중</span>
    </div>
    <p class="text-muted-soft mb-4" style="font-size:var(--fs-xs);">
        공급자별 API 키를 등록하세요. 커뮤니티 글 재작성·캡차 분석 등 AI 기능에 사용됩니다. 값은 <b>암호화 저장</b>되며, 비우면 해당 공급자 키가 삭제됩니다.
    </p>

    @foreach ($providers as $code => $label)
        @include('admin.settings._simplefield', [
            'name' => "ai_key[$code]",
            'label' => $label,
            'value' => $__keys[$code] ?? '',
            'secret' => true,
            'placeholder' => $__hint[$code] ?? 'API 키',
        ])
    @endforeach
</div>
