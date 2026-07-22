<?php

namespace App\Domain\Keyword;

use App\Models\KeywordCandidate;
use App\Models\KeywordSearch;
use App\Models\MarketAnalysis;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * 키워드 자동 분석 — 후보를 공개 분석 문서로 발행한다.
 * 플레이스는 키워드 분석(/keyword), 쇼핑은 쇼핑 시장 분석(/market)으로 분기한다.
 */
class KeywordHubPublisher
{
    public function __construct(
        private KeywordReportBuilder $builder,
        private KeywordAiInsight $ai,
        private PlaceKeywordRegions $regions,
    ) {}

    /** 발행 — 성공 시 공개 문서 반환, 데이터 부족이면 null(후보는 rejected + 사유). */
    public function publish(KeywordCandidate $c): ?Model
    {
        $c->loadMissing('category');

        return $c->category?->type === 'shopping'
            ? $this->publishShoppingMarket($c)
            : $this->publishPlaceKeyword($c);
    }

    private function publishPlaceKeyword(KeywordCandidate $c): ?KeywordSearch
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

        // 폐기 문서도 찾아 in-place 갱신(스코프 우회) — 중복 문서 생성 방지. retired_at 은 payload 에 없어 유지된다.
        $doc = KeywordSearch::withoutGlobalScope('notRetired')->updateOrCreate(
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

    private function publishShoppingMarket(KeywordCandidate $c): ?MarketAnalysis
    {
        if ($source = $this->latestMarketSource($c->keyword)) {
            $doc = MarketAnalysis::updateOrCreate(
                ['user_id' => $this->systemUserId(), 'keyword' => $c->keyword],
                $this->marketPayloadFromSource($source, $c),
            );
            $c->update([
                'status' => 'published',
                'note' => null,
                'monthly_total' => $doc->monthly_search ?: $c->monthly_total,
                'comp_idx' => $doc->comp_idx ?: $c->comp_idx,
            ]);

            return $doc;
        }

        // 소스(사용자 수집분)는 없어도 **시스템 발행본이 이미 있으면** 그 키워드는 발행 완료 상태다 —
        // 같은 키워드가 여러 카테고리에 후보로 존재해 중복 후보가 '확장 수집 데이터 필요'로 오거부되던
        // 실사고(시간당 60건, 발행 대상 선별과 판정 불일치) 수정. 후보만 published 로 정리한다.
        $existing = MarketAnalysis::where('user_id', $this->systemUserId())
            ->where('keyword', $c->keyword)->first();
        if ($existing) {
            $c->update(['status' => 'published', 'note' => null]);

            return $existing;
        }

        // 쇼핑 시장 분석은 **확장 플로 수집 데이터로만** 만든다(사용자 확정 2026-07-22) —
        // 서버 SERP 기반 자동 생성은 판매량·매출·차트가 빠진 껍데기 문서라 발행하지 않는다.
        $c->update(['status' => 'rejected', 'note' => '쇼핑 시장 분석은 확장 수집 데이터 필요 — 보류 ('.now()->format('Y-m-d').')']);

        return null;
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

    private function latestMarketSource(string $keyword): ?MarketAnalysis
    {
        return MarketAnalysis::where('keyword', $keyword)
            ->where('user_id', '!=', $this->systemUserId())
            ->orderByDesc('sales_6m')
            ->orderByDesc('id')
            ->first();
    }

    private function marketPayloadFromSource(MarketAnalysis $source, KeywordCandidate $candidate): array
    {
        $snapshot = (array) $source->snapshot;
        $payload = Arr::only($source->getAttributes(), [
            'total_count', 'item_count', 'include_ads', 'sales_6m', 'revenue_6m',
            'avg_price', 'median_price', 'top10_share', 'monthly_search', 'comp_idx',
        ]);
        $payload['category_id'] = $candidate->category_id;
        $payload['snapshot'] = array_merge($snapshot, [
            'generated_by' => 'keyword_auto_analysis',
            'source_market_analysis_id' => $source->id,
            'top_product_category' => $snapshot['top_product_category'] ?? $candidate->category?->name,
        ]);

        return $payload;
    }

    private function systemUserId(): int
    {
        $email = (string) config('rankfree.hub.system_user_email', 'hub-system@rankfree.kr');
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => '키워드 자동 분석',
                'password' => Str::random(40),
                'role' => 'super',
            ],
        );

        return (int) $user->id;
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
