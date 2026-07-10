<?php

namespace App\Domain\Place;

/**
 * 플레이스 경쟁분석 점수 엔진 — crm smartplace.analysis.lib.php(spa_*) 이식.
 *
 * 순수 계산만 담당(네트워크 X). N1(유사도)·N2(관련성)·N3(랭킹) + 세부지표 D1~D10.
 * ⚠️ 점수는 관측 신호 기반 자체 추정치이며 "네이버 공식 점수"가 아니다.
 *
 * 가중치(고정):
 *   N2 = D1 .18 / D2 .09 / D3 .07 / D4 .12 / D5 .08 / D7 .14 / D9 .20 / D10 .12  (결측 재정규화)
 *   N1 = L .30 / B .30 / T .30 / M .10
 *   N3 = 100·(1 − ln(min(rnk,300)) / ln 301)
 */
class PlaceScorer
{
    /** 90분위값. */
    public static function p90(array $arr): float
    {
        $v = array_values(array_filter($arr, fn ($x) => $x !== null));
        if (! count($v)) {
            return 0;
        }
        sort($v);
        $idx = (int) ceil(0.9 * count($v)) - 1;
        if ($idx < 0) {
            $idx = 0;
        }

        return (float) $v[$idx];
    }

    /** Hazen 백분위 0~100. */
    public static function pct(?float $x, array $arr): ?float
    {
        if ($x === null) {
            return null;
        }
        $v = array_values(array_filter($arr, fn ($y) => $y !== null));
        $n = count($v);
        if (! $n) {
            return null;
        }
        $lt = 0;
        $eq = 0;
        foreach ($v as $y) {
            if ($y < $x) {
                $lt++;
            } elseif ($y == $x) {
                $eq++;
            }
        }

        return round(100 * ($lt + 0.5 * $eq) / $n, 3);
    }

    /** 로그-P90 정규화 0~100 = 100·min(1, ln(1+x)/ln(1+P90)). */
    public static function absP90(?float $x, array $arr): ?float
    {
        if ($x === null) {
            return null;
        }
        $p90 = self::p90($arr);
        if ($p90 <= 0) {
            return null;
        }

        return round(100 * min(1, log(1 + $x) / log(1 + $p90)), 3);
    }

    /** 마스크 가중평균 — [[score,weight],...], score null 은 가중치에서 제외 후 재정규화. */
    public static function weighted(array $pairs): ?float
    {
        $sw = 0;
        $swg = 0;
        foreach ($pairs as $p) {
            if ($p[0] !== null) {
                $sw += $p[1];
                $swg += $p[1] * $p[0];
            }
        }

        return $sw > 0 ? round($swg / $sw, 3) : null;
    }

    /** 업종어 사전(키워드 분해용). */
    public static function bizTerms(): array
    {
        return [
            // 음식/외식
            '맛집', '음식점', '식당', '레스토랑', '술집', '이자카야', '포차', '포장마차', '횟집', '초밥', '스시', '오마카세',
            '고기', '삼겹살', '냉삼', '곱창', '막창', '갈비', '양갈비', '한우', '국밥', '곰탕', '설렁탕', '해장국',
            '한식', '중식', '일식', '양식', '분식', '파스타', '피자', '치킨', '족발', '보쌈', '냉면', '칼국수', '국수',
            '우동', '라멘', '마라탕', '부대찌개', '매운탕', '전골', '뷔페', '조개구이', '장어', '오리', '닭갈비',
            '쭈꾸미', '낙지', '아구', '해물', '킹크랩', '대게', '양꼬치', '순대', '백숙', '브런치', '다이닝', '바베큐',
            '맥주', '호프', '와인바', '수제버거', '포케', '샐러드',
            // 카페/디저트
            '카페', '베이커리', '빵집', '디저트', '케이크', '도넛', '와플', '빙수', '커피', '로스터리', '티룸',
            // 미용/뷰티
            '미용실', '헤어샵', '헤어', '네일샵', '네일', '왁싱', '속눈썹', '반영구', '태닝', '피부관리', '뷰티',
            '에스테틱', '마사지', '타이마사지', '스웨디시', '두피', '메이크업', '슈가링', '눈썹', '래쉬', '붙임머리',
            // 병원/의료
            '병원', '의원', '치과', '한의원', '한의', '정형외과', '피부과', '성형외과', '이비인후과', '안과', '산부인과',
            '내과', '정신과', '통증의학과', '도수치료', '물리치료', '약국', '동물병원', '비뇨기과', '재활', '교정',
            '임플란트', '필러', '보톡스', '내성발톱', '클리닉',
            // 학원/교육
            '학원', '교습소', '독서실', '스터디카페', '피아노', '미술', '태권도', '주짓수', '복싱', '헬스장', '헬스',
            '필라테스', '요가', '크로스핏', '골프', '스크린골프', '수영', '발레', '유도', '검도', '논술', '공부방', '어학원',
            // 숙박
            '펜션', '모텔', '호텔', '게스트하우스', '리조트', '글램핑', '캠핑', '카라반', '풀빌라', '스테이',
            // 생활/서비스
            '정수기', '렌탈', '에어컨청소', '에어컨설치', '청소', '이사', '인테리어', '도배', '장판', '빨래방', '세탁',
            '사진관', '스튜디오', '꽃집', '플라워', '안경', '부동산', '공인중개사', '세무사', '법무사', '변호사', '노무사',
            '간판', '철거', '방역', '줄눈', '탄성코트', '수선', '열쇠', '커튼', '블라인드', '휴대폰', '전자담배',
            '하수구막힘', '하수구', '변기막힘', '덴트', '손세차', '세차', '타이어', '카센터', '사무실', '상담센터',
            '심리상담', '누수탐지', '여권사진', '맞춤정장', '렌트카', '강아지분양',
            // 여가/레저
            '애견카페', '애견미용', '애견호텔', '스크린야구', '방탈출', '노래방', '만화카페', '파티룸', '볼링', '당구',
            '골프연습장', '키즈카페', '클라이밍', '서핑', '요트', '승마', '낚시', '점집', '사주', '타로', '작명',
        ];
    }

    /** 키워드 → [loc(지역), core(지역핵심), biz(업종어), rest, full]. getAllLocations 미보유 → 나머지=지역 폴백. */
    public static function splitKeyword(string $keyword): array
    {
        $kw = trim($keyword);
        $biz = '';
        foreach (self::bizTerms() as $t) {
            if (mb_strpos($kw, $t) !== false && mb_strlen($t) > mb_strlen($biz)) {
                $biz = $t;
            }
        }
        // rankfree 에는 지역 사전이 없어 업종어 제거한 나머지를 지역으로 사용(crm 폴백 경로와 동일)
        $loc = '';
        $rest = ($biz !== '') ? trim(str_replace($biz, '', $kw)) : trim(str_replace($loc, '', $kw));
        if ($loc === '') {
            $loc = $rest;
        }
        if ($biz === '') {
            $biz = $rest;
        }
        $stripped = preg_replace('/(역|동|구|읍|면|리|로|길|사거리|네거리|먹자골목|먹자|시장|촌|점|맛집)$/u', '', $loc);
        $core = (mb_strlen((string) $stripped) >= 2) ? (string) $stripped : $loc;

        return ['loc' => $loc, 'core' => $core, 'biz' => $biz, 'rest' => $rest, 'full' => $kw];
    }

    /** D7 정보충실성 체크리스트 항목들(라벨·raw·grade·가중·가용). */
    public static function seoItems(array $d, string $cat): array
    {
        $g = fn ($k) => $d[$k] ?? null;
        $it = [];
        $add = function (&$it, $label, $raw, $grade, $w, $avail) {
            $it[] = ['label' => $label, 'raw' => $raw, 'grade' => round((float) $grade, 2), 'w' => $w, 'avail' => $avail ? 1 : 0];
        };
        $menuBase = ($cat === 'restaurant') ? 10 : 5;
        $mc = (int) $g('menu_cnt');
        $add($it, '메뉴/시술', $mc.'개', min(1, $mc / $menuBase), 1.5, in_array($cat, ['restaurant', 'hairshop', 'nailshop'], true));
        $kc = (int) $g('keyword_cnt');
        $add($it, '대표키워드', $kc.'개', min(1, $kc / 5), 1.5, true);
        $add($it, '찾아오는길', $g('has_road') ? '작성' : '없음', $g('has_road') ? 1 : 0, 1.5, $g('has_road') !== null);
        $pc = (int) $g('photo_cnt');
        $add($it, '대표사진', $pc.'장', min(1, $pc / 10), 1.0, true);
        $add($it, '영업시간 공개', $g('hide_hours') ? '비공개' : '공개', $g('hide_hours') ? 0 : 1, 1.0, $g('hide_hours') !== null);
        $add($it, '예약 연결', $g('has_booking') ? '연결' : '없음', $g('has_booking') ? 1 : 0, 1.0, in_array($cat, ['restaurant', 'hairshop', 'nailshop', 'hospital'], true));
        $add($it, '가격 공개', $g('hide_price') ? '비공개' : '공개', $g('hide_price') ? 0 : 1, 1.0, in_array($cat, ['hairshop', 'nailshop', 'hospital'], true));
        $mi = (int) $g('missing_cnt');
        $mlRaw = $g('missing_labels');
        $ml = trim(is_array($mlRaw) ? implode(',', $mlRaw) : (string) $mlRaw);
        $miRaw = ($mi > 0) ? (($ml !== '') ? ($ml.' 누락') : ($mi.'건 누락')) : '누락 없음';
        $add($it, '필수정보 완성', $miRaw, 1 - min(1, $mi / 3), 1.0, $g('missing_cnt') !== null);
        $add($it, '톡톡/챗봇', ($g('has_talktalk') || $g('has_chatbot')) ? '연결' : '없음', ($g('has_talktalk') || $g('has_chatbot')) ? 1 : 0, 0.8, true);
        $st = (int) $g('stylist_cnt');
        $add($it, '스타일리스트', $st.'명', min(1, $st / 3), 0.8, $cat === 'hairshop');
        $cv = (int) $g('conv_cnt');
        $add($it, '편의시설', $cv.'개', min(1, $cv / 5), 0.7, $g('conv_cnt') !== null);
        $cc = (int) $g('category_cnt');
        $add($it, '부가 카테고리', $cc.'개', ($cc >= 2) ? 1 : 0, 0.5, $g('category_cnt') !== null);
        $py = (int) $g('pay_cnt');
        $add($it, '결제수단', $py.'종', ($py > 0) ? 1 : 0, 0.5, $g('pay_cnt') !== null);

        return $it;
    }

    /** D7 정보충실성 0~100 = 100·Σ(avail w·grade)/Σ(avail w). */
    public static function seoChecklist(array $d, string $cat): ?float
    {
        $sw = 0;
        $swg = 0;
        foreach (self::seoItems($d, $cat) as $it) {
            if ($it['avail']) {
                $sw += $it['w'];
                $swg += $it['w'] * $it['grade'];
            }
        }

        return $sw > 0 ? round(100 * $swg / $sw, 3) : null;
    }

    /** N1/D8 구성요소 L(지역)/B(업종)/T(대표키워드)/M(상호). */
    public static function keywordComponents(string $keyword, string $name, string $category, string $address, $tags, string $cat = ''): array
    {
        $norm = fn ($s) => mb_strtolower(str_replace(' ', '', (string) $s), 'UTF-8');
        $split = self::splitKeyword($keyword);
        $bizRaw = $split['biz'];
        $biz = $norm($bizRaw);
        $loc = $norm($split['loc']);
        $core = $norm($split['core']);
        $full = $norm($split['full']);
        $n = $norm($name);
        $c = $norm($category);
        $a = $norm($address);
        $tagstr = $norm(is_array($tags) ? implode(' ', $tags) : $tags);
        $inAny = fn ($h) => $h !== '' && (($loc !== '' && mb_strpos($h, $loc) !== false) || ($core !== '' && mb_strpos($h, $core) !== false));
        // L 지역: 주소 > 상호 > 태그
        $L = ($loc === '' && $core === '') ? null : ($inAny($a) ? 1.0 : ($inAny($n) ? 0.7 : ($inAny($tagstr) ? 0.5 : 0)));
        // B 업종: 업종어→경로 매칭
        $bizPath = PlaceRankChecker::categoryKorToPath($bizRaw);
        $catPath = PlaceRankChecker::categoryKorToPath($category);
        $B = null;
        if ($bizRaw !== '') {
            if ($cat !== '' && $bizPath !== 'place' && $bizPath === $cat) {
                $B = 1.0;
            } elseif ($biz !== '' && mb_strpos($c, $biz) !== false) {
                $B = 1.0;
            } elseif ($bizPath !== 'place' && $bizPath === $catPath) {
                $B = 0.8;
            } else {
                $B = 0;
            }
        }
        // T 대표키워드: 전체 > 업종 > 지역핵심
        $T = ($tagstr === '') ? null : ((mb_strpos($tagstr, $full) !== false) ? 1.0 : (($biz !== '' && mb_strpos($tagstr, $biz) !== false) ? 0.6 : (($core !== '' && mb_strpos($tagstr, $core) !== false) ? 0.4 : 0)));
        // M 상호: 지역핵심 or 업종어 포함
        $M = ($n === '') ? null : (($core !== '' && mb_strpos($n, $core) !== false) ? 1.0 : (($biz !== '' && mb_strpos($n, $biz) !== false) ? 0.6 : 0));

        return [
            'L' => $L, 'B' => $B, 'T' => $T, 'M' => $M,
            'region' => $split['loc'], 'core' => $split['core'], 'bizterm' => $split['biz'],
        ];
    }

    /** N1/D8 키워드 일치 0~100 = 가중(L.30 B.30 T.30 M.10, 결측 재정규화). */
    public static function keywordMatch(string $keyword, string $name, string $category, string $address, $tags, string $cat = ''): ?float
    {
        $c = self::keywordComponents($keyword, $name, $category, $address, $tags, $cat);
        $parts = [[$c['L'], 0.30], [$c['B'], 0.30], [$c['T'], 0.30], [$c['M'], 0.10]];
        $sw = 0;
        $swg = 0;
        foreach ($parts as $p) {
            if ($p[0] !== null) {
                $sw += $p[1];
                $swg += $p[1] * $p[0];
            }
        }

        return $sw > 0 ? round(100 * $swg / $sw, 3) : null;
    }

    /**
     * 한 플레이스의 D1~D10 · N1/N2/N3 산출.
     *
     * @param  array       $item     serp item(visitor_cnt,blog_cnt,booking_cnt,save_cnt,review_score,tags,name,address,rnk,id)
     * @param  array|null  $detail   상세(없으면 T1만; visitor_cnt,blog_cnt,review_score,category,tags,seo필드,bv_raw,rec_raw,auth_raw)
     * @param  float[]     $visArr   경쟁셋 방문자리뷰 배열(P90 기준)
     */
    public static function computeScores(array $item, ?array $detail, string $keyword, string $cat, array $visArr, array $blogArr, array $saveArr, array $scoreArr, array $bookingArr = [], array $recArr = [], array $authArr = [], array $bvArr = []): array
    {
        $dv = fn ($k) => ($detail && array_key_exists($k, $detail)) ? $detail[$k] : null;

        $visitor = ($detail && $dv('visitor_cnt') !== null) ? $dv('visitor_cnt') : ($item['visitor_cnt'] ?? null);
        $blog = ($detail && $dv('blog_cnt') !== null) ? $dv('blog_cnt') : ($item['blog_cnt'] ?? null);
        $booking = $item['booking_cnt'] ?? null;
        $score = ($detail && $dv('review_score') !== null) ? $dv('review_score') : ($item['review_score'] ?? null);
        $save = $item['save_cnt'] ?? null;

        $D1 = self::absP90(self::num($visitor), $visArr);
        $D2 = self::absP90(self::num($blog), $blogArr);
        $D3 = self::absP90(self::num($booking), $bookingArr);
        // 예약자리뷰 미제공 업종: 방문맥락 "예약 후 이용" 카운트를 정규화해 D3 대체
        if ($D3 === null) {
            $bvRaw = $dv('bv_raw');
            if ($bvRaw !== null && count($bvArr)) {
                $D3 = self::absP90(self::num($bvRaw), $bvArr);
            }
        }
        // D4 평점 베이지안
        $D4 = null;
        if ($score !== null && $visitor !== null) {
            $mu = 0;
            $cnt = 0;
            foreach ($scoreArr as $s) {
                if ($s !== null) {
                    $mu += $s;
                    $cnt++;
                }
            }
            $mu = $cnt ? $mu / $cnt : 4.3;
            $st = ((float) $visitor * (float) $score + 20 * $mu) / ((float) $visitor + 20);
            $D4 = round(100 * max(0, min(1, ($st - 3.5) / 1.5)), 3);
        }
        $D5 = ($cat === 'restaurant') ? self::absP90(self::num($save), $saveArr) : null;
        $D7 = $detail ? self::seoChecklist($detail, $cat) : null;
        $tags = ($detail && ! empty($detail['tags'])) ? $detail['tags'] : ($item['tags'] ?? []);
        $D8 = self::keywordMatch($keyword, (string) ($item['name'] ?? ''), (string) ($detail ? ($detail['category'] ?? '') : ''), (string) ($item['address'] ?? ''), $tags, $cat);

        $recRaw = $dv('rec_raw');
        $authRaw = $dv('auth_raw');
        $D9 = ($recRaw !== null && count($recArr)) ? self::absP90(self::num($recRaw), $recArr) : null;
        $D10 = ($authRaw !== null && count($authArr)) ? self::pct(self::num($authRaw), $authArr) : null;

        $N1 = $D8;
        $N2 = self::weighted([
            [$D1, 0.18], [$D2, 0.09], [$D3, 0.07], [$D4, 0.12],
            [$D5, 0.08], [$D7, 0.14], [$D9, 0.20], [$D10, 0.12],
        ]);
        $rnk = isset($item['rnk']) ? (int) $item['rnk'] : 0;
        $N3 = ($rnk > 0) ? round(100 * (1 - log(min($rnk, 300)) / log(301)), 3) : null;

        $mask = 0;
        foreach ([$D1, $D2, $D3, $D4, $D5, $D7, $D8, $D9, $D10] as $i => $b) {
            if ($b !== null) {
                $mask |= (1 << $i);
            }
        }

        return [
            'd1' => $D1, 'd2' => $D2, 'd3' => $D3, 'd4' => $D4, 'd5' => $D5,
            'd7' => $D7, 'd8' => $D8, 'd9' => $D9, 'd10' => $D10,
            'n1' => $N1, 'n2' => $N2, 'n3' => $N3, 'act' => null,
            'mask' => $mask, 'tier' => $detail ? 2 : 1,
        ];
    }

    /** N3 랭킹 점수만 단독 계산(순위추적 연동용). */
    public static function rankScore(int $rnk): ?float
    {
        return ($rnk > 0) ? round(100 * (1 - log(min($rnk, 300)) / log(301)), 3) : null;
    }

    private static function num($v): ?float
    {
        return $v === null ? null : (float) $v;
    }
}
