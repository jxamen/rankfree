<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Qna;
use Illuminate\Http\Request;

/** 1:1 문의 관리 — 목록·답변 (운영자). */
class QnaController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status');
        $query = Qna::with('user')->latest();
        if (in_array($status, ['pending', 'answered'], true)) {
            $query->where('status', $status);
        }

        return view('admin.qnas.index', [
            'qnas' => $query->paginate(20)->withQueryString(),
            'status' => $status,
            'pendingCount' => Qna::where('status', 'pending')->count(),
        ]);
    }

    public function show(Qna $qna)
    {
        return view('admin.qnas.show', ['qna' => $qna->load('user', 'answerer')]);
    }

    public function answer(Request $request, Qna $qna)
    {
        $data = $request->validate(['answer' => 'required|string']);
        $qna->update([
            'answer' => $data['answer'],
            'status' => 'answered',
            'answered_at' => now(),
            'answered_by' => $request->user()->id,
        ]);

        return redirect()->route('admin.qnas.show', $qna)->with('status', '답변을 등록했습니다.');
    }

    public function destroy(Qna $qna)
    {
        $qna->delete();

        return redirect()->route('admin.qnas')->with('status', '문의를 삭제했습니다.');
    }
}
