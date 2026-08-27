<?php

namespace App\Domain\Place;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * 유입 키워드 추천(2026-08-27) — 플레이스명·주소(지역)·업종을 섞어 후보를 만들고,
 * **네이버 통합검색 플레이스 영역에 그 업체가 실제로 뜨는 키워드만** 순위와 함께 골라낸다.
 *
 * 부스팅샵 유입 미션은 참여자가 검색창에 키워드를 넣고 화면에서 업체를 찾아 들어가는 방식이라,
 * 검색해도 안 나오는 키워드를 넣으면 미션이 성립하지 않는다.
 * 판정 기준(사용자 확정): **더보기를 눌러서 나오는 것까지 인정**, 상위 [RANK_LIMIT] 위까지 채택.
 *
 * ⚠️ 판정 방식 변천 — 앞의 둘은 틀려서 버렸다(같은 날 실측):
 *  1. 통합검색 HTML 에 placeId 문자열이 있는지 → **거짓 양성**. 블로그 썸네일 데이터가
 *     `"gdid":"blog_…","sid":"<placeId>"` 로 같은 숫자를 실어, 화면에 안 보이는 키워드도 노출로 잡혔다.
 *  2. [PlaceRankChecker](PlaceRankChecker.php) 의 pcmap 순위 → **화면과 다르다**. 좌표를 업체 위치로 바꿔도
 *     같은 값이 나왔고(서울/부산 모두 8위), 정작 화면에선 6번째였다. pcmap 8~18위여도 화면엔 아예 없는 키워드가 많다.
 *  3. (현재) **브라우저로 실제 화면을 렌더링**해 플레이스 카드 순서를 센다 — HTML(curl)에는 첫 5개만 있고
 *     "펼쳐서 더보기" 로 나오는 나머지는 JS 로 그려지므로 브라우저가 필요하다. [scripts/place-serp-rank.cjs](../../../scripts/place-serp-rank.cjs)
 */
class PlaceKeywordSuggester
{
    /** 후보 검사 상한 — 키워드마다 브라우저로 검색하므로 무한정 늘리지 않는다. */
    private const MAX_CANDIDATES = 12;

    /** 채택 기준 순위(사용자 확정 2026-08-27) — 더보기까지 인정하되 이보다 뒤는 참여자가 못 찾는다. */
    private const RANK_LIMIT = 20;

    /**
     * 프로필 → 후보 생성 → 통합검색 플레이스 영역 노출 순위 확인.
     *
     * @param  array{place_id:string, name:string, category:string, address:string, ...}  $profile
     * @param  list<string>  $extra  기존 주문 키워드 등 함께 확인할 키워드
     * @return array{checked:int, failed:bool, exposed:list<array{keyword:string,rank:int}>, missed:list<string>}
     */
    public function suggest(array $profile, array $extra = []): array
    {
        $pid = preg_replace('/\D/', '', (string) ($profile['place_id'] ?? ''));
        $candidates = array_slice(
            $this->dedupe(array_merge($extra, $this->candidates($profile))),
            0,
            self::MAX_CANDIDATES,
        );
        if ($pid === '' || ! $candidates) {
            return ['checked' => 0, 'failed' => false, 'exposed' => [], 'missed' => []];
        }

        // 캐시에 있는 건 재사용하고, 남은 것만 브라우저로 한 번에 수집한다(브라우저 기동 1회)
        $ranks = [];
        $todo = [];
        foreach ($candidates as $kw) {
            $hit = Cache::get($this->cacheKey($pid, $kw));
            if ($hit === null) {
                $todo[] = $kw;
            } else {
                $ranks[$kw] = (int) $hit;
            }
        }

        $failed = false;
        if ($todo) {
            $collected = $this->collect($pid, $todo);
            if ($collected === null) {
                $failed = true;
            } else {
                foreach ($collected as $kw => $rank) {
                    $ranks[$kw] = $rank;
                    Cache::put($this->cacheKey($pid, $kw), $rank, now()->addHours(6));
                }
            }
        }

        $exposed = $missed = [];
        foreach ($candidates as $kw) {
            $rank = $ranks[$kw] ?? 0;
            if ($rank > 0 && $rank <= self::RANK_LIMIT) {
                $exposed[] = ['keyword' => $kw, 'rank' => $rank];
            } elseif (isset($ranks[$kw])) {
                $missed[] = $kw;
            }
        }
        usort($exposed, fn ($a, $b) => $a['rank'] <=> $b['rank']);   // 위에 뜨는 키워드부터

        return [
            'checked' => count($exposed) + count($missed),
            'failed' => $failed,
            'exposed' => $exposed,
            'missed' => $missed,
        ];
    }

    /**
     * 브라우저로 키워드들의 플레이스 영역 노출 순위를 한 번에 수집. 실패하면 null.
     *
     * @param  list<string>  $keywords
     * @return array<string, int>|null  키워드 => 순위(0=안 나옴)
     */
    public function collect(string $placeId, array $keywords): ?array
    {
        // 공용 서버라 브라우저는 동시 1개만 — 쇼핑 수집기와 같은 원칙
        $lock = Cache::lock('place:serp:browser', 300);
        if (! $lock->get()) {
            Log::warning('플레이스 노출순위 수집: 브라우저 사용 중(락 획득 실패)');

            return null;
        }

        try {
            $cfg = (array) config('rankfree.shopping.server_collect', []);
            $cmd = [
                (string) ($cfg['node'] ?? 'node'),
                base_path('scripts/place-serp-rank.cjs'),
                '--pid='.$placeId,
                '--keywords='.implode('|', $keywords),
                '--expand=4',
            ];
            // 배열로 넘겨 OS 별 이스케이프는 Symfony Process 에 맡긴다(윈도우에서 escapeshellarg 로 만든 문자열은 실행 실패)
            // 임시 디렉터리·홈 경로를 넘겨야 한다 — 없으면 Playwright 가 프로필 폴더를 못 만들고 죽는다
            $t0 = microtime(true);
            $tmp = sys_get_temp_dir();
            $res = Process::path(base_path())
                ->env(array_filter([
                    'TEMP' => $tmp, 'TMP' => $tmp, 'TMPDIR' => $tmp,
                    'PATH' => (string) (getenv('PATH') ?: getenv('Path') ?: ''),
                    'HOME' => (string) (getenv('HOME') ?: ''),
                    // 윈도우는 SystemRoot 가 없으면 크롬이 **DNS 를 못 푼다**(ERR_NAME_NOT_RESOLVED)
                    'USERPROFILE' => (string) (getenv('USERPROFILE') ?: ''),
                    'SystemRoot' => (string) (getenv('SystemRoot') ?: ''),
                    'SystemDrive' => (string) (getenv('SystemDrive') ?: ''),
                    'windir' => (string) (getenv('windir') ?: ''),
                ], fn ($v) => $v !== ''))
                ->timeout(20 + 15 * count($keywords))->run($cmd);
            foreach (array_reverse(explode("\n", trim($res->output()))) as $l) {
                $l = trim($l);
                if (! str_starts_with($l, '{') || ! str_contains($l, '"ok"')) {
                    continue;
                }
                $json = json_decode($l, true);
                if (! is_array($json) || ! ($json['ok'] ?? false)) {
                    Log::warning('플레이스 노출순위 수집 실패', ['out' => mb_substr($l, 0, 300)]);

                    return null;
                }

                $out = [];
                $errors = [];
                foreach ((array) ($json['results'] ?? []) as $r) {
                    // 오류가 난 키워드는 '순위 없음' 이 아니다 — 캐시에 넣지 않고 다음 기회에 다시 본다
                    if (! empty($r['error'])) {
                        $errors[(string) ($r['keyword'] ?? '')] = (string) $r['error'];

                        continue;
                    }
                    $out[(string) ($r['keyword'] ?? '')] = (int) ($r['rank'] ?? 0);
                }
                if ($errors) {
                    Log::warning('플레이스 노출순위 수집: 일부 키워드 오류', ['errors' => array_slice($errors, 0, 3, true)]);
                }
                if (! $out) {
                    return null;   // 전부 실패면 수집 실패로 본다
                }
                Log::info('플레이스 노출순위 수집 완료', ['n' => count($out), 'ranks' => $out, 'sec' => round(microtime(true) - $t0, 1)]);

                return $out;
            }

            Log::warning('플레이스 노출순위 수집: JSON 출력 없음', ['exit' => $res->exitCode(), 'out' => mb_substr(trim($res->output()), 0, 200), 'err' => mb_substr(trim($res->errorOutput()), 0, 300)]);

            return null;
        } catch (Throwable $e) {
            Log::warning('플레이스 노출순위 수집 오류', ['e' => $e->getMessage()]);

            return null;
        } finally {
            $lock->release();
        }
    }

    /**
     * 상호명 · 지역(시/구/동) · 업종을 섞은 키워드 후보.
     *
     * @param  array{name:string, category:string, address:string, ...}  $profile
     * @return list<string>
     */
    public function candidates(array $profile): array
    {
        $name = trim((string) ($profile['name'] ?? ''));
        $cat = trim((string) ($profile['category'] ?? ''));
        [$si, $gu, $dong] = $this->regionTokens((string) ($profile['address'] ?? ''));
        $dongShort = $this->trimSuffix($dong, ['동', '읍', '면', '리']);   // 초량동 → 초량 (검색은 보통 이 형태)

        $out = [];
        $add = function (string ...$parts) use (&$out) {
            $kw = trim(implode('', array_map('trim', array_filter($parts))));
            if ($kw !== '' && mb_strlen($kw) <= 25) {
                $out[] = $kw;
            }
        };

        // 상호명 기반 — 플레이스 영역에 뜰 확률이 가장 높다(브랜드 검색)
        if ($name !== '') {
            $add($name);
            $add($dongShort, $name);
            $add($si, $name);
        }
        // 업종 기반 — 지역을 좁은 곳부터 넓은 곳까지
        if ($cat !== '') {
            $add($dongShort, $cat);
            $add($dong, $cat);
            $add($gu, $cat);
            $add($si, $gu, $cat);
            $add($si, $cat);
            $add($dongShort, $cat, '추천');
            $add($dongShort, $cat, '잘하는곳');
            $add($gu, $cat, '추천');
        }

        return $this->dedupe($out);
    }

    /**
     * 지번 주소 → [시/도, 시군구, 동] (예: "부산 동구 초량동 42-13" → ["부산", "동구", "초량동"]).
     *
     * @return array{0:string,1:string,2:string}
     */
    public function regionTokens(string $address): array
    {
        $parts = preg_split('/\s+/', trim($address)) ?: [];
        $si = $parts[0] ?? '';
        $gu = $parts[1] ?? '';
        $dong = $parts[2] ?? '';
        // "경기 성남시 분당구 정자동" 처럼 4토막이면 시군구가 둘로 쪼개져 있다 — 뒤쪽(구)을 쓴다
        if (isset($parts[3]) && preg_match('/(구|군)$/u', $dong)) {
            $gu = $dong;
            $dong = $parts[3];
        }
        // 번지만 남은 칸은 지역명이 아니다
        if ($dong !== '' && preg_match('/^\d/', $dong)) {
            $dong = '';
        }

        return [$si, $gu, $dong];
    }

    private function cacheKey(string $placeId, string $keyword): string
    {
        return 'place:serprank:v2:'.$placeId.':'.md5($keyword);
    }

    /** @param list<string> $suffixes */
    private function trimSuffix(string $token, array $suffixes): string
    {
        foreach ($suffixes as $s) {
            if ($token !== $s && mb_strlen($token) > 1 && str_ends_with($token, $s)) {
                return mb_substr($token, 0, mb_strlen($token) - mb_strlen($s));
            }
        }

        return $token;
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    private function dedupe(array $items): array
    {
        return array_values(array_unique(array_filter(array_map('trim', $items))));
    }
}
