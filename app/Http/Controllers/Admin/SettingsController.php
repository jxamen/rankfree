<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * 환경 설정 (운영자) — 네이버 API 자격증명 관리(모두 다중 등록/삭제).
 * 값은 암호화 저장되고 SettingsServiceProvider 가 config 를 런타임 오버라이드한다.
 */
class SettingsController extends Controller
{
    private const SECONDARY_DOMAINS_KEY = 'secondary.domains';
    private const CLOUDFLARE_API_TOKEN_KEY = 'cloudflare.api_token';
    private const CLOUDFLARE_DNS_TARGET_KEY = 'cloudflare.dns_target';
    private const CLOUDFLARE_ZONES_KEY = 'cloudflare.zones';
    private const CLOUDFLARE_API_BASE = 'https://api.cloudflare.com/client/v4';

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
            'secondaryDomains' => self::normalizeDomains(AppSetting::readJson(self::SECONDARY_DOMAINS_KEY)),
            'cloudflareApiToken' => AppSetting::read(self::CLOUDFLARE_API_TOKEN_KEY),
            'cloudflareDnsTarget' => AppSetting::read(self::CLOUDFLARE_DNS_TARGET_KEY) ?: self::defaultDnsTarget(request()),
            'cloudflareZones' => self::normalizeCloudflareZones(AppSetting::readJson(self::CLOUDFLARE_ZONES_KEY)),
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
            // 재작성 추론(thinking) — 기본 끔(비용 절감). on/1/true 만 켬.
            'rewriteThinking' => in_array(strtolower((string) config('rankfree.community.rewrite.thinking')), ['1', 'true', 'on'], true),
            // 캡차(퀴즈) 분석 모델 — 멀티 공급자(Gemini/OpenAI/Claude/Grok). 비우면 기본(gemini-pro-latest)
            'quizModel' => (string) AppSetting::read('quiz.model'),
            'quizModelLive' => (string) (config('rankfree.quiz.model') ?: 'gemini-pro-latest'),
            'quizSolveTimeout' => (int) (config('services.gemini.quiz_timeout') ?: 10),
            // 캡차 풀이 추론(thinking) — 기본 끔(비용 절감). on/1/true 만 켬. 뷰엔 'on'/'off'로 정규화.
            'quizThinking' => in_array(strtolower((string) (AppSetting::read('quiz.thinking') ?: config('services.gemini.quiz_thinking'))), ['1', 'true', 'on'], true) ? 'on' : 'off',
            'quizThinking' => AppSetting::read('quiz.thinking') === '1',
            // 회원 — 추천인 보상(순위체크 보너스 슬롯)
            'referralPer' => \App\Domain\Member\ReferralService::bonusPer(),
            'referralMax' => \App\Domain\Member\ReferralService::bonusMax(),
            // 구글 서치 콘솔 — HTML 소유확인 토큰(공개 <head> 메타로 출력)
            'googleSiteVerification' => (string) AppSetting::read('google.site_verification'),
            // 구글 서치 콘솔 — 속성 + 서비스 계정 안내
            'gscProperty' => AppSetting::read('gsc.property') ?: 'sc-domain:rankfree.kr',
            'gscServiceEmail' => \App\Support\GoogleServiceAccount::clientEmail(),
            // GA4 — 속성 ID(숫자)
            'gaPropertyId' => AppSetting::read('ga.property_id'),
            // 서울 열린데이터광장 — 신규 개업(인허가) 수집 인증키(24)
            'seoulOpenapiKey' => AppSetting::read('seoul.openapi_key'),
            'jandiOrderWebhookUrl' => AppSetting::read('jandi.order_webhook_url'),
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
        'google.site_verification' => 'google_site_verification',   // 서치 콘솔 HTML 소유확인 토큰 → services.google.site_verification
        'gsc.property' => 'gsc_property',
        'ga.property_id' => 'ga_property_id',
        'seoul.openapi_key' => 'seoul_openapi_key',
        'jandi.order_webhook_url' => 'jandi_order_webhook_url',   // 주문 접수 알림 웹훅(잔디 Incoming Webhook)
        'quiz.model' => 'quiz_model',   // 캡차(퀴즈) 이미지 분석 모델 → services.gemini.quiz_model
        'quiz.solve_timeout' => 'quiz_solve_timeout',   // 확장 정답 대기 시간(초) → services.gemini.quiz_timeout
        'quiz.thinking' => 'quiz_thinking',   // 캡차 풀이 추론(thinking) on/off → services.gemini.quiz_thinking
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

        $this->saveSecondaryDomains($request);
        $this->saveCloudflareSettings($request);

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
        AppSetting::write('community.rewrite_thinking', $request->boolean('community_rewrite_thinking') ? '1' : '0');

        // 캡차 퀴즈 추론(thinking) 사용 여부 — 끄면 비용 대폭 절감
        AppSetting::write('quiz.thinking', $request->boolean('quiz_thinking') ? '1' : '0');

        // 회원 — 추천인 보상 설정 (1회당 증가량 · 최대 증가량)
        AppSetting::write('referral.bonus_per', (string) max(0, (int) $request->input('referral_bonus_per', 20)));
        AppSetting::write('referral.bonus_max', (string) max(0, (int) $request->input('referral_bonus_max', 200)));

        // 저장 후에도 보던 탭 유지
        $tab = in_array($request->input('tab'), ['basic', 'api', 'integ', 'member', 'place', 'domains', 'custom'], true) ? $request->input('tab') : null;

        return redirect()->route('admin.settings', array_filter(['tab' => $tab]))->with('status', '환경 설정을 저장했습니다.');
    }

    public function createSecondaryDomain(Request $request)
    {
        $request->validate([
            'zone_domain' => ['required', 'string', 'max:253'],
            'subdomain' => ['nullable', 'string', 'max:253'],
            'dns_target' => ['nullable', 'string', 'max:253'],
            'count' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $token = self::normalizeCloudflareApiToken((string) AppSetting::read(self::CLOUDFLARE_API_TOKEN_KEY));
        if ($token === '') {
            return back()
                ->withInput()
                ->withErrors(['cloudflare' => 'Cloudflare API token is required.']);
        }
        if (self::looksLikeCloudflareGlobalApiKey($token)) {
            return back()
                ->withInput()
                ->withErrors(['cloudflare' => 'cfk_로 시작하는 값은 Global API Key입니다. 여기에는 Cloudflare API Token 값을 넣어야 합니다. 사용자 API Token은 보통 cfut_, 계정 API Token은 cfat_로 시작합니다.']);
        }

        $zoneDomain = self::normalizeDomain((string) $request->input('zone_domain'));
        $zones = self::normalizeCloudflareZones(AppSetting::readJson(self::CLOUDFLARE_ZONES_KEY));
        $zone = collect($zones)->firstWhere('domain', $zoneDomain);
        if ($zoneDomain === null || ! $zone) {
            return back()
                ->withInput()
                ->withErrors(['cloudflare' => 'Register the Cloudflare connected domain first.']);
        }

        $count = max(1, min(50, (int) $request->input('count', 1)));
        $prefix = (string) $request->input('subdomain', '');
        $domains = $this->buildSecondaryDomains($prefix, $zoneDomain, $count);
        if ($domains === []) {
            return back()
                ->withInput()
                ->withErrors(['cloudflare' => 'Enter a valid subdomain.']);
        }

        $target = self::normalizeDnsTarget(
            (string) ($request->input('dns_target') ?: AppSetting::read(self::CLOUDFLARE_DNS_TARGET_KEY) ?: self::defaultDnsTarget($request))
        );
        if ($target === null) {
            return back()
                ->withInput()
                ->withErrors(['cloudflare' => 'Enter the server target host or IP for the DNS record.']);
        }

        try {
            $zoneId = trim((string) ($zone['zone_id'] ?? ''));
            if ($zoneId === '') {
                $zoneId = $this->resolveCloudflareZoneId($token, $zoneDomain);
                AppSetting::write(
                    self::CLOUDFLARE_ZONES_KEY,
                    json_encode(self::withCloudflareZoneId($zones, $zoneDomain, $zoneId), JSON_UNESCAPED_UNICODE)
                );
            }

            foreach ($domains as $domain) {
                $this->upsertCloudflareDnsRecord($token, $zoneId, $domain, $target, (bool) ($zone['proxied'] ?? true));
            }
        } catch (\Throwable $e) {
            return back()
                ->withInput()
                ->withErrors(['cloudflare' => 'Cloudflare DNS create failed: '.$e->getMessage()]);
        }

        AppSetting::write(self::CLOUDFLARE_DNS_TARGET_KEY, $target);
        AppSetting::write(
            self::SECONDARY_DOMAINS_KEY,
            json_encode(self::normalizeDomains([...AppSetting::readJson(self::SECONDARY_DOMAINS_KEY), ...$domains]), JSON_UNESCAPED_UNICODE)
        );

        $message = $count === 1
            ? '2차 도메인을 생성했습니다: '.$domains[0]
            : '2차 도메인 '.$count.'개를 생성했습니다: '.implode(', ', $domains);

        return redirect()
            ->route('admin.settings', ['tab' => 'domains'])
            ->with('status', $message);
    }

    private function saveSecondaryDomains(Request $request): void
    {
        AppSetting::write(
            self::SECONDARY_DOMAINS_KEY,
            json_encode(self::normalizeDomains((array) $request->input('secondary_domains', [])), JSON_UNESCAPED_UNICODE)
        );
    }

    private function saveCloudflareSettings(Request $request): void
    {
        AppSetting::write(self::CLOUDFLARE_API_TOKEN_KEY, self::normalizeCloudflareApiToken((string) $request->input('cloudflare_api_token', '')));
        AppSetting::write(
            self::CLOUDFLARE_DNS_TARGET_KEY,
            self::normalizeDnsTarget((string) $request->input('cloudflare_dns_target', '')) ?? ''
        );
        AppSetting::write(
            self::CLOUDFLARE_ZONES_KEY,
            json_encode(self::normalizeCloudflareZonesFromRequest($request), JSON_UNESCAPED_UNICODE)
        );
    }

    private static function normalizeDomains(array $values): array
    {
        $domains = [];
        foreach ($values as $value) {
            if (is_array($value)) {
                $value = $value['domain'] ?? '';
            }
            $domain = self::normalizeDomain((string) $value);
            if ($domain !== null && ! in_array($domain, $domains, true)) {
                $domains[] = $domain;
            }
        }

        return $domains;
    }

    private static function normalizeDomain(string $value): ?string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/\s+/', '', $value) ?? '';
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, '//')) {
            $host = parse_url('https:'.$value, PHP_URL_HOST);
        } elseif (str_contains($value, '://')) {
            $host = parse_url($value, PHP_URL_HOST);
        } else {
            $host = preg_split('/[\/?#]/', $value, 2)[0] ?? '';
        }

        $host = trim((string) $host, '.');
        $host = preg_replace('/:\d+$/', '', $host) ?? '';
        if ($host === '') {
            return null;
        }

        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = strtolower($ascii);
            }
        }

        return preg_match('/^(?=.{1,253}$)(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', $host)
            ? $host
            : null;
    }

    private static function normalizeCloudflareZonesFromRequest(Request $request): array
    {
        $domains = (array) $request->input('cloudflare_zone_domain', []);
        $zoneIds = (array) $request->input('cloudflare_zone_id', []);
        $rows = [];
        $count = max(count($domains), count($zoneIds));

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'domain' => $domains[$i] ?? '',
                'zone_id' => $zoneIds[$i] ?? '',
                'proxied' => true,
            ];
        }

        return self::normalizeCloudflareZones($rows);
    }

    private static function normalizeCloudflareApiToken(string $value): string
    {
        $value = trim($value);
        $value = trim($value, "\"'");
        $value = preg_replace('/\s+/', '', $value) ?? '';

        return preg_replace('/^bearer/i', '', $value) ?? '';
    }

    private static function looksLikeCloudflareGlobalApiKey(string $value): bool
    {
        return str_starts_with(strtolower(trim($value)), 'cfk_');
    }

    private static function normalizeCloudflareZones(array $values): array
    {
        $zones = [];

        foreach ($values as $value) {
            $domain = self::normalizeDomain((string) (is_array($value) ? ($value['domain'] ?? '') : $value));
            if ($domain === null) {
                continue;
            }

            $zones[$domain] = [
                'domain' => $domain,
                'zone_id' => trim((string) (is_array($value) ? ($value['zone_id'] ?? '') : '')),
                'proxied' => is_array($value) ? (bool) ($value['proxied'] ?? true) : true,
            ];
        }

        return array_values($zones);
    }

    private static function withCloudflareZoneId(array $zones, string $domain, string $zoneId): array
    {
        foreach ($zones as &$zone) {
            if (($zone['domain'] ?? null) === $domain) {
                $zone['zone_id'] = $zoneId;
                $zone['proxied'] = (bool) ($zone['proxied'] ?? true);
            }
        }
        unset($zone);

        return self::normalizeCloudflareZones($zones);
    }

    private function buildSecondaryDomains(string $value, string $zoneDomain, int $count): array
    {
        $value = strtolower(trim($value));

        if ($count === 1 && $value !== '') {
            $domain = self::normalizeSubdomain($value, $zoneDomain);

            return $domain === null ? [] : [$domain];
        }

        $prefix = self::normalizeDnsLabel(str_ends_with($value, '.'.$zoneDomain)
            ? substr($value, 0, -strlen('.'.$zoneDomain))
            : $value);
        $domains = [];
        $used = [];

        for ($i = 0; $i < $count; $i++) {
            $label = $this->randomSubdomainLabel($prefix, $used);
            $domain = self::normalizeDomain($label.'.'.$zoneDomain);
            if ($domain !== null) {
                $domains[] = $domain;
            }
        }

        return $domains;
    }

    private static function normalizeDnsLabel(string $value): ?string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/\s+/', '-', $value) ?? '';
        $value = preg_replace('/[^a-z0-9-]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        if ($value === '') {
            return null;
        }

        $value = substr($value, 0, 40);
        $value = trim($value, '-');

        return preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)$/', $value) ? $value : null;
    }

    private function randomSubdomainLabel(?string $prefix, array &$used): string
    {
        $words = [
            'able', 'amber', 'atlas', 'beam', 'bright', 'calm', 'clear', 'core', 'daily', 'dawn',
            'delta', 'easy', 'focus', 'fresh', 'glow', 'green', 'happy', 'harbor', 'kind', 'light',
            'lucky', 'mint', 'nova', 'olive', 'orbit', 'prime', 'quiet', 'river', 'round', 'silver',
            'smart', 'solid', 'spark', 'stone', 'sunny', 'swift', 'true', 'urban', 'vivid', 'wave',
        ];

        do {
            $word = $words[random_int(0, count($words) - 1)];
            $suffix = strtolower(substr(bin2hex(random_bytes(3)), 0, 5));
            $base = $prefix ? substr($prefix, 0, max(1, 63 - strlen($word) - strlen($suffix) - 2)).'-' : '';
            $label = trim($base.$word.'-'.$suffix, '-');
        } while (isset($used[$label]));

        $used[$label] = true;

        return $label;
    }

    private static function normalizeSubdomain(string $value, string $zoneDomain): ?string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/\s+/', '', $value) ?? '';
        $value = trim($value, '.');
        if ($value === '' || str_contains($value, '*')) {
            return null;
        }

        $domain = self::normalizeDomain($value);
        if ($domain !== null && str_ends_with($domain, '.'.$zoneDomain)) {
            return $domain;
        }

        if (str_contains($value, '://') || str_contains($value, '/') || str_contains($value, '?')) {
            return null;
        }

        return self::normalizeDomain($value.'.'.$zoneDomain);
    }

    private static function normalizeDnsTarget(string $value): ?string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/\s+/', '', $value) ?? '';
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, '//')) {
            $host = parse_url('https:'.$value, PHP_URL_HOST);
        } elseif (str_contains($value, '://')) {
            $host = parse_url($value, PHP_URL_HOST);
        } else {
            $host = preg_split('/[\/?#]/', $value, 2)[0] ?? '';
        }

        $host = trim((string) $host, '.');
        $host = preg_replace('/:\d+$/', '', $host) ?? '';
        if ($host === '') {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        return self::normalizeDomain($host);
    }

    private static function defaultDnsTarget(Request $request): string
    {
        $host = self::normalizeDnsTarget((string) config('app.url'));
        if ($host !== null && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return $host;
        }

        $host = self::normalizeDnsTarget($request->getHost());

        return $host !== null && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true) ? $host : '';
    }

    private static function dnsRecordType(string $target): string
    {
        if (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return 'A';
        }

        if (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return 'AAAA';
        }

        return 'CNAME';
    }

    private function resolveCloudflareZoneId(string $token, string $zoneDomain): string
    {
        $payload = $this->cloudflareRequest($token, 'GET', '/zones', [
            'name' => $zoneDomain,
            'status' => 'active',
            'per_page' => 1,
        ]);

        $zone = collect($payload['result'] ?? [])->first(fn ($row) => ($row['name'] ?? null) === $zoneDomain);
        $zoneId = trim((string) ($zone['id'] ?? ''));
        if ($zoneId === '') {
            throw new \RuntimeException('Cloudflare zone not found: '.$zoneDomain);
        }

        return $zoneId;
    }

    private function upsertCloudflareDnsRecord(string $token, string $zoneId, string $fqdn, string $target, bool $proxied): array
    {
        $record = [
            'type' => self::dnsRecordType($target),
            'name' => $fqdn,
            'content' => $target,
            'ttl' => 1,
            'proxied' => $proxied,
        ];

        $existing = $this->cloudflareRequest($token, 'GET', '/zones/'.$zoneId.'/dns_records', [
            'name' => $fqdn,
            'per_page' => 20,
        ]);
        $existingRecord = collect($existing['result'] ?? [])->first();

        if ($existingRecord && ! empty($existingRecord['id'])) {
            return $this->cloudflareRequest($token, 'PATCH', '/zones/'.$zoneId.'/dns_records/'.$existingRecord['id'], $record);
        }

        return $this->cloudflareRequest($token, 'POST', '/zones/'.$zoneId.'/dns_records', $record);
    }

    private function cloudflareRequest(string $token, string $method, string $path, array $data = []): array
    {
        $client = Http::withToken($token)->acceptJson()->asJson()->timeout(20);
        $url = self::CLOUDFLARE_API_BASE.$path;
        $response = match (strtoupper($method)) {
            'GET' => $client->get($url, $data),
            'POST' => $client->post($url, $data),
            'PATCH' => $client->patch($url, $data),
            default => throw new \InvalidArgumentException('Unsupported Cloudflare method: '.$method),
        };
        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];

        if (! $response->successful() || ! (bool) ($payload['success'] ?? false)) {
            $message = self::cloudflareErrorMessage($payload) ?: $response->body() ?: 'Cloudflare API request failed.';
            if (str_contains($message, '9109')) {
                $message .= ' API Token 생성 완료 화면에서 복사한 cfut_/cfat_ 토큰을 입력하세요. cfk_ Global API Key는 Bearer 토큰으로 사용할 수 없습니다.';
            }
            throw new \RuntimeException($message);
        }

        return $payload;
    }

    private static function cloudflareErrorMessage(array $payload): string
    {
        return collect($payload['errors'] ?? [])
            ->map(fn ($error) => trim(((string) ($error['code'] ?? '')).' '.((string) ($error['message'] ?? ''))))
            ->filter()
            ->implode(' / ');
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
