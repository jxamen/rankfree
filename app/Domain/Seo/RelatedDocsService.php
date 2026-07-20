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
        $seen = []; // 페이지 전체(섹션 간) 제목 중복 방지

        // 지리 기반 "주변 지역·업종" — 플레이스 타입 키워드 문서 + 대표 좌표가 있을 때 맨 앞에.
        // 같은 카테고리(유사도) 추천은 지역이 안 맞을 수 있어, 실제 거리순 다른 지역/업종을 먼저 보여준다.
        if ($doc instanceof KeywordSearch && $doc->place_x !== null && $doc->place_y !== null) {
            $geo = $this->nearbyPlaceItems($doc, $seen);
            if ($geo) {
                $sections[] = ['title' => '가까운 지역 · 업종 추천', 'items' => $geo];
            }
        }

        // 같은 카테고리 인기 키워드(허브 문서) — 아고다식 "주변" 추천을 맨 앞에(22 Phase 2·3)
        // 키워드 문서는 자기 카테고리로, 시장/셀러력/매장 문서는 같은 키워드의 허브 문서를 찾아 그 카테고리로 연결.
        [$catId, $catName, $excludeId] = $this->resolveCategory($doc);
        if ($catId) {
            $catDocs = KeywordSearch::where('origin', 'hub')
                ->where('category_id', $catId)
                ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
                ->orderByDesc('monthly_total')->limit(12)->get();
            $items = $this->uniqueItems($catDocs, 'keyword', self::SELF_LIMIT, $seen);
            if ($items) {
                $sections[] = ['title' => ($catName ? "'{$catName}' 카테고리" : '같은 카테고리').' 인기 키워드', 'items' => $items];
            }
        }

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

            $items = $this->uniqueItems($matched, $prefix, $isSelf ? self::SELF_LIMIT : self::CROSS_LIMIT, $seen);
            if ($items) {
                $sections[] = ['title' => $sectionTitle, 'items' => $items];
            }
        }

        return $sections;
    }

    /**
     * 문서의 허브 카테고리 결정 — [category_id, 카테고리명, 제외할 허브 문서 id].
     * 허브 키워드 문서는 자기 카테고리(자신 제외), 그 외 keyword 를 가진 문서(시장/셀러력/매장)는
     * 같은 키워드의 허브 문서를 찾아 그 카테고리를 쓴다(허브 문서 자신은 추천에 포함).
     */
    private function resolveCategory(Model $doc): array
    {
        if ($doc instanceof KeywordSearch) {
            return $doc->category_id
                ? [$doc->category_id, $doc->category?->name, $doc->getKey()]
                : [null, null, null];
        }

        $kw = trim((string) ($doc->keyword ?? ''));
        if ($kw === '') {
            return [null, null, null];
        }
        $hub = KeywordSearch::with('category')->where('origin', 'hub')->where('keyword', $kw)->first();

        return $hub && $hub->category_id ? [$hub->category_id, $hub->category?->name, null] : [null, null, null];
    }

    /** 제목 기준 유일한 추천 아이템 목록 — $seen 은 페이지 전체(섹션 간) 중복 방지 누적분. */
    private function uniqueItems(Collection $models, string $prefix, int $limit, array &$seen): array
    {
        $items = [];
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
     * 대표 좌표 기준 거리순 주변 허브 문서 — 다른 지역/업종을 가까운 순으로(카테고리당 최대 2개, 다양성).
     * 바운딩박스(±약 6km)로 1차 좁히고 하버사인 거리로 정렬한다.
     *
     * @return array<int, array{type:string,title:string,meta:string,url:string}>
     */
    private function nearbyPlaceItems(KeywordSearch $doc, array &$seen): array
    {
        $lat = (float) $doc->place_y;
        $lng = (float) $doc->place_x;
        if ($lat === 0.0 || $lng === 0.0) {
            return [];
        }
        $km = 6.0;
        $dLat = $km / 111.0;
        $dLng = $km / (111.0 * max(0.15, cos(deg2rad($lat))));   // 위도에 따른 경도 1도 거리 보정

        $cands = KeywordSearch::where('origin', 'hub')
            ->where('id', '!=', $doc->getKey())
            ->whereNotNull('place_x')->whereNotNull('place_y')
            ->whereBetween('place_x', [$lng - $dLng, $lng + $dLng])
            ->whereBetween('place_y', [$lat - $dLat, $lat + $dLat])
            ->with('category:id,name,type')
            ->limit(80)->get();
        if ($cands->isEmpty()) {
            return [];
        }

        // 실제 거리 계산 → 8km 이내만 → 가까운 순
        $cands = $cands
            ->each(fn ($c) => $c->setAttribute('__dist', $this->haversineKm($lat, $lng, (float) $c->place_y, (float) $c->place_x)))
            ->filter(fn ($c) => $c->getAttribute('__dist') <= 8.0)
            ->sortBy(fn ($c) => $c->getAttribute('__dist'))->values();

        // 다양성 우선(카테고리당 최대 2개) → 부족하면 가까운 순 나머지로 채운다(캡에 굶지 않게).
        $primary = [];
        $overflow = [];
        $catCount = [];
        foreach ($cands as $c) {
            $kw = trim((string) $c->keyword);
            $dedupe = mb_strtolower($kw, 'UTF-8');
            if ($kw === '' || isset($seen[$dedupe])) {
                continue;
            }
            $cid = (int) ($c->category_id ?: 0);
            if (($catCount[$cid] ?? 0) < 2) {
                $catCount[$cid] = ($catCount[$cid] ?? 0) + 1;
                $primary[] = $c;
            } else {
                $overflow[] = $c;
            }
        }

        $items = [];
        foreach (array_merge($primary, $overflow) as $c) {
            if (count($items) >= self::SELF_LIMIT) {
                break;
            }
            $kw = trim((string) $c->keyword);
            $dedupe = mb_strtolower($kw, 'UTF-8');
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;

            $d = (float) $c->getAttribute('__dist');
            $dist = $d < 1.0 ? max(50, (int) round($d * 1000 / 50) * 50).'m' : number_format($d, 1).'km';
            $items[] = [
                'type' => '주변',
                'title' => $kw.' 키워드 분석',
                'meta' => ($c->category?->name ? $c->category->name.' · ' : '').'약 '.$dist,
                'url' => $c->shareUrl(),
            ];
        }

        return $items;
    }

    /** 두 좌표(위도 lat·경도 lng) 사이 거리(km) — 하버사인. */
    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
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
