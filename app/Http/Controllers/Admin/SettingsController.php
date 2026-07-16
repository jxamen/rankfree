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
    ];

    public function index()
    {
        return view('admin.settings.index', [
            'searchadRows' => AppSetting::readJson('searchad.accounts'),
            'adsRows' => AppSetting::readJson('ads.logins'),
            'openapiRows' => AppSetting::readJson('openapi.keys'),
            'aiRows' => AppSetting::readJson('ai.keys'),
            'aiProviders' => self::AI_PROVIDERS,
            // 실제 적용중(오버라이드 후) 개수
            'liveSearchad' => count((array) config('rankfree.searchad.accounts', [])) ?: (! empty(config('rankfree.searchad.api_key')) ? 1 : 0),
            'liveAds' => count((array) config('searchadweb.logins', [])) ?: (! empty(config('searchadweb.login.id')) ? 1 : 0),
            'liveOpenapi' => count((array) config('rankfree.shopping.api_keys', [])),
            'liveAi' => collect(['services.anthropic.key', 'services.gemini.key', 'services.openai.key'])
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
            // 회원 — 추천인 보상(순위체크 보너스 슬롯)
            'referralPer' => \App\Domain\Member\ReferralService::bonusPer(),
            'referralMax' => \App\Domain\Member\ReferralService::bonusMax(),
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
    ];

    public function update(Request $request)
    {
        foreach (self::GROUPS as $key => $def) {
            $this->saveGroup($request, $key, $def['g'], $def['plain'], $def['secret']);
        }
        $this->saveAiKeys($request);

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
        AppSetting::write('community.rewrite_provider', in_array($provider, ['auto', 'gemini', 'anthropic', 'off'], true) ? $provider : 'auto');
        AppSetting::write('community.rewrite_model', trim((string) $request->input('community_rewrite_model', '')));
        AppSetting::write('community.rewrite_fallback', $request->boolean('community_rewrite_fallback') ? '1' : '0');

        // 회원 — 추천인 보상 설정 (1회당 증가량 · 최대 증가량)
        AppSetting::write('referral.bonus_per', (string) max(0, (int) $request->input('referral_bonus_per', 20)));
        AppSetting::write('referral.bonus_max', (string) max(0, (int) $request->input('referral_bonus_max', 200)));

        return redirect()->route('admin.settings')->with('status', '환경 설정을 저장했습니다.');
    }

    /** AI 키 저장 — 공급자 선택 + 키. 폼 필드: ai_provider[] · ai_key[]. */
    private function saveAiKeys(Request $r): void
    {
        $providers = (array) $r->input('ai_provider', []);
        $keys = (array) $r->input('ai_key', []);
        $rows = max(count($providers), count($keys));

        $out = [];
        for ($i = 0; $i < $rows; $i++) {
            $p = trim((string) ($providers[$i] ?? ''));
            $k = trim((string) ($keys[$i] ?? ''));
            if ($p !== '' && $k !== '' && isset(self::AI_PROVIDERS[$p])) {
                $out[] = ['provider' => $p, 'api_key' => $k];
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
