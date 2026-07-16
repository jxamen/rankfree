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

        $doc = KeywordSearch::updateOrCreate(
            ['origin' => 'hub', 'keyword' => $c->keyword],
            [
                'user_id' => null,
                'category_id' => $c->category_id,
                'region' => $c->region,           // 플레이스 지역 축(쇼핑은 null)
                'region_type' => $c->region_type,
                'monthly_total' => $vm['total'],
                'monthly_pc' => $vm['pc'],
                'monthly_mobile' => $vm['mobile'],
                'comp_idx' => $vm['comp_idx'],
                'grade' => $vm['grade'],
                'snapshot' => $result,
                'refreshed_at' => now(),
            ],
        );
        $c->update(['status' => 'published', 'note' => null]);

        return $doc;
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
