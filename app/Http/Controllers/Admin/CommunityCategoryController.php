<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommunityCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** 커뮤니티 카테고리 관리 — 추가·이름/아이콘/설명/정렬·사용 여부. 목록 페이지 인라인 CRUD. */
class CommunityCategoryController extends Controller
{
    public function index()
    {
        return view('admin.community-categories.index', [
            'categories' => CommunityCategory::withCount('posts')->orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'slug' => 'nullable|string|max:40|alpha_dash',
            'icon' => 'nullable|string|max:16',
            'description' => 'nullable|string|max:200',
        ]);

        CommunityCategory::create([
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($data['slug'] ?? null, $data['name']),
            'icon' => $data['icon'] ?? null,
            'description' => $data['description'] ?? null,
            'sort_order' => (int) (CommunityCategory::max('sort_order') + 1),
            'is_active' => true,
        ]);

        return back()->with('status', '카테고리를 추가했습니다.');
    }

    public function update(Request $request, CommunityCategory $category)
    {
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'slug' => 'nullable|string|max:40|alpha_dash',
            'icon' => 'nullable|string|max:16',
            'description' => 'nullable|string|max:200',
            'sort_order' => 'nullable|integer|min:0|max:9999',
        ]);

        $category->update([
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($data['slug'] ?? null, $data['name'], $category->id),
            'icon' => $data['icon'] ?? null,
            'description' => $data['description'] ?? null,
            'sort_order' => $data['sort_order'] ?? $category->sort_order,
        ]);

        return back()->with('status', '카테고리를 수정했습니다.');
    }

    public function toggle(CommunityCategory $category)
    {
        $category->update(['is_active' => ! $category->is_active]);

        return back()->with('status', '카테고리 사용 상태를 변경했습니다.');
    }

    public function destroy(CommunityCategory $category)
    {
        // category_id는 cascadeOnDelete — 글이 있으면 함께 삭제되므로 방지(먼저 이동·삭제 유도)
        if ($category->posts()->exists()) {
            return back()->with('status', '이 카테고리에 글이 있어 삭제할 수 없습니다. 글을 다른 카테고리로 옮기거나 삭제한 뒤 다시 시도하세요.');
        }
        $category->delete();

        return back()->with('status', '카테고리를 삭제했습니다.');
    }

    /** slug 정규화 + 중복 회피. 한글 이름 등으로 비면 'cat-N'로 대체. */
    private function uniqueSlug(?string $slug, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($slug ?: $name);
        if ($base === '') {
            $base = 'cat-'.((int) CommunityCategory::max('id') + 1);
        }
        $candidate = $base;
        $i = 1;
        while (CommunityCategory::where('slug', $candidate)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $candidate = $base.'-'.(++$i);
        }

        return $candidate;
    }
}
