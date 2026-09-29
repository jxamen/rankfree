<?php

namespace App\Domain\Shopping;

use Illuminate\Support\Facades\Cache;

/**
 * 쇼핑 노출 순위 수집 전역 잠금(2026-09-29 대표님 지시) — 사무실이 같은 IP 라 두 곳이 동시에 m.search 를
 * 돌면 네이버가 바로 403 으로 막는다. 화면 루프·서버 배치·확장 백그라운드 큐 어디서 시작하든 전체에서 하나만 돈다.
 *
 * 수집이 진행되는 동안 매 요청이 갱신하고, TTL(90초) 동안 갱신이 없으면 저절로 풀린다(창 닫힘·보안문자 대기).
 * 캐시 값은 순수 배열만(운영 database 캐시에 객체 저장 금지).
 */
class ShopExposureRunLock
{
    private const KEY = 'shop-exposure:runner';

    public const TTL = 90;

    /**
     * 잠금 획득·갱신. 비어 있거나 같은 주인이면 성공, 다른 주인이 들고 있으면 실패 + 주인 정보.
     *
     * @return array{ok: bool, holder: ?array{owner: string, analysis_id: int, keyword: string, at: int}}
     */
    public function acquire(string $owner, int $analysisId, string $keyword = ''): array
    {
        return Cache::lock(self::KEY.':mutex', 5)->block(3, function () use ($owner, $analysisId, $keyword) {
            $cur = Cache::get(self::KEY);
            if (is_array($cur) && ($cur['owner'] ?? '') !== $owner) {
                return ['ok' => false, 'holder' => $cur];
            }
            Cache::put(self::KEY, ['owner' => $owner, 'analysis_id' => $analysisId, 'keyword' => $keyword, 'at' => time()], self::TTL);

            return ['ok' => true, 'holder' => null];
        });
    }

    /** 주인(또는 이 분석을 돌리던 주인)일 때만 푼다. */
    public function release(?string $owner = null, ?int $analysisId = null): void
    {
        Cache::lock(self::KEY.':mutex', 5)->block(3, function () use ($owner, $analysisId) {
            $cur = Cache::get(self::KEY);
            if (! is_array($cur)) {
                return;
            }
            if (($owner !== null && ($cur['owner'] ?? '') === $owner)
                || ($analysisId !== null && (int) ($cur['analysis_id'] ?? 0) === $analysisId)) {
                Cache::forget(self::KEY);
            }
        });
    }

    /** 화면에 띄울 안내 — 다른 수집이 도는 분석. */
    public function busyMessage(array $holder): string
    {
        $kw = trim((string) ($holder['keyword'] ?? ''));

        return '다른 수집이 진행 중이에요 — 분석 #'.(int) ($holder['analysis_id'] ?? 0).($kw !== '' ? " ({$kw})" : '')
            .'. 같은 인터넷 주소라 동시에 돌면 네이버가 막아요. 끝나면 자동으로 이어서 시작합니다.';
    }
}
