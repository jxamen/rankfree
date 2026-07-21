<?php

namespace App\Domain\Seo;

use App\Console\Commands\SitemapRefresh;
use App\Models\KeywordCategory;
use App\Models\KeywordSearch;
use App\Support\GoogleToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 검색엔진 발행 알림(21_SEO_SLUG_SITEMAP) — 공식 지원 경로만 사용한다.
 *  · IndexNow(네이버·빙·얀덱스): 신규/갱신 URL 즉시 제출. 구글은 미참여.
 *  · 구글: 폐기된 sitemap ping 대신 Search Console API sitemaps.submit 재제출.
 *  · Indexing API 는 채용공고·라이브방송 전용(일반 콘텐츠는 정책 위반)이라 쓰지 않는다.
 * 실패해도 발행 흐름을 막지 않는다 — 로그를 남기고 결과 메시지만 돌려준다.
 */
class SearchEnginePing
{
    private const GSC_SCOPE = 'https://www.googleapis.com/auth/webmasters';

    public static function enabled(): bool
    {
        return (bool) config('seo-ping.enabled', true);
    }

    /**
     * 허브 발행 직후 알림 — 사이트맵 캐시 버전을 올려 새 URL·lastmod 를 반영시키고,
     * IndexNow 로 문서·허브 페이지 URL 을 보내고, 구글엔 사이트맵을 재제출한다.
     * (hub:refresh 갱신은 사이트맵 lastmod 로 충분해 알리지 않는다 — 대량 반복 제출 방지)
     *
     * @param  \Illuminate\Support\Collection<int,Model>|array  $docs
     * @return string 요약 메시지(관리자 플래시·콘솔 출력용). 비활성/대상 없음이면 빈 문자열.
     */
    public function afterHubPublish($docs): string
    {
        $docs = collect($docs)->filter(fn ($d) => $d instanceof Model && $d->exists); // 미저장 모델 방어(shareUrl 이 저장을 유발)
        if (! self::enabled() || $docs->isEmpty()) {
            return '';
        }

        $this->bumpSitemapVersion();

        $urls = $docs->filter(fn ($d) => method_exists($d, 'shareUrl'))->map(fn ($d) => $d->shareUrl())->all();
        $urls[] = route('keywords.index');
        $catIds = $docs
            ->filter(fn ($d) => $d instanceof KeywordSearch)
            ->pluck('category_id')->filter()->unique()->values();
        if ($catIds->isNotEmpty()) {
            foreach (KeywordCategory::whereIn('id', $catIds)->pluck('slug') as $slug) {
                $urls[] = route('keywords.category', $slug);
            }
        }

        $in = $this->pingIndexNow($urls);
        $gsc = $this->submitSitemapToGoogle();

        return "검색엔진 알림 — IndexNow(네이버·빙): {$in['message']} · 구글 사이트맵: {$gsc['message']}";
    }

    /**
     * IndexNow 일괄 제출(https://www.indexnow.org — 네이버·빙·얀덱스 공유).
     * 한 엔드포인트에 보내면 참여 엔진 전체에 전파된다. 200/202 = 접수.
     *
     * @param  string[]  $urls
     * @return array{ok: bool, message: string}
     */
    public function pingIndexNow(array $urls): array
    {
        $key = (string) config('seo-ping.indexnow.key');
        if ($key === '') {
            return ['ok' => false, 'message' => '건너뜀(INDEXNOW_KEY 미설정)'];
        }
        $urls = array_values(array_unique(array_map([$this, 'encodeUrl'], $urls)));
        if ($urls === []) {
            return ['ok' => false, 'message' => '보낼 URL 없음'];
        }

        try {
            $res = Http::timeout(15)->post((string) config('seo-ping.indexnow.endpoint'), [
                'host' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'rankfree.kr',
                'key' => $key,
                'keyLocation' => url('/'.$key.'.txt'),
                'urlList' => $urls,
            ]);
        } catch (\Throwable $e) {
            Log::warning('indexnow: 전송 실패 — '.$e->getMessage());

            return ['ok' => false, 'message' => '전송 실패('.$e->getMessage().')'];
        }

        $ok = in_array($res->status(), [200, 202], true);
        if (! $ok) {
            Log::warning('indexnow: HTTP '.$res->status().' — '.mb_substr($res->body(), 0, 200));
        }

        return ['ok' => $ok, 'message' => $ok ? count($urls).'건 제출' : 'HTTP '.$res->status()];
    }

    /**
     * 구글 서치 콘솔 사이트맵 재제출 — 폐기된 sitemap ping 의 공식 대체.
     * PUT sites/{property}/sitemaps/{feedpath}. 쓰기 스코프(webmasters) 토큰 필요.
     *
     * @return array{ok: bool, message: string}
     */
    public function submitSitemapToGoogle(): array
    {
        if (! config('seo-ping.gsc_sitemap_submit', true)) {
            return ['ok' => false, 'message' => '건너뜀(비활성)'];
        }
        $token = GoogleToken::token(self::GSC_SCOPE);
        if (! $token) {
            $hint = GoogleToken::oauthConnected()
                ? '구글 계정이 조회 전용 스코프로 연동됨 — 환경설정에서 [구글 계정으로 연동] 재연동 필요'
                : '구글 인증 없음 — 서비스 계정 키 설정 또는 [구글 계정으로 연동] 필요';

            return ['ok' => false, 'message' => "건너뜀({$hint})"];
        }

        $site = rawurlencode(SearchConsoleService::property());
        $feed = rawurlencode(route('sitemap'));
        try {
            $res = Http::timeout(15)->withToken($token)
                ->put("https://searchconsole.googleapis.com/webmasters/v3/sites/{$site}/sitemaps/{$feed}");
        } catch (\Throwable $e) {
            Log::warning('gsc sitemap submit: 전송 실패 — '.$e->getMessage());

            return ['ok' => false, 'message' => '전송 실패('.$e->getMessage().')'];
        }

        if (! $res->successful()) {
            Log::warning('gsc sitemap submit: HTTP '.$res->status().' — '.mb_substr($res->body(), 0, 200));
            $hint = $res->status() === 403 ? ' — 서치 콘솔 속성에 쓰기 권한(소유자 위임) 또는 [구글 계정으로 연동] 재연동 필요' : '';

            return ['ok' => false, 'message' => 'HTTP '.$res->status().$hint];
        }

        return ['ok' => true, 'message' => '재제출 완료'];
    }

    /**
     * 사이트맵 캐시 버전 올림 — 다음 요청부터 새 문서·lastmod 가 반영된다(SitemapRefresh 와 동일 키).
     * increment 는 키가 없으면 0→1 이 되어 기본 버전(1)과 같아 무효 — 현재 버전+1 저장으로 처리.
     */
    private function bumpSitemapVersion(): void
    {
        Cache::forever(SitemapRefresh::VERSION_KEY, SitemapRefresh::version() + 1);
    }

    /**
     * 한글 슬러그 경로를 percent-encoding — IndexNow 는 RFC 준수 URL 을 기대한다.
     * decode 후 재인코딩(멱등) — shareUrl()은 미인코딩, route()는 인코딩이라 섞여 들어온다.
     */
    private function encodeUrl(string $url): string
    {
        $p = parse_url($url);
        if (! is_array($p) || empty($p['host'])) {
            return $url;
        }
        $path = implode('/', array_map(
            fn ($seg) => rawurlencode(rawurldecode($seg)),
            explode('/', $p['path'] ?? '/'),
        ));

        return ($p['scheme'] ?? 'https').'://'.$p['host']
            .(isset($p['port']) ? ':'.$p['port'] : '')
            .$path
            .(isset($p['query']) ? '?'.$p['query'] : '');
    }
}
