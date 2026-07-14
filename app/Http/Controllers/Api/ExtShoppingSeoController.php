<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shopping\ShoppingTitleSeoAnalyzer;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 크롬 확장 — 쇼핑 상품명 SEO 분석(제목 점수·공통단어·추천 상품명·노출 키워드). 저장 없음(계산만). */
class ExtShoppingSeoController extends Controller
{
    public function analyze(Request $request, ShoppingTitleSeoAnalyzer $analyzer): JsonResponse
    {
        $data = $request->validate([
            'keyword' => ['required', 'string', 'max:100'],
            'products' => ['required', 'array', 'max:300'],
            'products.*.title' => ['nullable', 'string', 'max:250'],
            'products.*.rank' => ['nullable', 'integer'],
            'products.*.is_ad' => ['nullable', 'boolean'],
        ]);

        return response()->json(['data' => $analyzer->analyze($data['keyword'], $data['products'])]);
    }
}
