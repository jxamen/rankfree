<?php

namespace App\Domain\Keyword;

use App\Models\KeywordCandidate;
use App\Models\KeywordSearch;

/**
 * 키워드 콘텐츠 허브 — 발행·갱신(22_KEYWORD_CONTENT_HUB Phase 1).
 * 승인 후보를 KeywordReportBuilder 로 분석해 KeywordSearch(origin=hub, 시스템 소유) 문서로 발행한다.
 * thin content 방지: 검색량(has_volume) 없으면 발행하지 않고 후보를 rejected 처리한다.
 */
class KeywordHubPublisher
{
    public function __construct(
        private KeywordReportBuilder $builder,
        private KeywordAiInsight $ai,
        private PlaceKeywordRegions $regions,
    ) {}

    /** 발행 — 성공 시 허브 문서 반환, 데이터 부족이면 null(후보는 rejected + 사유). */
    public function publish(KeywordCandidate $c): ?KeywordSearch
    {
        $result = $this->builder->build($c->keyword);
        $vm = $result['vm'] ?? null;
        if (! $vm || ! ($vm['has_volume'] ?? false)) {
            $c->update(['status' => 'rejected', 'note' => '데이터 부족 — 발행 보류 ('.now()->format('Y-m-d').')']);

            return null;
        }

        // AI 인사이트(선택 보강) — 발행 시점 1회 생성해 스냅샷에 저장, 열람 시 재호출 없음
        if ($insight = $this->ai->write($vm)) {
            $result['ai_insight'] = $insight;
        }

        $place = $this->placeRegion($c);

        $doc = KeywordSearch::updateOrCreate(
            ['origin' => 'hub', 'keyword' => $c->keyword],
            [
                'user_id' => null,
                'category_id' => $c->category_id,
                'region' => $place['region'],           // 플레이스 지역 축(쇼핑은 null)
                'region_type' => $place['region_type'],
                'monthly_total' => $vm['total'],
                'monthly_pc' => $vm['pc'],
                'monthly_mobile' => $vm['mobile'],
                'comp_idx' => $vm['comp_idx'],
                'grade' => $vm['grade'],
                'snapshot' => $result,
                'refreshed_at' => now(),
            ],
        );
        $c->update(['status' => 'published', 'note' => null] + array_filter($place, fn ($v) => $v !== null));

        return $doc;
    }

    /**
     * 후보의 지역(플레이스 2번째 분류 축). region 컬럼 도입 전 후보는 비어 있으므로
     * 키워드에서 되짚어 채운다 — 안 그러면 카테고리 허브 지역 배지 수가 실제보다 적게 나온다.
     *
     * @return array{region: ?string, region_type: ?string}
     */
    private function placeRegion(KeywordCandidate $c): array
    {
        if ($c->region !== null) {
            return ['region' => $c->region, 'region_type' => $c->region_type];
        }
        $cat = $c->category ?? $c->category()->first();
        if (! $cat || $cat->type !== 'place') {
            return ['region' => null, 'region_type' => null];
        }

        return $this->regions->resolve((string) $c->keyword, (string) $cat->name)
            ?? ['region' => null, 'region_type' => null];
    }

    /** 발행 문서 갱신(hub:refresh) — 볼륨이 안 나오면 기존 스냅샷 유지, 커서만 전진(재시도 폭주 방지). */
    public function refresh(KeywordSearch $doc): bool
    {
        $result = $this->builder->build($doc->keyword);
        $vm = $result['vm'] ?? null;
        if (! $vm || ! ($vm['has_volume'] ?? false)) {
            $doc->forceFill(['refreshed_at' => now()])->save();

            return false;
        }

        if ($insight = $this->ai->write($vm)) {
            $result['ai_insight'] = $insight;
        }

        $doc->forceFill([
            'monthly_total' => $vm['total'],
            'monthly_pc' => $vm['pc'],
            'monthly_mobile' => $vm['mobile'],
            'comp_idx' => $vm['comp_idx'],
            'grade' => $vm['grade'],
            'snapshot' => $result,
            'refreshed_at' => now(),
        ])->save();

        return true;
    }
}
