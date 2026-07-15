<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 에디터 이미지 첨부 업로드 — 로그인 사용자. public 디스크 저장 후 루트상대 URL 반환. */
class UploadController extends Controller
{
    public function image(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|image|mimes:jpg,jpeg,png,gif,webp|max:5120',   // 5MB
        ]);

        $path = $request->file('image')->store('community', 'public');   // storage/app/public/community/xxx

        // 호스트(APP_URL) 의존을 피해 루트상대 경로 반환 → public/storage 심링크로 서빙
        return response()->json(['url' => '/storage/'.$path]);
    }
}
