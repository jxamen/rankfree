<?php

namespace App\Domain\Shopping;

use Illuminate\Support\Facades\Cache;

/**
 * 쇼핑 노출 순위 수집 IP별 잠금(2026-09-29 대표님 지시) — 같은 IP 에서 두 곳이 동시에 m.search 를 돌면
 * 네이버가 바로 403 으로 막는다. 네이버 차단은 IP 단위라 잠금도 IP 단위다(계정별이면 같은 사무실의 두 계정은
 * 여전히 부딪히고, 한 계정이 다른 장소에서 돌리는 건 괜히 막힌다). 다른 IP 끼리는 동시에 돌아도 된다.
 *
 * scope: 브라우저 화면·확장 = 'ip:<요청 IP>'(수집하는 브라우저의 IP), 서버 배치 = 'server'(서버 IP 하나).
 * 수집이 진행되는 동안 매 요청이 갱신하고, TTL(90초) 동안 갱신이 없으면 저절로 풀린다(창 닫힘·보안문자 대기).
 * 캐시 값은 순수 배열만(운영 database 캐시에 객체 저장 금지).
 */
class ShopExposureRunLock
{
    private const KEY = 'shop-exposure:runner:';

    public const TTL = 90;

    /**
     * 잠금 획득·갱신. 비어 있거나 같은 주인이면 성공, 같은 scope 에서 다른 주인이 들고 있으면 실패 + 주인 정보.
     *
     * @return array{ok: bool, holder: ?array{owner: string, analysis_id: int, keyword: string, at: int}}
     */
    public function acquire(string $scope, string $owner, int $analysisId, string $keyword = ''): array
    {
        $key = self::KEY.$scope;

        return Cache::lock($key.':mutex', 5)->block(3, function () use ($key, $owner, $analysisId, $keyword) {
            $cur = Cache::get($key);
            if (is_array($cur) && ($cur['owner'] ?? '') !== $owner) {
                return ['ok' => false, 'holder' => $cur];
            }
            Cache::put($key, ['owner' => $owner, 'analysis_id' => $analysisId, 'keyword' => $keyword, 'at' => time()], self::TTL);

            return ['ok' => true, 'holder' => null];
        });
    }

    /** 이 scope 에서 이 분석을 돌리던 잠금을 푼다(중단·완료). */
    public function release(string $scope, int $analysisId): void
    {
        $key = self::KEY.$scope;
        Cache::lock($key.':mutex', 5)->block(3, function () use ($key, $analysisId) {
            $cur = Cache::get($key);
            if (is_array($cur) && (int) ($cur['analysis_id'] ?? 0) === $analysisId) {
                Cache::forget($key);
            }
        });
    }

    /** 화면에 띄울 안내 — 같은 IP 에서 다른 수집이 도는 분석. */
    public function busyMessage(array $holder): string
    {
        $kw = trim((string) ($holder['keyword'] ?? ''));

        return '같은 인터넷 주소(IP)에서 다른 수집이 진행 중이에요 — 분석 #'.(int) ($holder['analysis_id'] ?? 0).($kw !== '' ? " ({$kw})" : '')
            .'. 동시에 돌면 네이버가 막아요. 끝나면 자동으로 이어서 시작합니다.';
    }
}
