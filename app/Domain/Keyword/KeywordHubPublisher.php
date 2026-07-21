<?php

namespace App\Domain\Keyword;

use App\Domain\Shopping\ShopSerpStore;
use App\Models\KeywordCandidate;
use App\Models\KeywordShopSerp;
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
        private ShopSerpStore $shopSerp,
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

        $doc = $this->buildShoppingMarketDoc($c->keyword, $c->category?->name, $c->category_id, $reason);
        if (! $doc) {
            $c->update(['status' => 'rejected', 'note' => $reason.' ('.now()->format('Y-m-d').')']);

            return null;
        }

        $c->update([
            'status' => 'published',
            'note' => null,
            'monthly_total' => (int) ($doc->monthly_search ?: $c->monthly_total),
            'comp_idx' => $doc->comp_idx ?: $c->comp_idx,
        ]);

        return $doc;
    }

    /**
     * 확장 수집 쇼핑 SERP + 검색량으로 시장분석 문서 생성/갱신(시스템 유저 소유) — 발행·백필 공용.
     * 데이터 부족이면 null 을 반환하고 $reason 에 사유를 채운다.
     */
    public function buildShoppingMarketDoc(string $keyword, ?string $categoryName = null, ?int $categoryId = null, ?string &$reason = null): ?MarketAnalysis
    {
        $result = $this->builder->build($keyword);
        $vm = $result['vm'] ?? null;
        if (! $vm || ! ($vm['has_volume'] ?? false)) {
            $reason = '검색량 데이터 부족 — 쇼핑 시장 분석 보류';

            return null;
        }

        $products = $this->shopSerp->items($keyword)->values();
        if ($products->isEmpty()) {
            $reason = '쇼핑 상품 SERP 수집 없음 — 시장 분석 보류';

            return null;
        }

        $meta = KeywordShopSerp::where('keyword', $keyword)->first();
        $prices = $products->pluck('price')->filter(fn ($v) => (int) $v > 0)->map(fn ($v) => (int) $v)->sort()->values();
        $avg = $prices->isNotEmpty() ? (int) round($prices->avg()) : 0;
        $median = $prices->isNotEmpty() ? (int) $prices[(int) floor(($prices->count() - 1) / 2)] : 0;

        $topProducts = $products->take(80)->map(fn ($p) => [
            'title' => (string) $p->title,
            'price' => (int) ($p->price ?? 0),
            'purchase6m' => 0,
            'revenue6m' => 0,
            'mallName' => (string) ($p->mall_name ?? ''),
            'link' => (string) ($p->link ?? ''),
            'rank' => (int) ($p->rnk ?? 0),
            'isAd' => (bool) ($p->is_ad ?? false),
        ])->values()->all();

        $snapshot = [
            'related_tags' => array_values((array) ($meta?->related_tags ?? [])),
            'keyword_data' => [
                'keyword' => $keyword,
                'monthly_total' => (int) ($vm['total'] ?? 0),
                'monthly_pc' => (int) ($vm['pc'] ?? 0),
                'monthly_mobile' => (int) ($vm['mobile'] ?? 0),
                'comp_idx' => $vm['comp_idx'] ?? null,
                'detail' => (array) ($result['detail'] ?? []),
            ],
            'top_products' => $topProducts,
            'top_product_category' => $categoryName,
            'generated_by' => 'keyword_auto_analysis',
            'generated_note' => '확장 수집 쇼핑 SERP 기반 자동 발행. 판매량/매출 데이터가 없는 상품은 0으로 표시됩니다.',
        ];

        $doc = MarketAnalysis::updateOrCreate(
            ['user_id' => $this->systemUserId(), 'keyword' => $keyword],
            [
                'total_count' => (int) ($meta?->total ?? $products->count()),
                'category_id' => $categoryId,
                'item_count' => $products->count(),
                'include_ads' => $products->contains(fn ($p) => (bool) ($p->is_ad ?? false)),
                'sales_6m' => 0,
                'revenue_6m' => 0,
                'avg_price' => $avg,
                'median_price' => $median,
                'top10_share' => 0,
                'monthly_search' => (int) ($vm['total'] ?? 0),
                'comp_idx' => $vm['comp_idx'] ?? null,
                'snapshot' => $snapshot,
            ],
        );

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
