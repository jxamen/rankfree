# jcurve/ga4-insights

초보자도 바로 이해하는 **GA4(구글 애널리틱스 4) 상세 분석 대시보드** — Laravel 이식형 패키지.

유입(채널·소스/매체·캠페인) · 랜딩/이탈 · 인기 페이지 · 기기 · 지역 · 이벤트 · 신규/재방문 · 시간대 · 실시간을 한 화면에서, **기간 대비 증감**과 쉬운 한국어 설명·툴팁으로 보여준다. GA4 Data API(`runReport`/`batchRunReports`)를 **라이브**로 조회한다(사전 수집 불필요).

- 프레임워크 외 의존 없음(순수 Laravel + Guzzle/HTTP).
- 인증/속성ID는 `Ga4Credentials` 인터페이스로 **완전 분리** — 어떤 인증 방식이든 앱이 정한다.
- 자체 스코프 CSS(호스트 디자인 토큰 `--color-*`이 있으면 자동 상속, 없으면 폴백) — 어느 앱에 붙여도 자연스럽다.

## 설치 (다른 프로젝트로 이식)

### A. Composer(권장)

`composer.json` 에 path/vcs 저장소로 추가 후:

```bash
composer require jcurve/ga4-insights
php artisan vendor:publish --tag=ga4-insights-config   # (선택) config 커스터마이즈
```

서비스 프로바이더는 Laravel auto-discovery 로 자동 등록된다.

### B. 모노레포(같은 저장소 안)

`packages/ga4-insights/` 에 두고 루트 `composer.json` 에:

```json
"autoload": { "psr-4": { "Jcurve\\Ga4Insights\\": "packages/ga4-insights/src/" } }
```

`bootstrap/providers.php` 에 `Jcurve\Ga4Insights\Ga4InsightsServiceProvider::class` 추가 → `composer dump-autoload`.

## 연결 (필수 2가지)

### 1) 자격증명 — `Ga4Credentials` 구현·바인딩

GA4 속성 ID와 `analytics.readonly` 액세스 토큰을 어떻게 얻을지는 앱이 정한다.

```php
use Jcurve\Ga4Insights\Contracts\Ga4Credentials;

class AppGa4Credentials implements Ga4Credentials
{
    public function propertyId(): ?string  { return '해당 GA4 속성 ID(숫자)'; }
    public function accessToken(): ?string  { return '구글 OAuth/서비스계정 Bearer 토큰'; }
    public function isConfigured(): bool     { return $this->propertyId() && $this->accessToken(); }
}

// AppServiceProvider::register()
$this->app->bind(Ga4Credentials::class, AppGa4Credentials::class);
```

> 바인딩하지 않으면 기본 `ConfigGa4Credentials` 가 `config('ga4-insights.property_id')` + `access_token`(문자열 또는 callable)을 쓴다. 정적 토큰(서비스 계정에서 발급)만 있으면 코드 없이도 동작.

### 2) 마운트/레이아웃 — `config/ga4-insights.php`

```php
'route' => [
    'prefix' => 'admin/traffic-stats',   // 마운트 경로
    'name'   => 'admin.traffic-stats',    // route('admin.traffic-stats') / '.refresh'
    'middleware' => ['web', 'auth', 'operator'],
],
'view' => [
    'layout'  => 'admin.layout',   // 호스트 레이아웃(@extends). 미지정 시 패키지 내장 레이아웃
    'section' => 'admin-content',   // 콘텐츠를 넣을 @section 이름
],
'site_url'  => env('APP_URL'),      // 페이지 경로 링크용
'cache_ttl' => 600,                 // GA4 쿼터 절약(초)
'setup_help'=> null,                // 미연동 안내 HTML(앱별)
```

`view.layout` 을 비우면(`ga4-insights::layout`) 호스트 레이아웃 없이 **독립 페이지**로도 뜬다.

## 사전 준비(GA4 쪽)

1. GA4 관리 → 속성 설정의 **속성 ID(숫자)**.
2. **Google Analytics Data API** 활성화(GCP).
3. 접근 주체(서비스 계정 이메일 또는 OAuth 계정)를 GA4 속성 **액세스 관리 → 뷰어**로 추가.

## 구조

| 파일 | 역할 |
|---|---|
| `Contracts/Ga4Credentials` | 속성ID·토큰 공급 인터페이스(앱이 구현) |
| `Ga4Client` | GA4 Data API 클라이언트(batchRunReports·realtime) |
| `Ga4Reporter` | 전 섹션 리포트 빌더(기간 대비·캐시) |
| `Support/Format` | 숫자·비율·시간·증감 포매팅 |
| `Http/Ga4DashboardController` | 대시보드/새로고침 |
| `resources/views/dashboard` | 초보자용 섹션·툴팁 UI |

## 라이선스

MIT.
