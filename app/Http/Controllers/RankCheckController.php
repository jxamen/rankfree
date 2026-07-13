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
}
