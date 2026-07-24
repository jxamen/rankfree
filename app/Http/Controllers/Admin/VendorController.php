<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use Illuminate\Http\Request;

/** 외부 발주 업체 관리(admin) — API/구글시트 채널 접속 정보 CRUD. */
class VendorController extends Controller
{
    public function index(Request $request)
    {
        $q = Vendor::withCount('productVendors')->orderBy('name');
        if ($kw = trim((string) $request->query('q', ''))) {
            $q->where('name', 'like', "%{$kw}%");
        }

        return view('admin.vendors.index', [
            'vendors' => $q->paginate(20)->withQueryString(),
            'q' => $kw ?? '',
        ]);
    }

    public function store(Request $request)
    {
        Vendor::create($this->validated($request));

        return back()->with('status', '업체가 등록되었습니다.');
    }

    public function update(Request $request, Vendor $vendor)
    {
        $vendor->update($this->validated($request));

        return back()->with('status', "'{$vendor->name}' 업체 정보를 수정했습니다.");
    }

    public function toggle(Vendor $vendor)
    {
        $vendor->update(['is_active' => ! $vendor->is_active]);

        return back();
    }

    /**
     * 구글시트 헤더(1행) 조회 (ajax) — 상품 편집 매핑 UI 에서 시트 열 이름을 자동으로 보여주기 위함.
     * 전송(append)과 동일한 서비스 계정·스코프를 사용하므로 발주가 되는 시트면 조회도 된다.
     */
    public function sheetColumns(Vendor $vendor)
    {
        if ($vendor->channel !== 'gsheet' || trim((string) $vendor->gsheet_id) === '') {
            return response()->json(['error' => '구글시트 채널 업체가 아니거나 시트 ID가 설정되지 않았습니다.'], 422);
        }
        $token = \App\Support\GoogleServiceAccount::token('https://www.googleapis.com/auth/spreadsheets');
        if (! $token) {
            return response()->json(['error' => '구글 서비스 계정 인증 실패 — .env GOOGLE_SERVICE_ACCOUNT_JSON(키 파일 경로)을 확인하세요.'], 422);
        }

        // 1) 탭 목록 조회 — 탭 미설정이면 첫 탭 자동 사용, 설정돼 있으면 실존 검증(불일치가 400 의 주원인)
        $meta = \Illuminate\Support\Facades\Http::timeout(15)->withToken($token)
            ->get("https://sheets.googleapis.com/v4/spreadsheets/{$vendor->gsheet_id}?fields=sheets.properties.title");
        if (! $meta->successful()) {
            $hint = match ($meta->status()) {
                403 => ' — 시트를 서비스 계정 이메일에 공유했는지 확인하세요.',
                404 => ' — 시트 ID를 확인하세요.',
                default => '',
            };

            return response()->json(['error' => '시트 조회 실패 (HTTP '.$meta->status().')'.$hint], 422);
        }
        $tabs = collect($meta->json('sheets', []))->pluck('properties.title')
            ->filter(fn ($t) => trim((string) $t) !== '')->values();
        // ?tab= — 상품 단위 탭 미리보기(2026-07-22): 배분별 탭 오버라이드 UI 가 특정 탭의 열을 조회할 때 사용.
        // 미지정이면 업체 기본 탭(레거시 — 설정 UI 는 제거됨, 기존 값만 폴백) → 첫 탭 순.
        $tab = trim((string) request()->query('tab', ''));
        if ($tab === '') {
            $tab = trim((string) $vendor->gsheet_tab);
        }
        if ($tab === '') {
            $tab = (string) ($tabs->first() ?? '');
        }
        if ($tab === '') {
            return response()->json(['error' => '시트에 탭이 없습니다.'], 422);
        }
        if (! $tabs->contains($tab)) {
            return response()->json(['error' => "시트에 '{$tab}' 탭이 없습니다 — 사용 가능한 탭: ".$tabs->implode(', ').' (상품 편집의 시트 탭에서 다시 선택하세요)'], 422);
        }

        // 2) 해당 탭 1행(헤더) 조회
        $range = rawurlencode("'{$tab}'!1:1");
        $res = \Illuminate\Support\Facades\Http::timeout(15)->withToken($token)
            ->get("https://sheets.googleapis.com/v4/spreadsheets/{$vendor->gsheet_id}/values/{$range}");
        if (! $res->successful()) {
            return response()->json(['error' => '시트 조회 실패 (HTTP '.$res->status().')'], 422);
        }

        $cols = array_map(fn ($c) => trim((string) $c), $res->json('values.0', []) ?? []);

        return response()->json(['tab' => $tab, 'tabs' => $tabs, 'columns' => $cols]);
    }

    public function destroy(Vendor $vendor)
    {
        $name = $vendor->name;
        $vendor->delete();   // product_vendors cascade — 기존 전송 이력(order_dispatches)은 vendor_name 으로 보존

        return back()->with('status', "'{$name}' 업체를 삭제했습니다.");
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'channel' => ['required', 'in:api,gsheet'],
            'api_url' => ['nullable', 'string', 'max:500'],
            'api_method' => ['nullable', 'in:POST,GET,PUT'],
            'api_headers' => ['nullable', 'string', 'max:2000'],
            'api_format' => ['nullable', 'in:json,form'],
            'gsheet_id' => ['nullable', 'string', 'max:120'],
            'memo' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
            'weekend_batch_dispatch' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        $data['weekend_batch_dispatch'] = $request->boolean('weekend_batch_dispatch', false);

        // 헤더 JSON 유효성 — 비었으면 null, 깨진 JSON 이면 반려
        if (trim((string) ($data['api_headers'] ?? '')) === '') {
            $data['api_headers'] = null;
        } elseif (! is_array(json_decode($data['api_headers'], true))) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'api_headers' => '헤더는 JSON 객체 형식이어야 합니다. 예: {"Authorization": "Bearer xxx"}',
            ]);
        }

        return $data;
    }
}
