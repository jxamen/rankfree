<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\Request;

/**
 * GA4 대시보드(admin.traffic-stats) 드래그 배치·숨김 서버 저장 — 운영자 공용.
 * localStorage(브라우저별) 대신 서버에 두어 캐시 삭제·다른 기기에서도 배치가 유지된다.
 */
class Ga4LayoutController extends Controller
{
    private const KEY = 'ga4.layout';

    /** 저장된 배치 반환({rows, hidden}). 없으면 빈 객체 → 프런트가 기본 배치 사용. */
    public function show()
    {
        $data = AppSetting::readJson(self::KEY);

        return response()->json($data ?: (object) []);
    }

    /** 배치 저장 — rows(줄별 섹션키 배열의 배열) + hidden(숨긴 섹션키 배열). 문자열 키만 허용. */
    public function store(Request $request)
    {
        $rows = collect((array) $request->input('rows', []))
            ->map(fn ($r) => array_values(array_filter((array) $r, 'is_string')))
            ->filter(fn ($r) => $r !== [])
            ->values()
            ->all();
        $hidden = array_values(array_filter((array) $request->input('hidden', []), 'is_string'));

        AppSetting::write(self::KEY, json_encode(['rows' => $rows, 'hidden' => $hidden], JSON_UNESCAPED_UNICODE));

        return response()->json(['ok' => true]);
    }
}
