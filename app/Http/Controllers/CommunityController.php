<?php

namespace App\Http\Controllers;

use App\Models\CommunityCategory;
use App\Models\CommunityComment;
use App\Models\CommunityLike;
use App\Models\CommunityPost;
use App\Models\CommunityPostAttachment;
use App\Support\HtmlSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
        ] + $this->attachmentRules($request));
        $data['body'] = HtmlSanitizer::clean($data['body']);
        $post = CommunityPost::create(collect($data)->only(['category_id', 'title', 'body'])->all() + [
            'author_type' => 'user',
            'user_id' => $request->user()->id,
        ]);
        $this->storeAttachments($request, $post);
        \Illuminate\Support\Facades\Cache::forget('sitemap:xml'); // 새 글 즉시 sitemap 반영

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
        ] + $this->attachmentRules($request, $post));
        $data['body'] = HtmlSanitizer::clean($data['body']);
        $post->update(collect($data)->only(['category_id', 'title', 'body'])->all());
        $this->storeAttachments($request, $post);

        return redirect()->route('community.show', $post)->with('status', '글을 수정했습니다.');
    }

    /** 상세 + 댓글. 조회수 증가. */
    public function show(Request $request, CommunityPost $post)
    {
        // 조회수는 updated_at 을 건드리지 않는다 — updated_at 은 실제 편집 시각(sitemap lastmod·SEO dateModified 소스)
        $post->timestamps = false;
        $post->increment('views');
        $post->timestamps = true;
        $post->load(['persona', 'user', 'category', 'attachments']);

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

    /** 첨부파일 다운로드 — 글을 볼 수 있으면 누구나(비로그인 포함). 원본 파일명으로 내려준다. */
    public function attachmentDownload(CommunityPostAttachment $attachment)
    {
        abort_unless(Storage::disk(CommunityPostAttachment::DISK)->exists($attachment->path), 404);
        $attachment->increment('download_count');

        return Storage::disk(CommunityPostAttachment::DISK)
            ->download($attachment->path, $attachment->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    /** 첨부파일 삭제 — 등록과 같은 권한(운영자). 파일 실체는 모델 이벤트가 지운다. */
    public function attachmentDestroy(Request $request, CommunityPostAttachment $attachment)
    {
        abort_unless($this->canManageAttachments($request->user()), 403);
        $attachment->delete();

        return back()->with('status', '첨부파일을 삭제했습니다.');
    }

    /** 글 삭제 — 본인 글 또는 운영자. */
    public function destroy(Request $request, CommunityPost $post)
    {
        abort_unless($this->canManagePost($post, $request->user()), 403);
        // 행은 FK cascade 로 지워지지만 파일 실체는 남는다 — 모델 이벤트를 태워 함께 정리
        $post->attachments()->get()->each->delete();
        $post->delete();
        \Illuminate\Support\Facades\Cache::forget('sitemap:xml'); // 삭제 글 sitemap 즉시 제거

        return redirect()->route('community')->with('status', '글을 삭제했습니다.');
    }

    /** 첨부 등록·삭제 권한 — 운영자만(일반 회원은 첨부를 올리지 못한다). */
    private function canManageAttachments($user): bool
    {
        return (bool) $user?->isOperator();
    }

    /** 첨부 검증 규칙 — 운영자가 아니면 파일 입력 자체를 받지 않는다. */
    private function attachmentRules(Request $request, ?CommunityPost $post = null): array
    {
        if (! $this->canManageAttachments($request->user())) {
            return [];
        }
        $remain = max(0, CommunityPostAttachment::MAX_COUNT - ($post ? $post->attachments()->count() : 0));

        return [
            'attachments' => 'nullable|array|max:'.$remain,
            'attachments.*' => 'file|max:'.CommunityPostAttachment::MAX_KB.'|extensions:'.CommunityPostAttachment::EXTENSIONS,
        ];
    }

    /** 업로드된 첨부 저장 — 운영자만. 파일명은 랜덤(원본명은 DB 에만 둔다). */
    private function storeAttachments(Request $request, CommunityPost $post): void
    {
        if (! $this->canManageAttachments($request->user()) || ! $request->hasFile('attachments')) {
            return;
        }
        foreach ($request->file('attachments') as $file) {
            $path = $file->store('community-attachments/'.$post->id, CommunityPostAttachment::DISK);
            $post->attachments()->create([
                'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 191),
                'path' => $path,
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ]);
        }
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
