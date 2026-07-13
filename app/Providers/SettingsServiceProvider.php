<?php

namespace App\Providers;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * DB 환경 설정(app_settings)을 config 로 런타임 오버라이드.
 * 어드민 '환경 설정'에서 저장한 네이버 자격증명이 .env 를 대신/보완한다(값이 있을 때만).
 * config:cache 상태에서도 boot 는 실행되어 in-memory 로 덮어쓴다.
 */
class SettingsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // 마이그레이션 이전/CLI 안전 가드
        try {
            if (! Schema::hasTable('app_settings')) {
                return;
            }
            $m = AppSetting::map();
        } catch (\Throwable) {
            return;
        }
        if (! $m) {
            return;
        }

        $rows = fn (string $key) => json_decode($m[$key] ?? '[]', true) ?: [];

        // 1) 네이버 검색광고 공식 API (키워드 분석) — 다중 계정
        $accounts = array_values(array_filter(array_map(
            fn ($a) => (is_array($a) && ! empty($a['api_key']) && ! empty($a['secret_key']) && ! empty($a['customer_id']))
                ? ['api_key' => trim((string) $a['api_key']), 'secret_key' => trim((string) $a['secret_key']), 'customer_id' => trim((string) $a['customer_id'])]
                : null,
            $rows('searchad.accounts'),
        )));
        if ($accounts) {
            config(['rankfree.searchad.accounts' => $accounts]);
            // 대표 계정을 단일 config 로도 반영(SearchAdClient 등 하위 호환)
            config([
                'rankfree.searchad.api_key' => $accounts[0]['api_key'],
                'rankfree.searchad.secret_key' => $accounts[0]['secret_key'],
                'rankfree.searchad.customer_id' => $accounts[0]['customer_id'],
            ]);
        }

        // 2) 네이버 광고주 웹 로그인 (성별·연령·트렌드 크롤링 세션) — 다중 로그인
        $logins = array_values(array_filter(array_map(
            fn ($a) => (is_array($a) && ! empty($a['id']) && ! empty($a['pw']))
                ? ['id' => trim((string) $a['id']), 'pw' => trim((string) $a['pw'])]
                : null,
            $rows('ads.logins'),
        )));
        if ($logins) {
            config(['searchadweb.logins' => $logins]);
            config(['searchadweb.login.id' => $logins[0]['id'], 'searchadweb.login.pw' => $logins[0]['pw']]);
        }

        // 3) 네이버 OpenAPI · 데이터랩(트렌드) 키 (다중) → shopping.api_keys
        $keys = array_values(array_filter(array_map(
            fn ($k) => (is_array($k) && ! empty($k['id']) && ! empty($k['secret']))
                ? ['id' => trim((string) $k['id']), 'secret' => trim((string) $k['secret'])]
                : null,
            $rows('openapi.keys'),
        )));
        if ($keys) {
            config(['rankfree.shopping.api_keys' => $keys]);
        }

        // 4) AI 모델 API 키 (Claude/Gemini/OpenAI 등) → services.{provider}.key (공급자별 첫 키가 대표)
        $providerConfig = ['anthropic' => 'services.anthropic.key', 'google' => 'services.gemini.key', 'openai' => 'services.openai.key'];
        $seen = [];
        foreach ($rows('ai.keys') as $row) {
            if (! is_array($row) || empty($row['provider']) || empty($row['api_key'])) {
                continue;
            }
            $p = (string) $row['provider'];
            if (isset($seen[$p]) || ! isset($providerConfig[$p])) {
                continue; // 공급자별 첫 키만 대표로
            }
            $seen[$p] = true;
            config([$providerConfig[$p] => trim((string) $row['api_key'])]);
        }
    }
}
