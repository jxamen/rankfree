<?php

namespace App\Domain\Shopping;

use App\Domain\Keyword\KeywordAnalysisPresenter;
use App\Domain\Keyword\NaverKeywordService;
use App\Domain\SearchAdWeb\SearchAdWebClient;
use App\Models\MarketAnalysis;
use Illuminate\Support\Facades\Cache;

/**
 * 시장분석 keyword_data 지연 보강(2026-07-22) — 허브 대량 수집 경로의 시장분석은
 * keyword_data 가 비어 '키워드 분석' 섹션이 통째로 빠진다(운영 실측: 최근 500건 중 0건 보유).
 * 발행 시점 일괄 수집은 발행 속도·세션 쿼터를 태우므로, **처음 열람될 때 1회** 서버가
 * 검색광고 기준 검색량(keywordstool)·상세(성별·연령·월별)를 채워 snapshot 에 저장한다.
 * 실패는 30분 네거티브 캐시 — 세션 장애 때 매 방문마다 크롤을 반복하지 않는다.
 */
class MarketKeywordDataEnricher
{
    public function __construct(
        private NaverKeywordService $light,
        private SearchAdWebClient $web,
    ) {}

    public function ensure(MarketAnalysis $a): MarketAnalysis
    {
        $kw = trim((string) $a->keyword);
        $snap = (array) $a->snapshot;
        $kd = (array) ($snap['keyword_data'] ?? []);
        $hasVolume = (int) ($kd['monthly_total'] ?? 0) > 0;
        $hasDetail = ! empty($kd['detail']);
        if ($kw === '' || ($hasVolume && $hasDetail)) {
            return $a;
        }

        $failKey = 'market:kd-enrich-fail:'.md5(mb_strtoupper($kw));
        if (Cache::get($failKey)) {
            return $a;
        }

        try {
            $changed = false;

            // 상세(성별·연령·월별·buckets) — KeywordReportBuilder 와 같은 6h 캐시 키 공유(중복 크롤 방지)
            $detail = null;
            if (! $hasDetail) {
                $cacheKey = 'kw:detail:'.md5(mb_strtoupper(str_replace(' ', '', $kw)));
                $detail = Cache::get($cacheKey);
                if ($detail === null) {
                    $d = $this->web->keywordDetail($kw);
                    $detail = isset($d['error']) ? null : $d;
                    if ($detail !== null) {
                        Cache::put($cacheKey, $detail, now()->addHours(6));
                    }
                }
                if ($detail) {
                    $kd['detail'] = $detail;
                    $changed = true;
                }
            }

            // 검색량·경쟁강도(keywordstool)
            if (! $hasVolume) {
                $base = $this->light->analyze($kw);
                $detailForVm = $detail ?: ($kd['detail'] ?? null);
                $vm = KeywordAnalysisPresenter::build($kw, $base, is_array($detailForVm) ? $detailForVm : null);
                if (($vm['has_volume'] ?? false) && ($vm['total'] ?? 0) > 0) {
                    $kd['monthly_total'] = (int) $vm['total'];
                    $kd['monthly_pc'] = (int) $vm['pc'];
                    $kd['monthly_mobile'] = (int) $vm['mobile'];
                    $kd['comp_idx'] = $vm['comp_idx'] ?? null;
                    $changed = true;
                }
            }

            if ($changed) {
                $snap['keyword_data'] = $kd;
                $fill = ['snapshot' => $snap];
                if (! $a->monthly_search && ($kd['monthly_total'] ?? 0) > 0) {
                    $fill['monthly_search'] = (int) $kd['monthly_total'];
                }
                if (! $a->comp_idx && ! empty($kd['comp_idx'])) {
                    $fill['comp_idx'] = (string) $kd['comp_idx'];
                }
                $a->forceFill($fill)->save();
            } else {
                Cache::put($failKey, 1, now()->addMinutes(30));
            }
        } catch (\Throwable $e) {
            Cache::put($failKey, 1, now()->addMinutes(30));
        }

        return $a;
    }
}
