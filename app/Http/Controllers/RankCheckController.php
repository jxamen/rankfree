<?php

namespace App\Http\Controllers;

use App\Domain\Place\PlaceRankChecker;
use App\Models\PlaceRankLookup;
use Illuminate\Http\Request;

class RankCheckController extends Controller
{
    /**
     * 홈 폼 → 1회성 무료 순위 조회 → 결과 페이지.
     * place 입력은 URL/placeId(정확) 또는 업체명(부분일치) 모두 허용.
     */
    public function check(Request $request, PlaceRankChecker $checker)
    {
        $data = $request->validate([
            'keyword' => 'required|string|max:100',
            'place' => 'required|string|max:255',
        ]);

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
}
