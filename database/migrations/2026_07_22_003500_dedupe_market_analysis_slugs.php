<?php

use App\Models\MarketAnalysis;
use Illuminate\Database\Migrations\Migration;

/**
 * 시장분석 슬러그 정리(21·2026-07-22 결정) — 같은 키워드는 기본 슬러그 1개만:
 * 키워드별 최신 분석이 기본 슬러그(-2 없이)를 갖고, 나머지는 슬러그 반납(비공개).
 * 구 '-2' 링크는 HasShareSlug::findByShareKey 폴백이 기본 슬러그 문서로 받아준다.
 */
return new class extends Migration
{
    public function up(): void
    {
        MarketAnalysis::whereNotNull('slug')
            ->orderBy('id')
            ->get(['id', 'keyword', 'slug'])
            ->groupBy(fn ($m) => MarketAnalysis::slugify((string) $m->keyword))
            ->each(function ($group, $base) {
                if ($base === '') {
                    return;
                }
                $latest = $group->sortByDesc('id')->first();
                $others = $group->where('id', '!=', $latest->id)->pluck('id');
                if ($others->isNotEmpty()) {
                    MarketAnalysis::whereIn('id', $others)->update(['slug' => null]);
                }
                if ($latest->slug !== $base) {
                    // 다른 키워드가 이미 base 를 물고 있는 극단 케이스 방지(자기 계열은 위에서 반납됨)
                    MarketAnalysis::where('slug', $base)->where('id', '!=', $latest->id)->update(['slug' => null]);
                    MarketAnalysis::whereKey($latest->id)->update(['slug' => $base]);
                }
            });
    }

    public function down(): void
    {
        // 반납된 슬러그는 복원하지 않는다(공개 URL 정책 변경) — 필요 시 shareSlug() 호출로 재발급
    }
};
