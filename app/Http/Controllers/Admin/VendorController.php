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
            'gsheet_tab' => ['nullable', 'string', 'max:120'],
            'memo' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

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
