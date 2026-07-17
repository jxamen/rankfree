<?php

namespace App\Domain\NewBiz;

use App\Models\NewBusiness;

/**
 * 신규 개업 업소 → 네이버 플레이스 등록 여부(24 2단계).
 * **공식 지역검색 API**(NaverLocalSearchService)로 "{상호} {지역}" 을 검색해 같은 시/군/구의 같은 상호가
 * 있으면 등록된 것으로 본다. 없으면 아직 플레이스가 없는 신규 업소 — 우리 서비스가 도울 여지가 있는 리드.
 *
 * ⚠️ pcmap 순위검색(PlaceRankChecker)은 상호 검색이 안 돼(실측) 전부 '미등록' 오판이 난다. 쓰지 말 것.
 * ⚠️ 응답에 플레이스 ID 가 없어 링크는 지도 검색(map.naver.com/p/search/…)으로 연결한다.
 *
 * 판정: (1) 상호 정규화 일치(공백·특수문자 제거 후 포함 관계) AND (2) 같은 시/군/구
 */
class NewBusinessPlaceMatcher
{
    public function __construct(private NaverLocalSearchService $search) {}

    /** @return 'found'|'not_found' */
    public function match(NewBusiness $biz): string
    {
        $name = trim((string) $biz->bplc_nm);
        if ($name === '') {
            $this->save($biz, null);

            return 'not_found';
        }

        // 지역을 붙여 동명 업소 오검출을 줄인다("신천동 조선솥밥")
        $region = trim((string) ($biz->emd ?: $biz->sgg ?: ''));
        $hit = $this->pick($this->search->search(trim($region.' '.$name)), $biz)
            ?: $this->pick($this->search->search($name), $biz);   // 지역 붙여 0건이면 상호만으로 재시도

        $this->save($biz, $hit);

        return $hit ? 'found' : 'not_found';
    }

    /** 검색 결과 중 같은 시/군/구 + 상호가 맞는 항목. */
    private function pick(array $items, NewBusiness $biz): ?array
    {
        $target = $this->norm($biz->bplc_nm);
        $sgg = trim((string) $biz->sgg);
        foreach ($items as $it) {
            $title = $this->norm($it['title']);
            $addr = $it['road_address'].' '.$it['address'];
            $sameName = $title !== '' && ($title === $target || str_contains($title, $target) || str_contains($target, $title));
            $sameArea = $sgg === '' || str_contains($addr, $sgg);
            if ($sameName && $sameArea) {
                return $it;
            }
        }

        return null;
    }

    /** 상호 정규화 — 공백·괄호·특수문자 제거("풍납 주먹고기 풍납본점" ~ "풍납주먹고기"). */
    private function norm(string $s): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(trim($s), 'UTF-8'));
    }

    private function save(NewBusiness $biz, ?array $hit): void
    {
        $biz->forceFill([
            'place_name' => $hit['title'] ?? null,
            'place_cat' => $hit ? mb_substr((string) $hit['category'], 0, 30) : null,
            'place_status' => $hit ? 'found' : 'not_found',
            'place_checked_at' => now(),
        ])->save();
    }
}
