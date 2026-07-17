<?php

namespace App\Domain\Keyword;

use App\Models\KeywordCandidate;
use Illuminate\Support\Collection;

/**
 * 후보 키워드 검색량 자동 갱신 — 관리자 키워드 탐색이 화면에 뜬 키워드를 주 1회 주기로 갱신한다.
 * keywordstool 이 한 번에 5개까지 받으므로 5개씩 묶어 조회한다(1건씩 대비 5배).
 * 화면 응답이 느려지지 않게 한 번에 갱신하는 건수를 제한한다(나머지는 다음 조회 때).
 */
class KeywordVolumeRefresher
{
    /** 갱신 주기(일) — "조회수는 일주일에 한번 업데이트" */
    public const TTL_DAYS = 7;

    /** 한 화면에서 갱신할 최대 건수(5의 배수 — API 호출 = 이 값 / 5) */
    public const MAX_PER_VIEW = 50;

    public function __construct(private NaverKeywordService $keywords) {}

    /**
     * 주어진 후보들 중 갱신 대상(한 번도 조회 안 했거나 7일 경과)을 골라 검색량을 채운다.
     * 갱신한 값은 전달된 모델 인스턴스에도 반영해 그대로 화면에 뿌릴 수 있다.
     *
     * @param  Collection<int, KeywordCandidate>  $candidates
     * @return int 갱신 건수
     */
    public function refresh(Collection $candidates): int
    {
        $due = $candidates->filter(fn ($c) => $this->isDue($c))->take(self::MAX_PER_VIEW);
        if ($due->isEmpty()) {
            return 0;
        }

        $vols = $this->keywords->volumes($due->pluck('keyword')->all());
        if (! $vols) {
            return 0;
        }

        $now = now();
        $n = 0;
        foreach ($due as $c) {
            $v = $vols[$c->keyword] ?? null;
            if (! $v) {
                continue;
            }
            // 조회는 됐는데 값이 없으면(검색량 0) 0으로 확정 — 매번 재조회하지 않도록 시각은 갱신
            KeywordCandidate::whereKey($c->id)->update([
                'monthly_total' => $v['monthly_total'],
                'comp_idx' => $v['comp_idx'],
                'volume_checked_at' => $now,
            ]);
            $c->monthly_total = $v['monthly_total'];
            $c->comp_idx = $v['comp_idx'];
            $c->volume_checked_at = $now;
            $n++;
        }

        return $n;
    }

    /** 갱신 대상인가 — 한 번도 조회 안 했거나 TTL 경과. */
    public function isDue(KeywordCandidate $c): bool
    {
        return $c->volume_checked_at === null
            || $c->volume_checked_at->lt(now()->subDays(self::TTL_DAYS));
    }
}
