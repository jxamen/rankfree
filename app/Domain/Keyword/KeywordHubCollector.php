<?php

namespace App\Domain\Keyword;

use App\Models\KeywordCandidate;
use App\Models\KeywordCategory;
use App\Models\KeywordSearch;

/**
 * 키워드 콘텐츠 허브 — 후보 수집(22_KEYWORD_CONTENT_HUB Phase 1).
 * 카테고리 시드 키워드 → 검색광고 연관키워드 + 자동완성 → keyword_candidates(pending) 적재.
 * 자동 필터: 길이(2~60자)·금지 패턴·최소 검색량(시드는 볼륨 필터 면제 — 운영자 의도 존중)·기발행 제외.
 */
class KeywordHubCollector
{
    public function __construct(
        private NaverKeywordService $light,
        private NaverAutocompleteService $ac,
    ) {}

    /** @return array{seeds:int,created:int,updated:int,filtered:int} */
    public function collect(KeywordCategory $cat): array
    {
        $stats = ['seeds' => 0, 'created' => 0, 'updated' => 0, 'filtered' => 0];

        foreach ($cat->seedList() as $seed) {
            $stats['seeds']++;

            $base = $this->light->analyze($seed);
            if ($base !== null) {
                // 시드 자신도 후보로(수집 시점 볼륨 포함)
                $this->upsert($cat, $seed, 'seed', (int) ($base['monthly_total'] ?? 0), $base['comp_idx'] ?? null, $stats);
                foreach ((array) ($base['related'] ?? []) as $r) {
                    if (! is_array($r) || ($r['keyword'] ?? '') === '') {
                        continue;
                    }
                    $this->upsert($cat, (string) $r['keyword'], 'related',
                        isset($r['monthly_total']) ? (int) $r['monthly_total'] : null,
                        isset($r['comp_idx']) ? (string) $r['comp_idx'] : null, $stats);
                }
            }

            // 자동완성 제안어 — 볼륨 미상(null)으로 pending 적재, 발행 시점 분석이 볼륨을 판정
            foreach ($this->ac->suggest($seed, 15) as $s) {
                $this->upsert($cat, (string) $s, 'autocomplete', null, null, $stats);
            }
        }

        $cat->forceFill(['collected_at' => now()])->save();

        return $stats;
    }

    private function upsert(KeywordCategory $cat, string $keyword, string $source, ?int $volume, ?string $comp, array &$stats): void
    {
        $kw = trim((string) preg_replace('/\s+/u', ' ', $keyword));
        if (! $this->passes($kw, $volume, $source === 'seed')) {
            $stats['filtered']++;

            return;
        }
        // 이미 허브로 발행된 키워드는 후보 불필요
        if (KeywordSearch::where('origin', 'hub')->where('keyword', $kw)->exists()) {
            $stats['filtered']++;

            return;
        }

        $c = KeywordCandidate::firstOrNew(['category_id' => $cat->id, 'keyword' => $kw]);
        if ($c->exists) {
            // 재수집 — 상태(승인/거부)는 건드리지 않고 수집 지표만 갱신
            $dirty = false;
            if ($volume !== null && $volume !== $c->monthly_total) {
                $c->monthly_total = $volume;
                $dirty = true;
            }
            if ($comp !== null && $comp !== $c->comp_idx) {
                $c->comp_idx = $comp;
                $dirty = true;
            }
            if ($dirty) {
                $c->save();
                $stats['updated']++;
            }

            return;
        }

        $c->fill(['source' => $source, 'monthly_total' => $volume, 'comp_idx' => $comp, 'status' => 'pending'])->save();
        $stats['created']++;
    }

    /** 자동 필터 — 볼륨 미상(null)은 통과시켜 pending 으로 두고 운영자/발행 시점에 판정한다. */
    private function passes(string $kw, ?int $volume, bool $isSeed): bool
    {
        $len = mb_strlen($kw, 'UTF-8');
        if ($len < 2 || $len > 60) {
            return false;
        }
        if (! $isSeed && $volume !== null && $volume < (int) config('rankfree.hub.min_volume', 1000)) {
            return false;
        }
        foreach ((array) config('rankfree.hub.banned_patterns', []) as $p) {
            if (@preg_match('/'.$p.'/u', $kw) === 1) {
                return false;
            }
        }

        return true;
    }
}
