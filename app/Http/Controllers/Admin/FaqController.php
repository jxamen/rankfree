<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use Illuminate\Http\Request;

/** FAQ 관리 (운영자). */
class FaqController extends Controller
{
    public function index()
    {
        return view('admin.faqs.index', ['faqs' => Faq::sorted()->paginate(50)]);
    }

    public function create()
    {
        return view('admin.faqs.form', ['faq' => new Faq(['category' => '시작하기', 'is_published' => true])]);
    }

    public function store(Request $request)
    {
        Faq::create($this->validated($request));

        return redirect()->route('admin.faqs')->with('status', 'FAQ를 등록했습니다.');
    }

    public function edit(Faq $faq)
    {
        return view('admin.faqs.form', ['faq' => $faq]);
    }

    public function update(Request $request, Faq $faq)
    {
        $faq->update($this->validated($request));

        return redirect()->route('admin.faqs')->with('status', 'FAQ를 수정했습니다.');
    }

    public function destroy(Faq $faq)
    {
        $faq->delete();

        return back()->with('status', 'FAQ를 삭제했습니다.');
    }

    public function toggle(Faq $faq)
    {
        $faq->update(['is_published' => ! $faq->is_published]);

        return back()->with('status', '게시 상태를 변경했습니다.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'category' => 'required|string|max:40',
            'question' => 'required|string|max:255',
            'answer' => 'required|string',
            'sort_order' => 'nullable|integer',
        ]);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_published'] = $request->boolean('is_published');

        return $data;
    }
}
