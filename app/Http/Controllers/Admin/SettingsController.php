<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\Request;

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
        ]);
    }

    public function update(Request $request)
    {
        foreach (self::GROUPS as $key => $def) {
            $this->saveGroup($request, $key, $def['g'], $def['plain'], $def['secret']);
        }
        $this->saveAiKeys($request);

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
