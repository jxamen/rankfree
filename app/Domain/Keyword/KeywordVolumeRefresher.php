<?php

namespace App\Domain\Keyword;

use App\Models\Keyword;
use App\Models\KeywordCandidate;
use App\Models\KeywordCategory;
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

    /**
     * 한 화면에서 갱신할 최대 건수(5의 배수 — API 호출 = 이 값 / 5).
     * 50건이면 첫 조회가 3.1초 걸려 "검색이 안 된다"고 느껴진다(실측) → 15건(API 3회, 약 1초)으로 낮춘다.
     * 나머지는 페이지를 넘기거나 다시 볼 때 이어서 채워진다.
     */
    public const MAX_PER_VIEW = 15;

    public function __construct(private NaverKeywordService $keywords) {}

    /**
     * 주어진 키워드들 중 갱신 대상(한 번도 조회 안 했거나 7일 경과)을 골라 검색량을 채운다.
     * 갱신한 값은 전달된 모델 인스턴스에도 반영해 그대로 화면에 뿌릴 수 있다.
     *
     * 후보(KeywordCandidate)와 키워드 마스터(Keyword) 둘 다 받는다 — 목록 화면은 마스터를 읽는다.
     * 어느 쪽을 받든 같은 키워드의 후보 전부 + 마스터를 함께 갱신한다(둘이 어긋나지 않게).
     *
     * @param  Collection<int, KeywordCandidate|Keyword>  $rows
     * @return int 갱신 건수
     */
    public function refresh(Collection $rows): int
    {
        $due = $rows->filter(fn ($c) => $this->isDue($c))->take(self::MAX_PER_VIEW);
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
            $vals = [
                'monthly_total' => $v['monthly_total'],
                'comp_idx' => $v['comp_idx'],
                'volume_checked_at' => $now,
            ];

            if ($c instanceof Keyword) {
                // 마스터에서 왔으면 그 키워드의 후보 전부(여러 분류)를 함께 맞춘다
                $catIds = KeywordCategory::where('type', $c->type)->pluck('id');
                KeywordCandidate::where('keyword', $c->keyword)->whereIn('category_id', $catIds)->update($vals);
                Keyword::whereKey($c->id)->update($vals);
            } else {
                KeywordCandidate::whereKey($c->id)->update($vals);
                Keyword::where('keyword', $c->keyword)
                    ->where('type', $c->category?->type ?? '')->update($vals);
            }

            $c->monthly_total = $v['monthly_total'];
            $c->comp_idx = $v['comp_idx'];
            $c->volume_checked_at = $now;
            $n++;
        }

        return $n;
    }

    /** 갱신 대상인가 — 한 번도 조회 안 했거나 TTL 경과. */
    public function isDue(KeywordCandidate|Keyword $c): bool
    {
        return $c->volume_checked_at === null
            || $c->volume_checked_at->lt(now()->subDays(self::TTL_DAYS));
    }
}
