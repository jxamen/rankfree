<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** 홍보 배너 관리 — 대시보드 노출 배너 (운영자). */
class BannerController extends Controller
{
    public function index()
    {
        return view('admin.banners.index', ['banners' => Banner::sorted()->paginate(30)]);
    }

    public function create()
    {
        return view('admin.banners.form', ['banner' => new Banner(['type' => 'promo', 'is_active' => true, 'bg_color' => '#111111', 'text_color' => '#ffffff'])]);
    }

    public function store(Request $request)
    {
        Banner::create($this->validated($request));

        return redirect()->route('admin.banners')->with('status', '배너를 등록했습니다.');
    }

    public function edit(Banner $banner)
    {
        return view('admin.banners.form', ['banner' => $banner]);
    }

    public function update(Request $request, Banner $banner)
    {
        $banner->update($this->validated($request));

        return redirect()->route('admin.banners')->with('status', '배너를 수정했습니다.');
    }

    public function destroy(Banner $banner)
    {
        $banner->delete();

        return back()->with('status', '배너를 삭제했습니다.');
    }

    public function toggle(Banner $banner)
    {
        $banner->update(['is_active' => ! $banner->is_active]);

        return back()->with('status', '노출 상태를 변경했습니다.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'body' => 'nullable|string|max:2000',
            'image_url' => 'nullable|string|max:1000',
            'image_file' => 'nullable|image|mimes:jpg,jpeg,png,gif,webp|max:4096',
            'link_url' => 'nullable|string|max:1000',
            'link_label' => 'nullable|string|max:60',
            'type' => 'required|in:promo,product,company',
            'bg_color' => 'nullable|string|max:40',
            'text_color' => 'nullable|string|max:40',
            'sort_order' => 'nullable|integer',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date',
        ]);

        // 이미지 파일 업로드 시 저장 후 image_url에 공개 경로 주입(URL 직접 입력보다 우선)
        if ($request->hasFile('image_file')) {
            $path = $request->file('image_file')->store('banners', 'public');
            $data['image_url'] = Storage::disk('public')->url($path);
        }
        unset($data['image_file']);

        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
