<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommunityCategory;
use App\Models\CommunitySeed;
use Illuminate\Http\Request;

/** 글밥(소스) 관리 — 다른 커뮤니티에서 수집한 글감을 등록·관리. 페르소나가 소재로 변형해 사용. */
class CommunitySeedController extends Controller
{
    public function index(Request $request)
    {
        $kind = $request->query('kind', 'post');

        return view('admin.community-seeds.index', [
            'kind' => in_array($kind, ['post', 'comment'], true) ? $kind : 'post',
            'seeds' => CommunitySeed::with('category')->where('kind', $kind)->latest('id')->paginate(30)->withQueryString(),
            'categories' => CommunityCategory::orderBy('sort_order')->get(),
            'postCount' => CommunitySeed::where('kind', 'post')->count(),
            'commentCount' => CommunitySeed::where('kind', 'comment')->count(),
        ]);
    }

    /**
     * 대량 등록 — bulk 텍스트를 `---` 구분선으로 나눠 여러 글감으로 저장.
     * post: 블록 첫 줄=제목, 나머지=본문. comment: 블록 전체=본문.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'kind' => 'required|in:post,comment',
            'category_id' => 'nullable|exists:community_categories,id',
            'source' => 'nullable|string|max:80',
            'bulk' => 'required|string',
        ]);

        $blocks = preg_split('/^\s*-{3,}\s*$/m', $data['bulk']);
        $saved = 0;
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            $title = null;
            $body = $block;
            if ($data['kind'] === 'post') {
                $lines = preg_split('/\r?\n/', $block, 2);
                if (count($lines) === 2 && trim($lines[1]) !== '') {
                    $title = mb_substr(trim($lines[0]), 0, 200);
                    $body = trim($lines[1]);
                }
            }
            CommunitySeed::create([
                'kind' => $data['kind'],
                'category_id' => $data['category_id'] ?? null,
                'source' => $data['source'] ?? null,
                'title' => $title,
                'body' => $body,
                'is_active' => true,
            ]);
            $saved++;
        }

        return redirect()->route('admin.community-seeds', ['kind' => $data['kind']])
            ->with('status', "{$saved}개 글밥을 등록했습니다.");
    }

    public function toggle(CommunitySeed $seed)
    {
        $seed->update(['is_active' => ! $seed->is_active]);

        return back()->with('status', '글밥 사용 상태를 변경했습니다.');
    }

    public function destroy(CommunitySeed $seed)
    {
        $kind = $seed->kind;
        $seed->delete();

        return redirect()->route('admin.community-seeds', ['kind' => $kind])->with('status', '글밥을 삭제했습니다.');
    }
}
