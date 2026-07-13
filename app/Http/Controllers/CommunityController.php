<?php

namespace App\Http\Controllers;

use App\Models\CommunityCategory;
use App\Models\CommunityComment;
use App\Models\CommunityLike;
use App\Models\CommunityPost;
use Illuminate\Http\Request;

/**
 * 공개 커뮤니티 — 비로그인 열람, 로그인 시 글/댓글/좋아요 작성.
 * 페르소나 자동 활동과 실사용자 활동이 함께 섞인다.
 */
class CommunityController extends Controller
{
    /** 목록 — 카테고리 필터 + 최신순. */
    public function index(Request $request)
    {
        $slug = $request->query('cat');
        $category = $slug ? CommunityCategory::where('slug', $slug)->where('is_active', true)->first() : null;

        $posts = CommunityPost::with(['persona', 'user', 'category'])
            ->when($category, fn ($q) => $q->where('category_id', $category->id))
            ->orderByDesc('is_pinned')->latest('id')
            ->paginate(20)->withQueryString();

        return view('community.index', [
            'categories' => CommunityCategory::where('is_active', true)->orderBy('sort_order')->withCount('posts')->get(),
            'category' => $category,
            'posts' => $posts,
            'totalPosts' => CommunityPost::count(),
        ]);
    }

    /** 글쓰기 폼(로그인 필요). */
    public function create(Request $request)
    {
        return view('community.create', [
            'categories' => CommunityCategory::where('is_active', true)->orderBy('sort_order')->get(),
            'selected' => $request->query('cat'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'category_id' => 'required|exists:community_categories,id',
            'title' => 'required|string|max:150',
            'body' => 'required|string|max:20000',
        ]);
        $post = CommunityPost::create($data + [
            'author_type' => 'user',
            'user_id' => $request->user()->id,
        ]);

        return redirect()->route('community.show', $post)->with('status', '글을 등록했습니다.');
    }

    /** 글 수정 폼 — 본인 글 또는 운영자. 운영자는 카테고리 이동(변경) 가능. */
    public function edit(Request $request, CommunityPost $post)
    {
        abort_unless($this->canManagePost($post, $request->user()), 403);

        return view('community.edit', [
            'post' => $post,
            'categories' => CommunityCategory::where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }

    /** 글 수정 저장 — 본인 글 또는 운영자. category_id 변경 = 카테고리 이동. */
    public function update(Request $request, CommunityPost $post)
    {
        abort_unless($this->canManagePost($post, $request->user()), 403);
        $data = $request->validate([
            'category_id' => 'required|exists:community_categories,id',
            'title' => 'required|string|max:150',
            'body' => 'required|string|max:20000',
        ]);
        $post->update($data);

        return redirect()->route('community.show', $post)->with('status', '글을 수정했습니다.');
    }

    /** 상세 + 댓글. 조회수 증가. */
    public function show(Request $request, CommunityPost $post)
    {
        $post->increment('views');
        $post->load(['persona', 'user', 'category']);

        $comments = $post->comments()->with(['persona', 'user', 'replies.persona', 'replies.user'])
            ->whereNull('parent_id')->orderBy('id')->get();

        // 로그인 사용자가 좋아요한 글 여부
        $liked = false;
        if ($request->user()) {
            $liked = CommunityLike::where(['likeable_type' => 'post', 'likeable_id' => $post->id, 'liker_type' => 'user', 'liker_id' => $request->user()->id])->exists();
        }

        return view('community.show', compact('post', 'comments', 'liked'));
    }

    /** 댓글 작성(로그인 필요). */
    public function comment(Request $request, CommunityPost $post)
    {
        $data = $request->validate([
            'body' => 'required|string|max:2000',
            'parent_id' => 'nullable|exists:community_comments,id',
        ]);
        CommunityComment::create([
            'post_id' => $post->id,
            'parent_id' => $data['parent_id'] ?? null,
            'author_type' => 'user',
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);
        $post->increment('comments_count');

        return back()->with('status', '댓글을 등록했습니다.');
    }

    /** 댓글 수정 — 본인 댓글 또는 운영자. */
    public function commentUpdate(Request $request, CommunityComment $comment)
    {
        abort_unless($this->canManageComment($comment, $request->user()), 403);
        $data = $request->validate(['body' => 'required|string|max:2000']);
        $comment->update(['body' => $data['body']]);

        return back()->with('status', '댓글을 수정했습니다.');
    }

    /** 댓글 삭제 — 본인 댓글 또는 운영자. 부모 댓글 삭제 시 대댓글까지 함께 삭제. */
    public function commentDestroy(Request $request, CommunityComment $comment)
    {
        abort_unless($this->canManageComment($comment, $request->user()), 403);
        $post = $comment->post;
        $removed = 1;
        if ($comment->parent_id === null) {
            $replies = $comment->replies()->get();
            $removed += $replies->count();
            foreach ($replies as $reply) {
                $reply->delete();
            }
        }
        $comment->delete();
        if ($post) {
            $post->decrement('comments_count', min($removed, $post->comments_count));
        }

        return back()->with('status', '댓글을 삭제했습니다.');
    }

    /** 좋아요 토글(AJAX, 로그인 필요). */
    public function like(Request $request, CommunityPost $post)
    {
        $key = ['likeable_type' => 'post', 'likeable_id' => $post->id, 'liker_type' => 'user', 'liker_id' => $request->user()->id];
        $existing = CommunityLike::where($key)->first();
        if ($existing) {
            $existing->delete();
            $post->decrement('likes_count');
            $liked = false;
        } else {
            CommunityLike::create($key);
            $post->increment('likes_count');
            $liked = true;
        }

        return response()->json(['liked' => $liked, 'count' => $post->fresh()->likes_count]);
    }

    /** 글 삭제 — 본인 글 또는 운영자. */
    public function destroy(Request $request, CommunityPost $post)
    {
        abort_unless($this->canManagePost($post, $request->user()), 403);
        $post->delete();

        return redirect()->route('community')->with('status', '글을 삭제했습니다.');
    }

    /** 글 관리 권한 — 본인이 쓴 글이거나 운영자(전체 글 수정·삭제·이동). */
    private function canManagePost(CommunityPost $post, $user): bool
    {
        return $user && ($user->isOperator() || ($post->author_type === 'user' && $post->user_id === $user->id));
    }

    /** 댓글 관리 권한 — 본인 댓글이거나 운영자. */
    private function canManageComment(CommunityComment $comment, $user): bool
    {
        return $user && ($user->isOperator() || ($comment->author_type === 'user' && $comment->user_id === $user->id));
    }
}
