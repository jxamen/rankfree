<?php

namespace App\Http\Controllers;

use App\Models\ProductAnalysis;
use Illuminate\Http\Request;

/** 상품 분석(리뷰 분석) 내역 — 콘솔 (확장 프로그램 수집분 열람). */
class ProductAnalysisController extends Controller
{
    public function index(Request $request)
    {
        return view('console.product', [
            'analyses' => $request->user()->productAnalyses()->latest()->paginate(20),
        ]);
    }

    public function show(Request $request, ProductAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);

        return view('console.product-show', ['a' => $analysis]);
    }

    /** 공개 공유 리포트 — 공유 토큰으로 비로그인 열람. */
    public function shared(string $token)
    {
        $a = ProductAnalysis::where('share_token', $token)->firstOrFail();

        return view('product.share', ['a' => $a]);
    }

    public function destroy(Request $request, ProductAnalysis $analysis)
    {
        abort_unless($analysis->user_id === $request->user()->id, 403);
        $analysis->delete();

        return redirect()->route('console.product')->with('status', "'{$analysis->name}' 분석 내역을 삭제했습니다.");
    }
}
