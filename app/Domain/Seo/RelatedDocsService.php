<?php

namespace App\Domain\Seo;

use App\Models\KeywordSearch;
use App\Models\MarketAnalysis;
use App\Models\PlaceStoreAnalysis;
use App\Models\ProductAnalysis;
use App\Models\SellerPowerAnalysis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * 관련 문서 추천 — 공개 분석 리포트 5종(키워드·시장·상품리뷰·셀러력·매장) 사이의 내부 링크 그물.
 * 문서 하단 "함께 보면 좋은 분석" 섹션을 구성한다(아고다식 크로스 추천 — 22_KEYWORD_CONTENT_HUB.md).
 *
 * ⚠️ 추적 슬롯(/place /compete /shopping — PlaceRankSlot·ShopRankSlot)은 사용자가 추적 중인
 *    업체/상품이므로 어떤 경우에도 추천 소스로 쓰지 않는다(21_SEO_SLUG_SITEMAP.md 비공개 원칙).
 */
class RelatedDocsService
{
    private const TTL = 21600; // 6h — 문서별 추천 캐시

    private const SELF_LIMIT = 6;   // 같은 타입 섹션 최대 개수

    private const CROSS_LIMIT = 3;  // 다른 타입 섹션 최대 개수

    /** 타입별 정의 — prefix => [모델, 매칭 컬럼, 섹션 제목, 매칭 없을 때 제목] */
    private const TYPES = [
        'keyword' => [KeywordSearch::class, ['keyword'], '함께 보면 좋은 키워드 분석', '많이 찾는 키워드 분석'],
        'market' => [MarketAnalysis::class, ['keyword'], '관련 쇼핑 시장 분석', '최신 쇼핑 시장 분석'],
        'product' => [ProductAnalysis::class, ['name'], '관련 상품 리뷰 분석', '최신 상품 리뷰 분석'],
        'seller' => [SellerPowerAnalysis::class, ['product_name', 'keyword'], '관련 셀러력 진단', '최신 셀러력 진단'],
        'store' => [PlaceStoreAnalysis::class, ['name', 'keyword'], '관련 플레이스 매장 분석', '최신 플레이스 매장 분석'],
    ];

    /**
     * 문서 하단 추천 섹션 목록.
     * $extraKeywords — 연관 키워드 등 정확 일치로 매칭할 추가 후보(키워드 문서의 vm.related 등).
     *
     * @return array<int, array{title: string, items: array<int, array{type: string, title: string, meta: string, url: string}>}>
     */
    public function sectionsFor(Model $doc, array $extraKeywords = []): array
    {
        $basis = (string) $doc->shareSlugBasis();
        $terms = $this->terms($basis);
        $extras = collect($extraKeywords)
            ->map(fn ($k) => trim((string) $k))
            ->filter(fn ($k) => $k !== '' && mb_strtolower($k, 'UTF-8') !== mb_strtolower($basis, 'UTF-8'))
            ->unique()->take(10)->values()->all();

        if (! $terms && ! $extras) {
            return [];
        }

        $key = 'related:v1:'.$doc->shareSlugPrefix().':'.$doc->getKey().':'.md5(implode('|', array_merge($terms, $extras)));

        return Cache::remember($key, self::TTL, fn () => $this->build($doc, $terms, $extras));
    }

    private function build(Model $doc, array $terms, array $extras): array
    {
        $self = $doc->shareSlugPrefix();
        // 같은 타입 섹션을 맨 앞에, 나머지는 고정 순서로
        $order = array_values(array_unique(array_merge([$self], array_keys(self::TYPES))));

        $sections = [];
        foreach ($order as $prefix) {
            if (! isset(self::TYPES[$prefix])) {
                continue;
            }
            [$cls, $cols, $title, $fallbackTitle] = self::TYPES[$prefix];
            $isSelf = $prefix === $self;

            $matched = $cls::query()->latest('id')
                ->when($doc instanceof $cls, fn ($q) => $q->where($doc->getKeyName(), '!=', $doc->getKey()))
                ->where(function ($w) use ($cols, $terms, $extras) {
                    foreach ($cols as $col) {
                        if ($extras) {
                            $w->orWhereIn($col, $extras);
                        }
                        foreach ($terms as $t) {
                            $w->orWhere($col, 'like', '%'.addcslashes($t, '\\%_').'%');
                        }
                    }
                })
                ->limit(30)->get();

            $sectionTitle = $title;
            if ($isSelf) {
                if ($matched->isEmpty()) {
                    $sectionTitle = $fallbackTitle;
                }
                if ($matched->count() < 3) {
                    // 같은 타입은 인기/최신 문서로 채워 빈 섹션을 방지(교차 타입은 매칭분만)
                    $pad = ($prefix === 'keyword' ? $cls::query()->orderByDesc('monthly_total') : $cls::query()->latest('id'))
                        ->when($doc instanceof $cls, fn ($q) => $q->where($doc->getKeyName(), '!=', $doc->getKey()))
                        ->limit(12)->get();
                    $matched = $matched->concat($pad);
                }
            }

            $items = $this->uniqueItems($matched, $prefix, $isSelf ? self::SELF_LIMIT : self::CROSS_LIMIT);
            if ($items) {
                $sections[] = ['title' => $sectionTitle, 'items' => $items];
            }
        }

        return $sections;
    }

    /** 소재 텍스트(중복 제거) 기준으로 유일한 추천 아이템 목록을 만든다. */
    private function uniqueItems(Collection $models, string $prefix, int $limit): array
    {
        $items = [];
        $seen = [];
        foreach ($models as $m) {
            $it = $this->item($m, $prefix);
            if (! $it) {
                continue;
            }
            $dedupe = mb_strtolower($it['title'], 'UTF-8');
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;
            unset($it['_basis']);
            $items[] = $it;
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    private function item(Model $m, string $prefix): ?array
    {
        $it = match ($prefix) {
            'keyword' => [
                'type' => '키워드',
                'title' => trim((string) $m->keyword).' 키워드 분석',
                'meta' => ((int) $m->monthly_total) > 0
                    ? '월 '.number_format((int) $m->monthly_total).'회 검색'
                    : '검색량·경쟁·연관 키워드',
            ],
            'market' => [
                'type' => '쇼핑 시장',
                'title' => trim((string) $m->keyword).' 쇼핑 시장 분석',
                'meta' => ((int) $m->sales_6m) > 0
                    ? '6개월 판매 약 '.number_format((int) $m->sales_6m).'건'
                    : '시장 규모·가격대·경쟁 강도',
            ],
            'product' => [
                'type' => '상품 리뷰',
                'title' => trim((string) $m->name).' 리뷰 분석',
                'meta' => ((int) $m->total_reviews) > 0
                    ? '리뷰 '.number_format((int) $m->total_reviews).'개'.($m->avg_score > 0 ? ' · 평점 '.number_format((float) $m->avg_score, 1) : '')
                    : '리뷰 감정·옵션 선호 분석',
            ],
            'seller' => [
                'type' => '셀러력',
                'title' => trim((string) ($m->product_name ?: $m->keyword)).' 셀러력 진단',
                'meta' => $m->score !== null
                    ? '종합 '.round((float) $m->score).'점'.($m->grade ? ' · '.$m->grade.'등급' : '')
                    : '5축 경쟁 비교·처방',
            ],
            'store' => [
                'type' => '플레이스',
                'title' => trim((string) $m->name).' 매장 분석',
                'meta' => "'".$m->keyword."' ".((! $m->rank || $m->rank >= 300) ? '상위권 밖' : $m->rank.'위'),
            ],
            default => null,
        };
        if (! $it || trim($it['title']) === '') {
            return null;
        }
        $it['url'] = $m->shareUrl();

        return $it;
    }

    /**
     * 소재 텍스트 → 매칭 토큰. 한글/영문/숫자 토큰(2자+, 순수 숫자 제외) + 공백 제거 전체 문구.
     * 예) '제주도 호텔' → ['제주도호텔', '제주도', '호텔']
     */
    private function terms(string $basis): array
    {
        $basis = trim(mb_strtolower($basis, 'UTF-8'));
        if ($basis === '') {
            return [];
        }
        preg_match_all('/[\p{L}\p{N}]+/u', $basis, $m);
        $tokens = array_values(array_filter($m[0] ?? [], fn ($t) => mb_strlen($t, 'UTF-8') >= 2 && ! preg_match('/^\d+$/', $t)));

        $joined = preg_replace('/[^\p{L}\p{N}]+/u', '', $basis);
        if ($joined !== '' && mb_strlen($joined, 'UTF-8') >= 2) {
            array_unshift($tokens, $joined);
        }

        return array_slice(array_values(array_unique($tokens)), 0, 6);
    }
}
