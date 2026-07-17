<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KeywordCategory;
use App\Models\KeywordSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * 키워드 인사이트 검색창 자동완성(공개, 22) — 발행 문서(origin=hub) + 카테고리 제안.
 * 접두 매칭 우선(인덱스 활용) → 부족분만 부분 일치로 채운다. 5분 캐시·throttle.
 * ⚠️ origin='hub' 강제 — 사용자 검색 내역(origin=user) 노출 금지(21 비공개 원칙).
 */
class KeywordSuggestController extends Controller
{
    private const MIN = 2;

    private const LIMIT = 8;

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $type = in_array($request->query('type'), ['place', 'shopping'], true) ? $request->query('type') : null;

        $data = mb_strlen($q, 'UTF-8') < self::MIN
            ? ['keywords' => [], 'categories' => []]
            : Cache::remember('kw:sug:v1:'.($type ?? 'all').':'.md5($q), now()->addMinutes(5), fn () => $this->build($q, $type));

        // JSON 이라 meta robots 를 못 쓴다 — 헤더로 색인 차단
        return response()->json($data)->header('X-Robots-Tag', 'noindex');
    }

    private function build(string $q, ?string $type): array
    {
        $esc = addcslashes($q, '\\%_');
        $base = fn () => KeywordSearch::where('origin', 'hub')
            ->when($type, fn ($x) => $x->whereHas('category', fn ($c) => $c->where('type', $type)))
            ->with('category:id,type');

        // 접두 일치 우선(인덱스) → 모자라면 부분 일치로 보충
        $hits = $base()->where('keyword', 'like', $esc.'%')->orderByDesc('monthly_total')->limit(self::LIMIT)->get();
        if ($hits->count() < self::LIMIT) {
            $more = $base()->where('keyword', 'like', '%'.$esc.'%')
                ->whereNotIn('id', $hits->pluck('id'))
                ->orderByDesc('monthly_total')->limit(self::LIMIT - $hits->count())->get();
            $hits = $hits->concat($more);
        }

        return [
            'keywords' => $hits->map(fn ($d) => [
                'keyword' => $d->keyword,
                'url' => $d->shareUrl(),
                'total' => (int) $d->monthly_total,
                'type' => $d->category?->type,
            ])->values()->all(),
            'categories' => KeywordCategory::where('is_active', true)
                ->when($type, fn ($x) => $x->where('type', $type))
                ->where('name', 'like', '%'.$esc.'%')
                ->withCount(['searches as docs_count' => fn ($x) => $x->where('origin', 'hub')])
                ->limit(3)->get()
                ->map(fn ($c) => [
                    'name' => $c->name,
                    'url' => route('keywords.category', $c->slug),
                    'docs' => (int) $c->docs_count,
                    'type' => $c->type,
                ])->values()->all(),
        ];
    }
}
