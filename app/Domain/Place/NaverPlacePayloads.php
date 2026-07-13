<?php

namespace App\Domain\Place;

/**
 * 카테고리별 pcmap-api GraphQL payload — crm smartplace.payload.php 이식.
 * 치환 토큰: {#query} {#start} {#x} {#y} {#bounds}. 순위판별에 필요한 필드만 남긴 경량 쿼리.
 * ⚠️ GraphQL query 선언은 본문에서 실제 쓰는 변수만(미사용 변수는 validation 오류).
 */
class NaverPlacePayloads
{
    /** @return array<string,string> */
    public static function map(): array
    {
        $place = <<<'JSON'
[{"operationName":"getPlacesList","variables":{"useReverseGeocode":true,"input":{"query":"{#query}","start":{#start},"display":50,"adult":false,"spq":false,"queryRank":"","x":"{#x}","y":"{#y}","deviceType":"pcmap"},"isNmap":true,"isBounds":true,"reverseGeocodingInput":{"x":"{#x}","y":"{#y}"}},"query":"query getPlacesList($input: PlacesInput, $isNmap: Boolean!, $isBounds: Boolean!) {  businesses: places(input: $input) {    total    items {      id      name      category      dbType      distance      roadAddress      address      x      y      markerLabel @include(if: $isNmap) {        text        __typename      }      visitorReviewCount      blogCafeReviewCount      imageCount      __typename    }    optionsForMap @include(if: $isBounds) {      center      __typename    }    queryString    siteSort    __typename  }}"}]
JSON;

        // getRestaurantsPcmap → restaurants.businesses.items. item에 리뷰수·저장수·평점·태그 포함.
        $restaurants = <<<'JSON'
[{"operationName":"getRestaurantsPcmap","variables":{"input":{"query":"{#query}","start":{#start},"display":50,"isCurrentLocationSearch":null,"deviceType":"pcmap","isPcmap":true}},"query":"query getRestaurantsPcmap($input: PlaceListInput) {  restaurants: placeList(input: $input) {    businesses {      total      items {        id        name        category        businessCategory        x        y        visitorReviewCount        blogCafeReviewCount        bookingReviewCount        visitorReviewScore        tags        saveCount        imageCount        __typename      }      __typename    }    __typename  }}"}]
JSON;

        $hospital = <<<'JSON'
[{"operationName":"getNxList","variables":{"useReverseGeocode":true,"input":{"query":"{#query}","display":50,"start":{#start},"filterBooking":false,"filterOpentime":false,"filterSpecialist":false,"sortingOrder":"precision","x":"{#x}","y":"{#y}","day":null,"bounds":"{#bounds}","deviceType":"pcmap"},"reverseGeocodingInput":{"x":"{#x}","y":"{#y}"}},"query":"query getNxList($input: HospitalListInput, $reverseGeocodingInput: ReverseGeocodingInput, $useReverseGeocode: Boolean = false) {  businesses: hospitals(input: $input) {    total    items {      id      name      category      businessCategory      x      y      distance      roadAddress      address      visitorReviewCount      blogCafeReviewCount      imageCount      __typename    }    __typename  }  reverseGeocodingAddr(input: $reverseGeocodingInput) @include(if: $useReverseGeocode) {    rcode    region    __typename  }}"}]
JSON;

        $hairshop = <<<'JSON'
[{"operationName":"getBeautyList","variables":{"useReverseGeocode":true,"input":{"query":"{#query}","display":50,"start":{#start},"filterBooking":false,"filterCoupon":false,"filterNpay":false,"filterOpening":false,"filterBookingPromotion":false,"naverBenefit":false,"sortingOrder":"precision","x":"{#x}","y":"{#y}","bounds":"{#bounds}","deviceType":"pcmap","bypassStyleClous":false,"ignoreQueryResult":false},"reverseGeocodingInput":{"x":"{#x}","y":"{#y}"}},"query":"query getBeautyList($input: BeautyListInput, $reverseGeocodingInput: ReverseGeocodingInput, $useReverseGeocode: Boolean = false) {  businesses: hairshopList(input: $input) {    total    items {      id      name      category      x      y      distance      roadAddress      address      visitorReviewCount      blogCafeReviewCount      bookingReviewCount      imageCount      __typename    }    queryString    siteSort    __typename  }  reverseGeocodingAddr(input: $reverseGeocodingInput) @include(if: $useReverseGeocode) {    rcode    region    __typename  }}"}]
JSON;

        // nailshop: getBeautyList 이나 리스트 필드가 nailshopList (실측 2026-07-09). 필드명만 치환.
        $nailshop = str_replace('hairshopList', 'nailshopList', $hairshop);

        return [
            'place' => $place,
            'restaurants' => $restaurants,
            'restaurant' => $restaurants,       // cat 단수 호환
            'hospital' => $hospital,
            'hairshop' => $hairshop,
            'nailshop' => $nailshop,
            'accommodation' => $place,
        ];
    }

    public static function for(string $cat): string
    {
        $map = self::map();

        return $map[$cat] ?? $map['place'];
    }

    /** 지원 카테고리(순위 payload 키) 목록 */
    public static function categories(): array
    {
        return ['place', 'restaurant', 'hospital', 'hairshop', 'nailshop', 'accommodation'];
    }
}
