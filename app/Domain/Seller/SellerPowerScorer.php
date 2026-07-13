<?php

namespace App\Domain\Seller;

/**
 * 셀러력 채점기 — 내 상품 vs 검색 상위 경쟁 상품(최대 10)을 5축으로 비교.
 * ─────────────────────────────────────────────────────────────────────
 * 원칙: 절대 임계값이 아니라 "경쟁 상위 평균 대비 내 위치"로 상대평가한다.
 * 5축: 적합도(SEO) · 인기도 · 신뢰도 · 기본·배송 · 마케팅·판매자.
 * 점수·등급은 관측 신호 기반 자체 추정치("네이버 공식 지수 아님").
 * 설계: .claude/15_SELLER_POWER.md
 */
class SellerPowerScorer
{
    /** 축별 총점 가중치(네이버 랭킹 기여도 기반 초안). */
    private const AXIS_WEIGHT = [
        '적합도' => 0.25, '인기도' => 0.30, '신뢰도' => 0.20, '기본·배송' => 0.15, '마케팅·판매자' => 0.10,
    ];

    /**
     * @param  array  $payload  ['keyword','terms'[],'my'=>{product,smartStoreV2,mallInfoCache,zzim,visit,blog,insta},'competitors'=>[…]]
     */
    public function score(array $payload): array
    {
        $kw = (string) ($payload['keyword'] ?? '');
        $terms = array_values(array_filter(array_map('strval', (array) ($payload['terms'] ?? []))));

        $my = $this->metrics((array) ($payload['my'] ?? []), $kw, $terms);
        $comps = array_map(fn ($c) => $this->metrics((array) $c, $kw, $terms), (array) ($payload['competitors'] ?? []));

        return $this->compute($my, $comps, $kw);
    }

    /**
     * 검색 결과(네이버 쇼핑 리스트) 아이템만으로 채점 — 상세 페이지 없이 5축.
     * content.js normalizeItem 형식: {title, price, reviewCount, reviewCountSum, purchase6m,
     *   keepCount, freeShip, fastDelivery, npay, npayAccumRto, buyPoint, mallGrade, isBrandStore, ...}
     *
     * @param  array  $payload  ['keyword','terms'[],'my'=>{…item…},'competitors'=>[{…item…}]]
     */
    public function scoreSearch(array $payload): array
    {
        $kw = (string) ($payload['keyword'] ?? '');
        $terms = array_values(array_filter(array_map('strval', (array) ($payload['terms'] ?? []))));

        $my = $this->searchMetrics((array) ($payload['my'] ?? []), $kw, $terms);
        $comps = array_map(fn ($c) => $this->searchMetrics((array) $c, $kw, $terms), (array) ($payload['competitors'] ?? []));

        return $this->compute($my, $comps, $kw);
    }

    /** metrics 산출 이후 공통 계산(축·총점·포지션·진단). */
    private function compute(array $my, array $comps, string $kw): array
    {
        $avg = $this->avgMetrics($comps);

        $myAxes = $this->axesScore($my, $avg);
        $myTotal = $this->total($myAxes);

        // 경쟁 축·총점, 레이더용 상위 평균(경쟁 축점수 평균)
        $compAxes = array_map(fn ($c) => $this->axesScore($c, $avg), $comps);
        $compTotals = array_map(fn ($ax) => $this->total($ax), $compAxes);
        $radarAvg = [];
        foreach (array_keys(self::AXIS_WEIGHT) as $k) {
            $vs = array_map(fn ($ax) => $ax[$k], $compAxes);
            $radarAvg[$k] = $vs ? array_sum($vs) / count($vs) : 0;
        }

        // 시장 내 위치(나 + 경쟁 합산 기준)
        $higher = count(array_filter($compTotals, fn ($t) => $t > $myTotal));
        $rankInTop = $higher + 1;
        $n = count($compTotals) + 1;
        $percentile = (int) round($rankInTop / $n * 100);
        $grade = $this->grade($percentile);

        $allSorted = $compTotals;
        $allSorted[] = $myTotal;
        rsort($allSorted);
        $myIdx = array_search($myTotal, $allSorted, true);

        $axes = [];
        foreach (self::AXIS_WEIGHT as $k => $w) {
            $axes[] = [
                'key' => $k, 'mine' => (int) round($myAxes[$k]), 'avg' => (int) round($radarAvg[$k]),
                'gap' => (int) round($myAxes[$k] - $radarAvg[$k]), 'weight' => $w,
            ];
        }

        [$rx, $losses] = $this->diagnose($my, $avg);

        return [
            'keyword' => $kw,
            'product_name' => $my['name'],
            'tags' => array_slice($my['tags'] ?? [], 0, 30), // 판매자 태그(상세에서 수집된 경우만)
            'score' => $myTotal,
            'grade' => $grade,
            'market_percentile' => $percentile,
            'rank_in_top' => $rankInTop,
            'competitor_count' => count($comps),
            'axes' => $axes,
            'radar_avg_total' => (int) round($this->total($radarAvg)),
            'positions' => array_map(fn ($t) => (int) round($t), $allSorted),
            'my_position_index' => $myIdx === false ? 0 : $myIdx,
            'losses' => $losses,
            'rx' => $rx,
            'fetched_at' => date('Y-m-d H:i'),
        ];
    }

    // ── 지표 추출 ─────────────────────────────────────────────────────

    /**
     * 검색 아이템 → metrics 키 매핑(axesScore가 그대로 소비).
     * 검색에 없는 상세 지표(상세설명·태그·방문·블로그·인스타)는 0(내·경쟁 모두 0이면 상대평가에서 중립).
     */
    private function searchMetrics(array $it, string $kw, array $terms): array
    {
        $name = (string) ($it['title'] ?? '');
        $reviews = (int) ($it['reviewCountSum'] ?? $it['reviewCount'] ?? 0);
        $reviewsRecent = (int) ($it['reviewCount'] ?? 0); // 최근 판매 프록시(최근 리뷰 유입)
        $sales6m = (int) ($it['purchase6m'] ?? 0);
        $price = (float) ($it['price'] ?? 0);
        $imgs = (int) ($it['additionalImageCount'] ?? $it['imgCount'] ?? 0);
        // manuTag: 콤마구분 태그(상품키워드·오늘출발·무료교환반품 등) → 검색모드 태그 SEO 신호
        $manuTag = (string) ($it['manuTag'] ?? '');
        $manuTags = array_values(array_filter(array_map('trim', explode(',', $manuTag)), fn ($t) => $t !== ''));

        return [
            'name' => $name,
            // 적합도 — 제목·태그(manuTag) 키워드(다단어 대응: 단어별 포함 카운트)
            'title_kw' => $this->kwWordHits($name, $kw),
            'title_term' => $this->countTerms($name, $terms),
            'desc_kw' => 0, 'detail_kw' => 0,
            'tag_kw' => $this->kwWordHits($manuTag, $kw), // manuTag에 핵심 키워드 포함(단어별)
            'search_tags' => count($manuTags), // manuTag 태그 수
            'title_len' => mb_strlen($name),
            'detail_len' => 0,
            'thumb' => 0, // 검색 additionalImageCount는 실제 대표 이미지 수 아님(상세 필요) → 제외
            'mall_grade' => $this->searchGradeScore($it),
            'good_service' => ! empty($it['goodService']) ? 1 : 0, // 굿서비스 배지(검색에 있으면)
            'official' => ! empty($it['official']) ? 1 : 0, // 공식 판매처(리스트 ico_public DOM)
            'brand_mall' => ! empty($it['isBrandStore']) ? 1 : 0, // 브랜드몰(brandNo≠0)
            'coupon' => ! empty($it['hasCoupon']) ? 1 : 0, // 쿠폰(hasCouponContent)
            // 인기도 — 판매·리뷰·찜 (+ 리뷰 기반 최근 판매 추정)
            'recent3' => $reviewsRecent > 0 ? $reviewsRecent : (int) round($sales6m / 60), // 최근 3일≈리뷰유입 / 6개월÷60
            'sales6m' => $sales6m,
            'revenue6m' => $sales6m * $price,
            'reviews' => $reviews,
            'zzim' => (int) ($it['keepCount'] ?? 0),
            'visit' => 0,
            'satisfaction' => 0,
            'price' => $price,
            // 기본·배송 — 검색 확정 필드
            'naver_pay' => ! empty($it['npay']) ? 1 : 0,
            'shop_reg' => 1, // 검색에 노출 = 쇼핑 등록됨
            'today_delivery' => ! empty($it['fastDelivery']) ? 1 : 0,
            'arrival_guarantee' => ! empty($it['arrivalGuarantee']) ? 1 : 0, // N배송(도착보장)
            'talktalk' => ! empty($it['talktalk']) ? 1 : 0, // 톡톡(검색엔 대개 없음 → 0,0이면 진단 제외)
            'free_exchange' => ! empty($it['freeExchange']) ? 1 : 0, // 무료교환반품(manuTag)
            'delivery_complete' => 0,
            'delivery_lead' => 0,
            'free_ship' => ! empty($it['freeShip']) ? 1 : 0,
            'base_fee' => (float) ($it['deliveryFee'] ?? 0),
            'return_fee' => 0,
            // npay 적립(포인트) — 신규 지표
            'npay_point' => (float) ($it['buyPoint'] ?? 0),
            'npay_rate' => (float) ($it['npayAccumRto'] ?? 0),
            'npay_given' => (($it['buyPoint'] ?? 0) > 0 || ($it['npayAccumRto'] ?? 0) > 0) ? 1 : 0, // 포인트 지급 여부
            // 마케팅·판매자 — 검색으로는 스토어 등급/브랜드만
            'blog_visit' => 0, 'blog_friend' => 0, 'insta_follower' => 0, 'insta_media' => 0,
            'tags' => [], // 판매자 태그는 상세에서만 수집됨
        ];
    }

    /** 검색 아이템 몰등급 → 점수(브랜드스토어 우대, mallGrade 문자열 보조). */
    private function searchGradeScore(array $it): float
    {
        if (! empty($it['isBrandStore'])) {
            return 90;
        }

        return $this->gradeScore((string) ($it['mallGrade'] ?? ''));
    }

    /** 상품 노드({product=product.A, smartStoreV2, mallInfoCache, …})에서 원자 지표 추출(방어적). */
    private function metrics(array $n, string $kw, array $terms): array
    {
        $A = (array) data_get($n, 'product', []);
        $ss = (array) data_get($n, 'smartStoreV2', []);
        $mall = (array) data_get($n, 'mallInfoCache', []);

        $name = (string) data_get($A, 'name', '');
        $desc = (string) data_get($ss, 'channel.description', '');
        $detail = strip_tags((string) data_get($A, 'detailContents.detailContentText', ''));
        $sellerTags = (array) data_get($A, 'seoInfo.sellerTags', []);
        $tagStr = implode(' ', array_map(fn ($t) => (string) data_get($t, 'text', ''), $sellerTags));
        $searchTags = count(array_filter($sellerTags, fn ($t) => ! empty(data_get($t, 'code'))));

        // 판매량 추정(원본 공식): 주간 leadTimeCount 합 → 하루 → 3일/6개월
        $lead = 0;
        foreach ((array) data_get($A, 'productDeliveryLeadTimes', []) as $l) {
            $lead += (int) data_get($l, 'leadTimeCount', 0);
        }
        $day = $lead > 0 ? round($lead / 7, 1) : 0;
        $recent3 = $day * 3;
        $sales6m = $day * 180;
        $price = (float) data_get($A, 'benefitsView.discountedSalePrice', 0);
        if (data_get($A, 'productDeliveryInfo.freeConditionalAmount')) {
            $price += (float) data_get($A, 'productDeliveryInfo.baseFee', 0);
        }
        $revenue6m = $price * $sales6m;

        $kwNorm = $this->norm($kw);

        return [
            'name' => $name,
            'title_kw' => $this->kwWordHits($name, $kw),
            'title_term' => $this->countTerms($name, $terms),
            'desc_kw' => $this->countKw($desc, $kwNorm),
            'detail_kw' => $this->countKw($detail, $kwNorm),
            'tag_kw' => $this->kwWordHits($tagStr, $kw),
            'search_tags' => $searchTags,
            'title_len' => mb_strlen($name),
            'detail_len' => mb_strlen(trim($detail)),
            // 이미지: 구(productImages) / 신(optionalImageUrls + 대표이미지)
            'thumb' => count((array) data_get($A, 'productImages', []))
                ?: (count((array) data_get($A, 'optionalImageUrls', [])) + (data_get($A, 'representativeImageUrl') ? 1 : 0)),
            'mall_grade' => $this->gradeScore((string) data_get($mall, 'mallGrade', '')),
            'good_service' => data_get($mall, 'goodService') ? 1 : 0,
            'coupon' => (data_get($A, 'benefitsView.couponUsable') || data_get($A, 'hasCoupon')) ? 1 : 0,
            'recent3' => $recent3,
            'sales6m' => $sales6m,
            'revenue6m' => $revenue6m,
            // 리뷰수: 그룹 상품(모든 옵션 합산) 우선 → 없으면 단일 옵션
            'reviews' => (int) (data_get($A, 'simpleStandardGroupProduct.reviewAmount.totalReviewCount')
                ?: data_get($A, 'reviewAmount.totalReviewCount', 0)),
            'review_score' => (float) (data_get($A, 'simpleStandardGroupProduct.reviewAmount.averageReviewScore')
                ?: data_get($A, 'reviewAmount.averageReviewScore', 0)),
            'zzim' => (int) (data_get($n, 'zzim') ?? data_get($n, 'keepCnt') ?? 0),
            'visit' => (int) data_get($n, 'visit', 0),
            'satisfaction' => (float) data_get($ss, 'channel.averageSaleSatificationScore', 0),
            'price' => $price,
            'naver_pay' => data_get($ss, 'channel.naverPayNo') ? 1 : 0,
            'shop_reg' => data_get($A, 'epInfo.naverShoppingRegistration') ? 1 : 0,
            'today_delivery' => data_get($A, 'productDeliveryInfo.todayDelivery') ? 1 : 0,
            'arrival_guarantee' => (data_get($A, 'productDeliveryInfo.arrivalGuarantee')
                || preg_match('/도착|ARRIVAL|GUARANTEE/i', (string) data_get($A, 'productDeliveryInfo.deliveryAttributeType', ''))) ? 1 : 0, // N배송(도착보장)
            'talktalk' => (data_get($ss, 'channel.talkUrl') || data_get($ss, 'channel.talkTalkUrl')
                || data_get($ss, 'channel.naverTalkNaverId') || data_get($ss, 'channel.contactInfo.talkUrl')) ? 1 : 0, // 톡톡 상담
            'delivery_complete' => (float) data_get($ss, 'channel.in2DaysDeliveryCompleteRatio', 0),
            'delivery_lead' => (float) data_get($A, 'averageDeliveryLeadTime.productAverageDeliveryLeadTime', 0),
            'free_ship' => in_array(data_get($A, 'productDeliveryInfo.deliveryFeeType'), ['FREE', 'CONDITIONAL_FREE'], true) ? 1 : 0,
            'base_fee' => (float) data_get($A, 'productDeliveryInfo.baseFee', 0),
            'return_fee' => (float) data_get($A, 'claimDeliveryInfo.returnDeliveryFee', 0),
            'blog_visit' => (int) data_get($n, 'blog.visit', 0),
            'blog_friend' => (int) data_get($n, 'blog.friend', 0),
            'insta_follower' => (int) data_get($n, 'insta.follower', 0),
            'insta_media' => (int) data_get($n, 'insta.media', 0),
            // npay 적립(포인트) — 상세 benefitsView
            'npay_point' => (float) (data_get($A, 'benefitsView.accumulatePointValue')
                ?: data_get($A, 'benefitsView.managerAccumulatePointValue', 0)),
            'npay_rate' => (float) data_get($A, 'benefitsView.naverMileageAccumulateRatio', 0),
            'npay_given' => (data_get($A, 'benefitsView.accumulatePointValue') || data_get($A, 'benefitsView.managerAccumulatePointValue')
                || data_get($A, 'benefitsView.naverMileageAccumulateRatio')) ? 1 : 0,
            'tags' => array_values(array_filter(array_map(fn ($t) => trim((string) data_get($t, 'text', '')), $sellerTags), fn ($t) => $t !== '')),
        ];
    }

    /** 경쟁 지표 평균(값 > 0 인 것만 — 미노출/무데이터 상품이 평균을 끌어내리지 않도록). */
    private function avgMetrics(array $comps): array
    {
        $keys = [
            'title_kw', 'title_term', 'desc_kw', 'detail_kw', 'tag_kw', 'search_tags', 'title_len', 'detail_len', 'thumb',
            'satisfaction', 'recent3', 'sales6m', 'revenue6m', 'reviews', 'zzim', 'visit',
            'delivery_complete', 'delivery_lead', 'base_fee', 'return_fee', 'price',
            'npay_point', 'npay_rate',
            'naver_pay', 'brand_mall', 'official', 'today_delivery', 'free_ship', 'free_exchange', 'arrival_guarantee', 'talktalk', 'npay_given', 'good_service', 'coupon', // bool: 경쟁사 채택률(1개라도 있으면 av>0)
            'blog_visit', 'blog_friend', 'insta_follower', 'insta_media',
        ];
        $out = [];
        foreach ($keys as $k) {
            $vals = array_filter(array_map(fn ($c) => (float) ($c[$k] ?? 0), $comps), fn ($v) => $v > 0);
            $out[$k] = $vals ? array_sum($vals) / count($vals) : 0;
        }

        return $out;
    }

    // ── 축 점수 ───────────────────────────────────────────────────────

    private function axesScore(array $m, array $a): array
    {
        $suit = [
            $this->rel($m['title_kw'], $a['title_kw'] ?? 0),
            $this->rel($m['title_term'], $a['title_term'] ?? 0),
            $this->rel($m['desc_kw'], $a['desc_kw'] ?? 0),
            $this->rel($m['detail_kw'], $a['detail_kw'] ?? 0),
            $this->rel($m['tag_kw'], $a['tag_kw'] ?? 0),
            $this->rel($m['search_tags'], $a['search_tags'] ?? 0),
            $m['mall_grade'],
            $this->titleLenScore($m['title_len']),
        ];
        $pop = [
            $this->rel($m['recent3'], $a['recent3'] ?? 0),
            $this->rel($m['sales6m'], $a['sales6m'] ?? 0),
            $this->rel($m['revenue6m'], $a['revenue6m'] ?? 0),
            $this->rel($m['reviews'], $a['reviews'] ?? 0),
            $this->rel($m['zzim'], $a['zzim'] ?? 0),
            $this->rel($m['visit'], $a['visit'] ?? 0),
        ];
        $trust = [
            $this->rel($m['detail_len'], $a['detail_len'] ?? 0),
            $this->rel($m['thumb'], $a['thumb'] ?? 0),
            $this->rel($m['satisfaction'], $a['satisfaction'] ?? 0),
            $this->boolScore($m['good_service']), // 굿서비스(인증)
            $this->boolScore($m['official'] ?? 0), // 공식 판매처
            $this->boolScore($m['brand_mall'] ?? 0), // 브랜드몰
            $this->boolScore($m['naver_pay']), // Npay+
        ];
        $basic = [
            $this->boolScore($m['shop_reg']),
            $this->boolScore($m['talktalk'] ?? 0), // 톡톡 상담
            $this->boolScore($m['today_delivery']),
            $this->boolScore($m['arrival_guarantee'] ?? 0), // N배송(도착보장)
            $this->rel($m['delivery_complete'], $a['delivery_complete'] ?? 0),
            $this->rel($m['delivery_lead'], $a['delivery_lead'] ?? 0, false),
            $this->boolScore($m['free_ship']),
            $this->boolScore($m['free_exchange'] ?? 0), // 무료교환반품
            $this->rel($m['base_fee'], $a['base_fee'] ?? 0, false),
            $this->rel($m['return_fee'], $a['return_fee'] ?? 0, false),
            $this->rel($m['price'], $a['price'] ?? 0, false), // 저렴할수록 경쟁력
        ];
        $mkt = [
            $this->rel($m['blog_visit'], $a['blog_visit'] ?? 0),
            $this->rel($m['blog_friend'], $a['blog_friend'] ?? 0),
            $this->rel($m['insta_follower'], $a['insta_follower'] ?? 0),
            $this->rel($m['insta_media'], $a['insta_media'] ?? 0),
            $m['mall_grade'],
            $this->boolScore($m['npay_given'] ?? 0), // 포인트 지급 여부(마케팅 레버)
            $this->rel($m['npay_point'] ?? 0, $a['npay_point'] ?? 0), // 포인트 지급액(높을수록)
            $this->boolScore($m['coupon'] ?? 0), // 쿠폰(마케팅 레버)
        ];

        return [
            '적합도' => $this->avg($suit),
            '인기도' => $this->avg($pop),
            '신뢰도' => $this->avg($trust),
            '기본·배송' => $this->avg($basic),
            '마케팅·판매자' => $this->avg($mkt),
        ];
    }

    private function total(array $axes): float
    {
        $t = 0;
        foreach (self::AXIS_WEIGHT as $k => $w) {
            $t += ($axes[$k] ?? 0) * $w;
        }

        return round($t, 1);
    }

    // ── 진단(처방·손해) ────────────────────────────────────────────────

    /** @return array{0:array,1:array} [rx(축별 체크리스트), losses(개선 우선순위 Top)] */
    private function diagnose(array $m, array $a): array
    {
        // [축, key, 라벨, higher, 난이도, 단위]
        $defs = [
            ['적합도', 'title_kw', '제목에 핵심 키워드', true, 'easy', '회'],
            ['적합도', 'tag_kw', '태그에 핵심 키워드', true, 'easy', '회'],
            ['적합도', 'search_tags', '검색적용 태그 수', true, 'easy', '개'],
            ['인기도', 'reviews', '리뷰 수', true, 'hard', '개'],
            ['인기도', 'zzim', '찜 수', true, 'mid', '개'],
            ['인기도', 'sales6m', '6개월 판매량', true, 'hard', '개'],
            ['신뢰도', 'detail_len', '상세 설명 길이', true, 'easy', '자'],
            ['신뢰도', 'thumb', '대표 이미지 수', true, 'easy', '장'],
            ['신뢰도', 'official', '공식 판매처', true, 'hard', ''],
            ['신뢰도', 'brand_mall', '브랜드몰', true, 'hard', ''],
            ['신뢰도', 'naver_pay', 'Npay+', true, 'easy', ''],
            ['기본·배송', 'today_delivery', '오늘출발', true, 'mid', ''],
            ['기본·배송', 'arrival_guarantee', 'N배송(도착보장)', true, 'mid', ''],
            ['기본·배송', 'free_ship', '무료배송', true, 'mid', ''],
            ['기본·배송', 'free_exchange', '무료교환반품', true, 'easy', ''],
            ['마케팅·판매자', 'npay_given', '포인트 지급', true, 'easy', ''],
            ['마케팅·판매자', 'npay_point', '포인트 지급액', true, 'easy', '원'],
            ['마케팅·판매자', 'coupon', '쿠폰', true, 'easy', ''],
            ['기본·배송', 'delivery_lead', '평균 배송기간', false, 'mid', '일'],
            ['기본·배송', 'talktalk', '톡톡 상담', true, 'easy', ''],
            ['신뢰도', 'good_service', '굿서비스', true, 'mid', ''],
            ['마케팅·판매자', 'blog_friend', '블로그 이웃', true, 'hard', '명'],
            ['마케팅·판매자', 'insta_follower', '인스타 팔로워', true, 'hard', '명'],
        ];
        $diffW = ['easy' => 1.4, 'mid' => 1.0, 'hard' => 0.6];
        $boolKeys = ['naver_pay', 'brand_mall', 'official', 'today_delivery', 'free_ship', 'free_exchange', 'arrival_guarantee', 'talktalk', 'npay_given', 'good_service', 'coupon'];
        // 설정이 아니라 네이버가 정량 기준(응대·배송 등) 충족 시 부여하는 "인증" 항목 — 손해/처방 문구를 다르게
        $earnedKeys = ['good_service', 'official'];

        $rxByAxis = [];
        $cand = [];
        foreach ($defs as [$axis, $key, $label, $higher, $diff, $unit]) {
            $mv = (float) ($m[$key] ?? 0);
            $av = (float) ($a[$key] ?? 0);
            $isBool = in_array($key, $boolKeys, true);
            $isEarned = in_array($key, $earnedKeys, true);
            // 숫자 지표가 내값·경쟁평균 모두 0 → 수집 안 된 상세전용(평균배송기간·블로그·인스타 등) → 제외.
            // 단, 제목 키워드는 검색모드 핵심 신호라 0이어도 항상 표시. bool "여부"도 항상 표시.
            if (! $isBool && $mv == 0.0 && $av == 0.0 && $key !== 'title_kw') {
                continue;
            }
            // 판매·매출은 네이버가 비공개인 경우 0으로 옴 → "0"이 아니라 "비공개"로 표기, 손해에서 제외
            $notDisclosed = $mv == 0.0 && in_array($key, ['sales6m', 'revenue6m'], true);
            $s = $isBool ? $this->boolScore($mv) : $this->rel($mv, $av, $higher);
            if ($isBool) {
                // 보유=ok / 나만 없음(경쟁사 보유)=bad / 아무도 없음=warn(권장)
                $state = $mv > 0 ? 'ok' : ($av > 0 ? 'bad' : 'warn');
            } else {
                $state = $notDisclosed ? 'warn' : ($s >= 70 ? 'ok' : ($s >= 45 ? 'warn' : 'bad'));
            }

            if ($isEarned) {
                // 인증형: 설정이 아니라 부여받는 것 → 문구를 그렇게
                $tip = $mv > 0 ? '인증됨' : '미획득';
            } elseif ($notDisclosed) {
                $tip = '비공개';
            } else {
                $tip = $this->tip($isBool, $mv, $av, $higher, $unit);
            }
            $rxByAxis[$axis][] = ['state' => $state, 'name' => $label, 'tip' => $tip];

            // 손해: 비공개·정상·인증형은 제외. bool은 "경쟁사가 실제 보유(av>0)"할 때만 손해로(아무도 없으면 단순 권장).
            $isLoss = ! $notDisclosed && ! $isEarned && $state !== 'ok' && ! ($isBool && $av <= 0);
            if ($isLoss) {
                $cand[] = [
                    'axis' => $axis, 'title' => $isBool ? $label.' 설정 안 됨' : $label.($higher ? ' 보강 필요' : ' 단축 필요'),
                    'cur' => $isBool ? '미설정' : $this->fmt($mv, $unit),
                    'target' => $isBool ? '설정 권장' : ($av > 0 ? '상위 평균 '.$this->fmt($av, $unit) : '개선 권장'),
                    'gain' => (int) round((100 - $s) * self::AXIS_WEIGHT[$axis]),
                    'difficulty' => $diff,
                    'priority' => (100 - $s) * $diffW[$diff],
                ];
            }
        }

        usort($cand, fn ($x, $y) => $y['priority'] <=> $x['priority']);
        $losses = [];
        foreach (array_slice($cand, 0, 3) as $i => $c) {
            unset($c['priority']);
            $losses[] = ['rank' => $i + 1] + $c;
        }

        $rx = [];
        foreach ($rxByAxis as $axis => $items) {
            $rx[] = ['axis' => $axis, 'items' => $items];
        }

        return [$rx, $losses];
    }

    private function tip(bool $isBool, float $mv, float $av, bool $higher, string $unit): string
    {
        if ($isBool) {
            return $mv ? '설정됨' : '미설정';
        }
        $cur = $this->fmt($mv, $unit);
        if ($av <= 0) {
            return '현재 '.$cur;
        }
        $avf = $this->fmt($av, $unit);

        return $higher
            ? $cur.' · 상위 평균 '.$avf.($mv < $av ? ' — 보강 필요' : ' — 우위')
            : $cur.' · 상위 평균 '.$avf.($mv > $av ? ' — 단축 권장' : ' — 우위');
    }

    // ── 유틸 ─────────────────────────────────────────────────────────

    /** 상대평가: 상위 평균 대비. avg면 80점, 2배면 100점 근처, 0이면 20점. */
    private function rel(float $my, float $avg, bool $higher = true): float
    {
        if (! $higher) { // 낮을수록 좋음(배송기간·배송비·가격)
            if ($my <= 0 || $avg <= 0) {
                return 60;
            }

            return max(0, min(100, round($avg / max(0.1, $my) * 60 + 20)));
        }
        if ($avg <= 0) {
            return $my > 0 ? 90 : 50;
        }

        return max(0, min(100, round($my / $avg * 60 + 20)));
    }

    private function boolScore($v): float
    {
        return $v ? 100 : 25;
    }

    /** 상품명 길이 최적화(네이버 권장 ~50자, 과도한 나열 감점). */
    private function titleLenScore(int $len): float
    {
        if ($len === 0) {
            return 20;
        }
        if ($len <= 50) {
            return 100;
        }
        if ($len <= 70) {
            return 80;
        }
        if ($len <= 100) {
            return 55;
        }

        return 35; // 키워드 남발 의심
    }

    /** 몰등급 문자열 → 점수(정확 매핑은 원본 확보 시 교정). */
    private function gradeScore(string $g): float
    {
        $g = strtoupper(trim($g));
        if ($g === '') {
            return 30;
        }

        return match (true) {
            str_contains($g, 'PREMIUM') || $g === '05' => 100,
            str_contains($g, 'BIG') || $g === '04' => 85,
            str_contains($g, 'POWER') || $g === '03' => 70,
            default => 55,
        };
    }

    private function avg(array $vals): float
    {
        $vals = array_filter($vals, fn ($v) => $v !== null);

        return $vals ? array_sum($vals) / count($vals) : 0;
    }

    private function grade(int $percentile): string
    {
        return match (true) {
            $percentile <= 10 => 'S',
            $percentile <= 30 => 'A',
            $percentile <= 50 => 'B',
            $percentile <= 70 => 'C',
            default => 'D',
        };
    }

    private function norm(string $s): string
    {
        return preg_replace('/\s+/u', '', mb_strtolower($s));
    }

    private function countKw(string $text, string $kwNorm): int
    {
        if ($kwNorm === '') {
            return 0;
        }

        return substr_count($this->norm($text), $kwNorm);
    }

    /** 키워드를 공백 단위로 나눠, 각 단어가 text에 포함된 개수(다단어 키워드 대응). */
    private function kwWordHits(string $text, string $kw): int
    {
        $textNorm = $this->norm($text);
        if ($textNorm === '') {
            return 0;
        }
        $hits = 0;
        foreach (preg_split('/\s+/u', trim($kw)) as $w) {
            $wn = $this->norm((string) $w);
            if ($wn !== '' && str_contains($textNorm, $wn)) {
                $hits++;
            }
        }

        return $hits;
    }

    private function countTerms(string $text, array $terms): int
    {
        $t = $this->norm($text);
        $c = 0;
        foreach ($terms as $term) {
            $tn = $this->norm((string) $term);
            if ($tn !== '' && str_contains($t, $tn)) {
                $c++;
            }
        }

        return $c;
    }

    private function fmt(float $v, string $unit): string
    {
        $n = $v >= 100 ? number_format(round($v)) : (round($v * 10) / 10);

        return $n.$unit;
    }
}
