<?php

namespace App\Domain\Shopping;

use App\Domain\Order\OrderFieldAutofill;
use App\Models\ShopKeywordAnalysis;
use App\Models\ShopKeywordShortLink;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Short URL 생성·재배정 단일 소스(2026-07-26) — 관리자 화면과 외부 API(28)가 같은 규칙을 쓴다.
 * 규칙을 고칠 땐 여기만 고친다(컨트롤러에 로직을 넣지 말 것).
 */
class ShopKeywordShortLinkService
{
    /** 참고 키워드(acq) 소스 — 링크가 회전할 때 함께 실리는 연관 키워드 풀. */
    public const REFERENCE_SOURCES = ['autocomplete', 'searchad', 'shopping_related', 'keyword_rec', 'together', 'competitor_brand'];

    /**
     * 노출 키워드를 그룹 수만큼 나눠 Short URL 을 새로 만든다(호출된 링크도 전부 교체).
     *
     * @throws DomainException 노출 키워드 없음 · 그룹 수 초과 · 그룹 수 범위 오류
     */
    public function generate(ShopKeywordAnalysis $analysis, ?int $groupCount): Collection
    {
        $links = DB::transaction(function () use ($analysis, $groupCount) {
            $analysis = ShopKeywordAnalysis::whereKey($analysis->id)->lockForUpdate()->firstOrFail();
            $old = $analysis->shortLinks()->orderBy('group_no')->orderBy('id')->lockForUpdate()->get();
            // Null is used only by the list action: take the current count under lock.
            $groupCount ??= $old->count();
            $keywords = $this->exposedKeywords($analysis);
            $this->validateCount($keywords, $groupCount);
            $groups = $this->keywordGroups($keywords, $groupCount, $old->pluck('keywords')->all());
            $references = $this->referenceKeywords($analysis);
            $domains = $this->secondaryDomains();
            $tokens = [];
            for ($i = 0; $i < $groupCount; $i++) {
                do { $token = $this->newToken(); } while (in_array($token, $tokens, true));
                $tokens[] = $token;
            }
            // Generate tokens while old rows still exist, so none can be reused.
            $analysis->shortLinks()->delete();
            foreach ($tokens as $idx => $token) {
                ShopKeywordShortLink::create(['analysis_id' => $analysis->id, 'token' => $token,
                    'domain' => $domains !== [] ? $domains[$idx % count($domains)] : null,
                    'group_no' => $idx + 1, 'group_count' => $groupCount,
                    'keywords' => $groups[$idx], 'reference_keywords' => $references]);
            }
            return $analysis->shortLinks()->orderBy('group_no')->get();
        });
        app(OrderFieldAutofill::class)->fillFromAnalysis($analysis->fresh());
        return $links;
    }

    /**
     * 재배정 — 이미 배포한 URL(토큰·도메인)은 그대로 두고 키워드만 다시 나눈다.
     * 순위 확인이 더 진행돼 노출 키워드가 늘었을 때 쓴다(링크 수를 늘리려면 generate — 모든 주소를 교체한다).
     *
     * @throws DomainException 링크 없음 · 노출 키워드 없음 · 링크 수 > 키워드 수
     */
    public function reassign(ShopKeywordAnalysis $analysis): Collection
    {
        return DB::transaction(function () use ($analysis) {
            $analysis = ShopKeywordAnalysis::whereKey($analysis->id)->lockForUpdate()->firstOrFail();
            $links = $analysis->shortLinks()->orderBy('group_no')->orderBy('id')->lockForUpdate()->get();
            if ($links->isEmpty()) {
                throw new DomainException('재배정할 Short URL이 없습니다. 먼저 Short URL을 생성하세요.');
            }
            $keywords = $this->exposedKeywords($analysis);
            $this->validateCount($keywords, $links->count());
            $groups = $this->keywordGroups($keywords, $links->count(), $links->pluck('keywords')->all());
            $references = $this->referenceKeywords($analysis);
            $analysis->shortLinks()->update(['keywords' => '[]', 'cursor' => 0]);
            foreach ($links->values() as $idx => $link) {
                // A group can randomly remain equal: reload the cleared DB value
                // so Eloquent does not omit it as an unchanged attribute.
                $link->refresh();
                $link->update(['group_no' => $idx + 1, 'group_count' => $links->count(),
                    'keywords' => $groups[$idx], 'reference_keywords' => $references, 'cursor' => 0]);
            }
            return $analysis->shortLinks()->orderBy('group_no')->get();
        });
    }

    private function validateCount(array $keywords, int $count): void
    {
        if ($keywords === []) throw new DomainException('상위 5위 노출 키워드가 아직 없습니다.');
        if ($count < 1 || $count > 100 || $count > count($keywords)) {
            throw new DomainException('Short URL 개수는 1~100개이며 상위 5위 키워드 수 이하여야 합니다.');
        }
    }

    /** 노출 판정(1~threshold 위) 키워드 — 확인 순서 유지, 중복 제거. */
    public function exposedKeywords(ShopKeywordAnalysis $analysis): array
    {
        $th = 5;

        return $analysis->combos()
            ->whereBetween('rank', [1, $th])
            ->orderBy('checked_at')
            ->orderBy('id')
            ->pluck('keyword')
            ->map(fn ($keyword) => trim((string) $keyword))
            ->filter()
            ->unique()
            ->take($analysis->keyword_target ?: PHP_INT_MAX)
            ->values()
            ->all();
    }

    /** 그룹 분배 — 라운드로빈(인덱스 modulo)으로 고르게 섞는다. */
    public function keywordGroups(array $keywords, int $groupCount, array $previous = []): array
    {
        $this->validateCount($keywords, $groupCount);
        shuffle($keywords);
        $distribute = static function (array $words) use ($groupCount) {
            $groups = array_fill(0, $groupCount, []);
            foreach ($words as $idx => $keyword) $groups[$idx % $groupCount][] = $keyword;
            return $groups;
        };
        $groups = $distribute($keywords);
        // A random draw can equal the old assignment. Make the requested change
        // observable whenever there is more than one distinct keyword.
        if ($groups === $previous && count($keywords) > 1) {
            $keywords[] = array_shift($keywords);
            $groups = $distribute($keywords);
        }
        return $groups;
    }

    /** 참고 키워드 — 없으면 전체 토큰, 그래도 없으면 핵심 키워드. */
    public function referenceKeywords(ShopKeywordAnalysis $analysis): array
    {
        $refs = $analysis->tokens()
            ->whereIn('source', self::REFERENCE_SOURCES)
            ->orderBy('id')
            ->pluck('keyword')
            ->map(fn ($keyword) => trim((string) $keyword))
            ->filter()->unique()->values()->all();

        if ($refs !== []) {
            return $refs;
        }

        return $analysis->tokens()
            ->orderBy('id')
            ->pluck('keyword')
            ->map(fn ($keyword) => trim((string) $keyword))
            ->filter()->unique()->values()->all() ?: [$analysis->core_keyword];
    }

    public function newToken(): string
    {
        do {
            $token = Str::random(11);
        } while (ShopKeywordShortLink::where('token', $token)->exists());

        return $token;
    }

    public function secondaryDomains(): array
    {
        return array_values(array_filter(array_map(
            fn ($domain) => trim((string) $domain),
            (array) config('rankfree.secondary_domains', []),
        )));
    }
}
