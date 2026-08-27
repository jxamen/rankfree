<?php

namespace App\Domain\Place;

use Illuminate\Support\Facades\Cache;

/**
 * 플레이스 프로필 조회(2026-08-27) — placeId → 상호명·업종·주소·전화.
 * m.place SSR HTML(__APOLLO_STATE__) 1회 조회로 얻는다. nCaptcha 불필요.
 *
 * [PlaceRankChecker::placeSummary()](PlaceRankChecker.php) 는 순위조회에 필요한 이름·카테고리 경로키만 주므로,
 * 부스팅샵 주문 자동 입력(상호명·전화)과 유입 키워드 추천(지역·업종)에 필요한 값을 여기서 따로 뽑는다.
 */
class PlaceProfileFetcher
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36';

    /**
     * @return array{place_id:string, name:string, category:string, address:string, road_address:string, phone:string}
     */
    public function fetch(string $placeId): array
    {
        $pid = preg_replace('/\D/', '', $placeId);
        $empty = ['place_id' => (string) $pid, 'name' => '', 'category' => '', 'address' => '', 'road_address' => '', 'phone' => ''];
        if ($pid === '') {
            return $empty;
        }

        // 업체 정보는 자주 바뀌지 않는다 — 같은 주문을 여러 번 열어도 네이버를 다시 때리지 않게 하루 캐시
        return Cache::remember("place:profile:{$pid}", now()->addDay(), function () use ($pid, $empty) {
            $html = $this->get("https://m.place.naver.com/place/{$pid}/home");
            if ($html === '') {
                return $empty;
            }

            $pick = function (string $key) use ($html): string {
                return preg_match('/"'.$key.'"\s*:\s*"([^"]{1,120})"/', $html, $m)
                    ? trim(html_entity_decode(stripslashes($m[1]), ENT_QUOTES, 'UTF-8'))
                    : '';
            };

            $name = $pick('name');
            if ($name === '' && preg_match('#<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)#i', $html, $m)) {
                $name = trim($m[1]);
            }

            return [
                'place_id' => $pid,
                'name' => $name,
                'category' => $pick('category'),          // 한글 업종(예: 미용실·헬스장)
                'address' => $pick('address'),             // 지번 주소 — 지역 토큰(시·구·동) 추출용
                'road_address' => $pick('roadAddress'),
                'phone' => $pick('virtualPhone') ?: $pick('phone'),
            ];
        });
    }

    /** 플레이스 URL 에서 placeId 추출 — /place/{id} · /hairshop/{id} 등 업종 경로 모두 지원. */
    public static function placeIdFromUrl(string $url): string
    {
        return preg_match('#/(\d{6,})#', $url, $m) ? $m[1] : '';
    }

    private function get(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => (int) config('rankfree.place.timeout', 20),
            CURLOPT_HTTPHEADER => [
                'accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'accept-language: ko-KR,ko;q=0.9',
                'user-agent: '.self::UA,
            ],
        ]);
        $html = (string) curl_exec($ch);
        curl_close($ch);

        return $html;
    }
}
