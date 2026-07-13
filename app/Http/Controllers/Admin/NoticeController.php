<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notice;
use Illuminate\Http\Request;

/** 공지사항 관리 (운영자). */
class NoticeController extends Controller
{
    public function index()
    {
        return view('admin.notices.index', ['notices' => Notice::listed()->paginate(20)]);
    }

    public function create()
    {
        return view('admin.notices.form', ['notice' => new Notice(['category' => '일반', 'is_published' => true])]);
    }

    public function store(Request $request)
    {
        Notice::create($this->validated($request));

        return redirect()->route('admin.notices')->with('status', '공지사항을 등록했습니다.');
    }

    public function edit(Notice $notice)
    {
        return view('admin.notices.form', ['notice' => $notice]);
    }

    public function update(Request $request, Notice $notice)
    {
        $notice->update($this->validated($request));

        return redirect()->route('admin.notices')->with('status', '공지사항을 수정했습니다.');
    }

    public function destroy(Notice $notice)
    {
        $notice->delete();

        return back()->with('status', '공지사항을 삭제했습니다.');
    }

    public function toggle(Notice $notice)
    {
        $notice->update(['is_published' => ! $notice->is_published]);

        return back()->with('status', '게시 상태를 변경했습니다.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'category' => 'required|string|max:40',
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'published_at' => 'nullable|date',
        ]);
        $data['is_pinned'] = $request->boolean('is_pinned');
        $data['is_published'] = $request->boolean('is_published');
        $data['published_at'] = $data['published_at'] ?? now();

        return $data;
    }
}
