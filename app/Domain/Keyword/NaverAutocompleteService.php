<?php

namespace App\Domain\Keyword;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 네이버 검색 자동완성(ac.search.naver.com/nx/ac) — 입력 키워드의 자동완성 제안어.
 * 인증·쿠키 불필요(공개 엔드포인트). r_format=json 으로 순수 JSON 응답. 1시간 캐시.
 */
class NaverAutocompleteService
{
    /**
     * 자동완성 제안어 목록(입력어 자신은 제외, 최대 $limit).
     *
     * @return list<string>
     */
    public function suggest(string $keyword, int $limit = 10): array
    {
        $kw = trim($keyword);
        if ($kw === '') {
            return [];
        }

        return Cache::remember('kw:ac:'.md5(mb_strtoupper(str_replace(' ', '', $kw))).':'.$limit, now()->addHour(), function () use ($kw, $limit) {
            try {
                $r = Http::withHeaders([
                    'accept' => '*/*',
                    'referer' => 'https://www.naver.com/',
                    'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36',
                ])->timeout(10)->get('https://ac.search.naver.com/nx/ac', [
                    'q' => $kw, 'con' => 1, 'frm' => 'nv', 'ans' => 2, 'r_format' => 'json',
                    'r_enc' => 'UTF-8', 'r_unicode' => 0, 't_koreng' => 1, 'run' => 2, 'rev' => 4, 'q_enc' => 'UTF-8', 'st' => 100,
                ]);

                if (! $r->ok()) {
                    return [];
                }

                $norm = fn ($s) => mb_strtolower(str_replace(' ', '', (string) $s));
                $self = $norm($kw);
                $out = [];
                foreach ((array) $r->json('items.0', []) as $it) {
                    $s = trim((string) (is_array($it) ? ($it[0] ?? '') : $it));
                    if ($s === '' || $norm($s) === $self || in_array($s, $out, true)) {
                        continue;
                    }
                    $out[] = $s;
                    if (count($out) >= $limit) {
                        break;
                    }
                }

                return $out;
            } catch (Throwable) {
                return [];
            }
        });
    }
}
