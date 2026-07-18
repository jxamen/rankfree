<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * 환경 설정 (운영자) — 네이버 API 자격증명 관리(모두 다중 등록/삭제).
 * 값은 암호화 저장되고 SettingsServiceProvider 가 config 를 런타임 오버라이드한다.
 */
class SettingsController extends Controller
{
    /** 각 자격증명 그룹 정의: setting키 => [폼접두, 일반필드, 시크릿필드]. */
    private const GROUPS = [
        'searchad.accounts' => ['g' => 'searchad', 'plain' => ['api_key', 'customer_id'], 'secret' => 'secret_key'],
        'ads.logins' => ['g' => 'ads', 'plain' => ['id'], 'secret' => 'pw'],
        'openapi.keys' => ['g' => 'openapi', 'plain' => ['id'], 'secret' => 'secret'],
    ];

    /** AI 모델 공급자(선택지) → services.{key} 매핑 라벨. */
    private const AI_PROVIDERS = [
        'anthropic' => 'Claude (Anthropic)',
        'google' => 'Gemini (Google)',
        'openai' => 'OpenAI (GPT)',
        'xai' => 'Grok (xAI)',
    ];

    public function index(\App\Domain\Keyword\PlaceKeywordPatterns $patterns)
    {
        return view('admin.settings.index', [
            // 플레이스 업종별 패턴(지역 × 패턴 조합 시딩에 사용) — 넣고 뺄 수 있게
            'placePatterns' => $patterns->all(),
            'searchadRows' => AppSetting::readJson('searchad.accounts'),
            'adsRows' => AppSetting::readJson('ads.logins'),
            'openapiRows' => AppSetting::readJson('openapi.keys'),
            'aiRows' => AppSetting::readJson('ai.keys'),
            'aiProviders' => self::AI_PROVIDERS,
            // 실제 적용중(오버라이드 후) 개수
            'liveSearchad' => count((array) config('rankfree.searchad.accounts', [])) ?: (! empty(config('rankfree.searchad.api_key')) ? 1 : 0),
            'liveAds' => count((array) config('searchadweb.logins', [])) ?: (! empty(config('searchadweb.login.id')) ? 1 : 0),
            'liveOpenapi' => count((array) config('rankfree.shopping.api_keys', [])),
            'liveAi' => collect(['services.anthropic.key', 'services.gemini.key', 'services.openai.key', 'services.xai.key'])
                ->filter(fn ($k) => ! empty(config($k)))->count(),
            // 커스텀 head 코드(모든 페이지 <head> 주입)
            'customCss' => AppSetting::read('custom.head_css'),
            'customHtml' => AppSetting::read('custom.head_html'),
            // 외부 연동 키 — Cloudflare Turnstile · Google/Kakao 소셜 · 알리고 SMS
            'turnstileSiteKey' => AppSetting::read('turnstile.site_key'),
            'turnstileSecret' => AppSetting::read('turnstile.secret'),
            'googleClientId' => AppSetting::read('google.client_id'),
            'googleClientSecret' => AppSetting::read('google.client_secret'),
            'kakaoClientId' => AppSetting::read('kakao.client_id'),
            'kakaoClientSecret' => AppSetting::read('kakao.client_secret'),
            'aligoUserId' => AppSetting::read('aligo.user_id'),
            'aligoApiKey' => AppSetting::read('aligo.api_key'),
            'aligoSender' => AppSetting::read('aligo.sender') ?: '1668-3721',
            // 커뮤니티 글 재작성(AI) — SettingsServiceProvider 가 이미 config 에 반영한 값
            'rewriteProvider' => (string) config('rankfree.community.rewrite.provider', 'auto'),
            'rewriteModel' => (string) config('rankfree.community.rewrite.model', ''),
            'rewriteFallback' => (bool) config('rankfree.community.rewrite.fallback', true),
            // 캡차(퀴즈) 분석 모델 — 멀티 공급자(Gemini/OpenAI/Claude/Grok). 비우면 기본(gemini-pro-latest)
            'quizModel' => (string) AppSetting::read('quiz.model'),
            'quizModelLive' => (string) (config('rankfree.quiz.model') ?: 'gemini-pro-latest'),
            // 회원 — 추천인 보상(순위체크 보너스 슬롯)
            'referralPer' => \App\Domain\Member\ReferralService::bonusPer(),
            'referralMax' => \App\Domain\Member\ReferralService::bonusMax(),
            // 구글 서치 콘솔 — 속성 + 서비스 계정 안내
            'gscProperty' => AppSetting::read('gsc.property') ?: 'sc-domain:rankfree.kr',
            'gscServiceEmail' => \App\Support\GoogleServiceAccount::clientEmail(),
            // GA4 — 속성 ID(숫자)
            'gaPropertyId' => AppSetting::read('ga.property_id'),
            // 서울 열린데이터광장 — 신규 개업(인허가) 수집 인증키(24)
            'seoulOpenapiKey' => AppSetting::read('seoul.openapi_key'),
            'seoulKeyLive' => (string) config('rankfree.newbiz.seoul_key', 'sample'),
            // 구글 OAuth 연동 상태 (서치 콘솔·GA4 공용)
            'googleConnected' => \App\Support\GoogleToken::oauthConnected(),
            'googleEmail' => \App\Support\GoogleToken::connectedEmail(),
        ]);
    }

    /** 단일 값 연동 키: setting키 => 폼필드명. config('services.*')는 SettingsServiceProvider 가 오버라이드. */
    private const SIMPLE = [
        'turnstile.site_key' => 'turnstile_site_key',
        'turnstile.secret' => 'turnstile_secret',
        'google.client_id' => 'google_client_id',
        'google.client_secret' => 'google_client_secret',
        'kakao.client_id' => 'kakao_client_id',
        'kakao.client_secret' => 'kakao_client_secret',
        'aligo.user_id' => 'aligo_user_id',
        'aligo.api_key' => 'aligo_api_key',
        'aligo.sender' => 'aligo_sender',
        'gsc.property' => 'gsc_property',
        'ga.property_id' => 'ga_property_id',
        'seoul.openapi_key' => 'seoul_openapi_key',
        'quiz.model' => 'quiz_model',   // 캡차(퀴즈) 이미지 분석 모델 → services.gemini.quiz_model
    ];

    public function update(Request $request, \App\Domain\Keyword\PlaceKeywordPatterns $patterns)
    {
        foreach (self::GROUPS as $key => $def) {
            $this->saveGroup($request, $key, $def['g'], $def['plain'], $def['secret']);
        }
        $this->saveAiKeys($request);

        // 플레이스 업종별 패턴 — 콤마/줄바꿈 구분 입력을 배열로. 탭을 열어 제출했을 때만 저장한다.
        if ($request->has('place_patterns')) {
            $patterns->save(collect((array) $request->input('place_patterns', []))
                ->map(fn ($raw) => \App\Domain\Keyword\PlaceKeywordPatterns::parse((string) $raw))->all());
        }

        // 커스텀 head 코드(CSS·스크립트/HTML) 저장 + 캐시 무효화
        AppSetting::write('custom.head_css', (string) $request->input('custom_head_css', ''));
        AppSetting::write('custom.head_html', (string) $request->input('custom_head_html', ''));
        Cache::forget(AppSetting::CUSTOM_HEAD_CACHE);

        // 외부 연동 단일 값 키 저장(Turnstile·소셜·알리고)
        foreach (self::SIMPLE as $key => $field) {
            AppSetting::write($key, trim((string) $request->input($field, '')));
        }

        // 커뮤니티 글 재작성(AI) 설정
        $provider = (string) $request->input('community_rewrite_provider', 'auto');
        AppSetting::write('community.rewrite_provider', in_array($provider, ['auto', 'gemini', 'anthropic', 'openai', 'xai', 'off'], true) ? $provider : 'auto');
        AppSetting::write('community.rewrite_model', trim((string) $request->input('community_rewrite_model', '')));
        AppSetting::write('community.rewrite_fallback', $request->boolean('community_rewrite_fallback') ? '1' : '0');

        // 회원 — 추천인 보상 설정 (1회당 증가량 · 최대 증가량)
        AppSetting::write('referral.bonus_per', (string) max(0, (int) $request->input('referral_bonus_per', 20)));
        AppSetting::write('referral.bonus_max', (string) max(0, (int) $request->input('referral_bonus_max', 200)));

        // 저장 후에도 보던 탭 유지
        $tab = in_array($request->input('tab'), ['basic', 'api', 'integ', 'member', 'custom'], true) ? $request->input('tab') : null;

        return redirect()->route('admin.settings', array_filter(['tab' => $tab]))->with('status', '환경 설정을 저장했습니다.');
    }

    /** AI 키 저장 — 공급자별 고정칸. 폼 필드: ai_key[{provider}]. 저장 포맷은 ai.keys=[{provider,api_key}] 유지. */
    private function saveAiKeys(Request $r): void
    {
        $keys = (array) $r->input('ai_key', []);   // ['anthropic'=>키, 'google'=>키, 'openai'=>키]

        $out = [];
        foreach (array_keys(self::AI_PROVIDERS) as $code) {
            $k = trim((string) ($keys[$code] ?? ''));
            if ($k !== '') {
                $out[] = ['provider' => $code, 'api_key' => $k];
            }
        }

        AppSetting::write('ai.keys', json_encode(array_values($out), JSON_UNESCAPED_UNICODE));
    }

    /**
     * 그룹 저장 — 폼의 균일 배열을 인덱스로 zip. 모든 일반필드 + 시크릿이 채워진 줄만 보존.
     * 폼 필드: {g}_{field}[] · {g}_{secret}[] (삭제된 줄은 애초에 전송되지 않음).
     */
    private function saveGroup(Request $r, string $key, string $g, array $plain, string $secret): void
    {
        $secrets = (array) $r->input("{$g}_{$secret}", []);
        $plainArrs = [];
        foreach ($plain as $f) {
            $plainArrs[$f] = (array) $r->input("{$g}_{$f}", []);
        }
        $rows = max(count($secrets), ...array_map('count', array_values($plainArrs) ?: [[]]));

        $out = [];
        for ($i = 0; $i < $rows; $i++) {
            $entry = [];
            $allPlain = true;
            foreach ($plain as $f) {
                $v = trim((string) ($plainArrs[$f][$i] ?? ''));
                $entry[$f] = $v;
                if ($v === '') {
                    $allPlain = false;
                }
            }
            $entry[$secret] = trim((string) ($secrets[$i] ?? ''));
            if ($allPlain && $entry[$secret] !== '') {
                $out[] = $entry;
            }
        }

        AppSetting::write($key, json_encode(array_values($out), JSON_UNESCAPED_UNICODE));
    }
}
