<?php

namespace App\Http\Controllers;

use App\Domain\Place\PlaceRankChecker;
use App\Domain\Shopping\NaverShoppingRankService;
use App\Models\PlaceRankLookup;
use App\Support\Turnstile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RankCheckController extends Controller
{
    /**
     * 비회원 봇 차단(Cloudflare Turnstile). 회원은 검증 생략, 비회원만 검사.
     * 실패 시 입력값을 유지한 채 에러와 함께 홈으로 되돌린다.
     */
    private function botGuard(Request $request): ?RedirectResponse
    {
        if ($request->user()) {
            return null; // 회원 → 봇 검증 생략
        }
        if (! Turnstile::verify($request->input('cf-turnstile-response'), $request->ip())) {
            return back()->withErrors(['captcha' => '봇 검증에 실패했습니다. 잠시 후 다시 시도하세요.'])->withInput();
        }

        return null;
    }

    /**
     * 홈 폼(플레이스 탭) → 1회성 무료 플레이스 순위 조회 → 결과 페이지.
     * place 입력은 URL/placeId(정확) 또는 업체명(부분일치) 모두 허용.
     */
    public function check(Request $request, PlaceRankChecker $checker)
    {
        $data = $request->validate([
            'keyword' => 'required|string|max:100',
            'place' => 'required|string|max:255',
        ]);
        if ($blocked = $this->botGuard($request)) {
            return $blocked;
        }

        $keyword = trim($data['keyword']);
        $place = trim($data['place']);

        $placeId = PlaceRankChecker::extractPlaceId($place);
        $targetName = $placeId ? null : $place;

        $result = $checker->check($keyword, $placeId, $targetName);

        PlaceRankLookup::create([
            'user_id' => $request->user()?->id,
            'keyword' => $keyword,
            'place_id' => $result['place_id'] ?: (string) $placeId,
            'category' => $result['category'],
            'rank' => $result['rank'],
            'list_total' => $result['list_total'],
            'review_count' => $result['review_count'],
            'blog_review_count' => $result['blog_review_count'],
            'save_count' => $result['save_count'],
            'review_score' => $result['review_score'],
            'place_name' => $result['place_name'] ?: (string) $targetName,
            'ip' => $request->ip(),
        ]);

        return view('rank.result', [
            'keyword' => $keyword,
            'place' => $place,
            'result' => $result,
        ]);
    }

    /**
     * 홈 폼(쇼핑 탭) → 1회성 무료 쇼핑 순위 조회 → 결과 페이지.
     * target 입력은 상품 URL/ID 또는 스토어(업체)명 허용.
     */
    public function shopCheck(Request $request, NaverShoppingRankService $shop)
    {
        $data = $request->validate([
            'keyword' => 'required|string|max:100',
            'target' => 'required|string|max:500',
        ]);
        if ($blocked = $this->botGuard($request)) {
            return $blocked;
        }

        $keyword = trim($data['keyword']);
        $target = $shop->resolveTarget(trim($data['target']));
        $result = $shop->checkRank($keyword, $target);

        return view('rank.shop-result', [
            'keyword' => $keyword,
            'target' => trim($data['target']),
            'result' => $result,
        ]);
    }

    /** 홈 폼 자동입력 — 플레이스 URL/ID → 깔끔한 m.place URL + 업체명(placeSummary). */
    public function resolvePlace(Request $request, PlaceRankChecker $checker): JsonResponse
    {
        $pid = PlaceRankChecker::extractPlaceId(trim((string) $request->input('url', '')));
        if (! $pid) {
            return response()->json(['ok' => false]);
        }
        try {
            $sum = $checker->placeSummary($pid);   // ['name'=>업체명, 'category'=>경로]
        } catch (\Throwable) {
            $sum = ['name' => '', 'category' => 'place'];
        }

        return response()->json([
            'ok' => true,
            'url' => PlaceRankChecker::buildMPlaceUrl($pid, $sum['category'] ?? 'place'),
            'name' => (string) ($sum['name'] ?? ''),
        ]);
    }

    /** 홈 폼 자동입력 — 쇼핑 상품 URL(스마트스토어·검색·가격비교) → 상품명(og:title). */
    public function resolveShop(Request $request): JsonResponse
    {
        $url = trim((string) $request->input('url', ''));
        if (! preg_match('#^https?://#i', $url)) {
            return response()->json(['ok' => false]);
        }
        $html = '';
        try {
            $res = \Illuminate\Support\Facades\Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36',
                'Accept-Language' => 'ko-KR,ko;q=0.9',
            ])->timeout((int) config('rankfree.place.timeout', 12))->get($url);
            $html = $res->ok() ? $res->body() : '';
        } catch (\Throwable) {
            $html = '';
        }

        $name = '';
        if ($html !== '') {
            if (preg_match('#<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)#i', $html, $m)
                || preg_match('#<title>([^<]+)</title>#i', $html, $m)) {
                $name = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
                $name = trim(preg_replace('/\s*[:|]\s*(네이버|스마트스토어|NAVER).*$/u', '', $name));
            }
        }

        return response()->json(['ok' => $name !== '', 'name' => $name]);
    }
}
