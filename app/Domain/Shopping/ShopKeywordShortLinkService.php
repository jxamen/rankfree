<?php

namespace App\Domain\Shopping;

use App\Models\ShopKeywordAnalysis;
use App\Models\ShopKeywordShortLink;
use DomainException;
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
     * 노출 키워드를 그룹 수만큼 나눠 Short URL 을 새로 만든다(기존 링크는 교체).
     *
     * @throws DomainException 노출 키워드 없음 · 그룹 수 초과 · 이미 호출된 링크 존재
     */
    public function generate(ShopKeywordAnalysis $analysis, int $groupCount): \Illuminate\Support\Collection
    {
        $keywords = $this->exposedKeywords($analysis);
        if ($keywords === []) {
            throw new DomainException('상위 노출 키워드가 아직 없습니다. 순위 확인을 먼저 완료하세요.');
        }
        if ($groupCount > count($keywords)) {
            throw new DomainException('Short URL 개수는 상위 노출 키워드 수보다 많을 수 없습니다.');
        }
        // 이미 트래픽이 돈 링크는 주소를 바꾸면 안 된다(배포된 URL 이 죽는다)
        if ($analysis->shortLinks()->where('hit_count', '>', 0)->exists()) {
            throw new DomainException('이미 호출된 Short URL이 있어 다시 생성할 수 없습니다.');
        }

        $references = $this->referenceKeywords($analysis);
        $domains = $this->secondaryDomains();
        $groups = $this->keywordGroups($keywords, $groupCount);

        DB::transaction(function () use ($analysis, $references, $groupCount, $domains, $groups): void {
            $analysis->shortLinks()->delete();

            for ($groupNo = 1; $groupNo <= $groupCount; $groupNo++) {
                ShopKeywordShortLink::create([
                    'analysis_id' => $analysis->id,
                    'token' => $this->newToken(),
                    'domain' => $domains !== [] ? $domains[($groupNo - 1) % count($domains)] : null,
                    'group_no' => $groupNo,
                    'group_count' => $groupCount,
                    'keywords' => $groups[$groupNo - 1],
                    'reference_keywords' => $references,
                ]);
            }
        });

        // 연결 주문의 Short URL 자동 채움 필드 반영(빈 필드만) — 발주 전달값
        app(\App\Domain\Order\OrderFieldAutofill::class)->fillFromAnalysis($analysis->fresh());

        return $analysis->shortLinks()->orderBy('group_no')->get();
    }

    /**
     * 재배정 — 이미 배포한 URL(토큰·도메인)은 그대로 두고 키워드만 다시 나눈다.
     * 순위 확인이 더 진행돼 노출 키워드가 늘었을 때 쓴다(generate 는 호출된 링크가 있으면 막힌다).
     *
     * @throws DomainException 링크 없음 · 노출 키워드 없음 · 링크 수 > 키워드 수
     */
    public function reassign(ShopKeywordAnalysis $analysis): \Illuminate\Support\Collection
    {
        $links = $analysis->shortLinks()->orderBy('group_no')->orderBy('id')->get();
        if ($links->isEmpty()) {
            throw new DomainException('재배정할 Short URL이 없습니다. 먼저 Short URL을 생성하세요.');
        }

        $keywords = $this->exposedKeywords($analysis);
        $groupCount = $links->count();
        if ($keywords === []) {
            throw new DomainException('상위 노출 키워드가 아직 없습니다. 순위 확인을 먼저 완료하세요.');
        }
        if ($groupCount > count($keywords)) {
            throw new DomainException('Short URL 수가 상위 노출 키워드 수보다 많아 재배정할 수 없습니다.');
        }

        $references = $this->referenceKeywords($analysis);
        $groups = $this->keywordGroups($keywords, $groupCount);

        DB::transaction(function () use ($links, $references, $groupCount, $groups): void {
            foreach ($links->values() as $idx => $link) {
                $link->update([
                    'group_no' => $idx + 1,
                    'group_count' => $groupCount,
                    'keywords' => $groups[$idx],
                    'reference_keywords' => $references,
                    'cursor' => 0,   // 회전 위치 초기화 — 새 키워드 묶음의 처음부터
                ]);
            }
        });

        return $analysis->shortLinks()->orderBy('group_no')->get();
    }

    /** 노출 판정(1~threshold 위) 키워드 — 확인 순서 유지, 중복 제거. */
    public function exposedKeywords(ShopKeywordAnalysis $analysis): array
    {
        $th = (int) $analysis->threshold;

        return $analysis->combos()
            ->whereBetween('rank', [1, $th])
            ->orderBy('checked_at')
            ->orderBy('id')
            ->pluck('keyword')
            ->map(fn ($keyword) => trim((string) $keyword))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** 그룹 분배 — 라운드로빈(인덱스 modulo)으로 고르게 섞는다. */
    public function keywordGroups(array $keywords, int $groupCount): array
    {
        $groups = array_fill(0, $groupCount, []);
        foreach ($keywords as $idx => $keyword) {
            $groups[$idx % $groupCount][] = $keyword;
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
