<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Popup;
use Illuminate\Http\Request;

/** 팝업 관리 — 위치·크기·기간 지정, 본문 WYSIWYG (운영자). */
class PopupController extends Controller
{
    public function index()
    {
        return view('admin.popups.index', ['popups' => Popup::sorted()->paginate(30)]);
    }

    public function create()
    {
        return view('admin.popups.form', ['popup' => new Popup(['position' => 'center', 'width' => 420, 'is_active' => true, 'dismissible' => true])]);
    }

    public function store(Request $request)
    {
        Popup::create($this->validated($request));

        return redirect()->route('admin.popups')->with('status', '팝업을 등록했습니다.');
    }

    public function edit(Popup $popup)
    {
        return view('admin.popups.form', ['popup' => $popup]);
    }

    public function update(Request $request, Popup $popup)
    {
        $popup->update($this->validated($request));

        return redirect()->route('admin.popups')->with('status', '팝업을 수정했습니다.');
    }

    public function destroy(Popup $popup)
    {
        $popup->delete();

        return back()->with('status', '팝업을 삭제했습니다.');
    }

    public function toggle(Popup $popup)
    {
        $popup->update(['is_active' => ! $popup->is_active]);

        return back()->with('status', '노출 상태를 변경했습니다.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'position' => 'required|in:center,top-left,top-right,bottom-left,bottom-right',
            'width' => 'nullable|integer|min:240|max:900',
            'sort_order' => 'nullable|integer',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date',
        ]);
        $data['width'] = (int) ($data['width'] ?? 420);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_active'] = $request->boolean('is_active');
        $data['dismissible'] = $request->boolean('dismissible');

        return $data;
    }
}
