<?php

namespace App\Http\Controllers\Api;

use App\Domain\Keyword\NaverKeywordService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 크롬 확장 — 키워드 분석(월간 검색량 등) API. */
class ExtKeywordController extends Controller
{
    public function show(Request $request, NaverKeywordService $service): JsonResponse
    {
        $keyword = trim((string) $request->query('keyword', ''));

        if ($keyword === '') {
            return response()->json(['data' => null, 'message' => 'keyword 파라미터가 필요합니다.'], 422);
        }

        $data = $service->analyze($keyword);

        return response()->json([
            'data' => $data,
            'message' => $data === null ? '키워드 검색량 데이터를 조회하지 못했습니다.' : null,
        ]);
    }
}
