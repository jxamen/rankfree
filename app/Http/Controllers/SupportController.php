<?php

namespace App\Http\Controllers;

use App\Models\Faq;
use App\Models\Notice;
use App\Models\Qna;
use Illuminate\Http\Request;

/** 콘솔 고객센터 — 공지사항 열람 / FAQ 검색 / 1:1 문의. */
class SupportController extends Controller
{
    public function notices()
    {
        return view('console.support.notices', [
            'notices' => Notice::visible()->listed()->paginate(15),
        ]);
    }

    public function notice(Notice $notice)
    {
        abort_unless($notice->is_published, 404);
        $notice->increment('views');

        return view('console.support.notice', [
            'notice' => $notice,
            'prev' => Notice::visible()->where('id', '<', $notice->id)->orderByDesc('id')->first(),
            'next' => Notice::visible()->where('id', '>', $notice->id)->orderBy('id')->first(),
        ]);
    }

    public function faq(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $cat = $request->query('cat');

        $query = Faq::published()->sorted();
        if ($q !== '') {
            $query->where(fn ($w) => $w->where('question', 'like', "%{$q}%")->orWhere('answer', 'like', "%{$q}%"));
        }
        if ($cat) {
            $query->where('category', $cat);
        }

        return view('console.support.faq', [
            'faqs' => $query->get(),
            'q' => $q,
            'cat' => $cat,
            'categories' => Faq::published()->select('category')->distinct()->orderBy('category')->pluck('category'),
            'total' => Faq::published()->count(),
        ]);
    }

    public function qnaIndex(Request $request)
    {
        return view('console.support.qna', [
            'qnas' => Qna::where('user_id', $request->user()->id)->latest()->paginate(15),
        ]);
    }

    public function qnaCreate()
    {
        return view('console.support.qna-create', ['qna' => new Qna(['category' => '서비스 이용'])]);
    }

    public function qnaStore(Request $request)
    {
        $data = $request->validate([
            'category' => 'required|string|max:40',
            'title' => 'required|string|max:255',
            'body' => 'required|string|max:5000',
        ]);
        $data['user_id'] = $request->user()->id;
        $data['is_secret'] = $request->boolean('is_secret');
        $qna = Qna::create($data);

        return redirect()->route('console.qna.show', $qna)->with('status', '문의를 등록했습니다. 답변은 등록 시 알려드립니다.');
    }

    public function qnaShow(Request $request, Qna $qna)
    {
        abort_unless($qna->user_id === $request->user()->id, 403);

        return view('console.support.qna-show', ['qna' => $qna->load('answerer')]);
    }

    public function qnaDestroy(Request $request, Qna $qna)
    {
        abort_unless($qna->user_id === $request->user()->id, 403);
        $qna->delete();

        return redirect()->route('console.qna')->with('status', '문의를 삭제했습니다.');
    }
}
