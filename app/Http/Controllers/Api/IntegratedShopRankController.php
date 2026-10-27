<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shopping\IntegratedShopCollector;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IntegratedShopRankController extends Controller
{
    public function __invoke(Request $request, IntegratedShopCollector $collector)
    {
        $input = $request->validate([
            'keywords' => ['required', 'array', 'min:1', 'max:5'],
            'keywords.*' => ['required', 'string', 'max:100', 'distinct'],
            'target' => ['required', 'array:type,value'],
            'target.type' => ['required', Rule::in(['nvMid', 'mallName', 'mallId', 'channelNo', 'channelProductId'])],
            'target.value' => ['required', 'string', 'max:200'],
            'top' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'rank_by' => ['sometimes', Rule::in(['rank', 'organic_rank'])],
        ]);
        // 모바일 통합검색만 수집한다(대표님 10-27 16:52 「PC 를 할 필요 없어. 모바일만 해」). 순위는 광고를 뺀 순위(organic_rank)가 기본이다(「광고는 제외하고 체크」).
        $rankBy = $input['rank_by'] ?? 'organic_rank';
        $results = [];
        $errors = [];
        $stopped = null;
        foreach (array_unique(array_map('trim', $input['keywords'])) as $keyword) {
            $start = microtime(true);
            $data = $stopped ? ['status' => $stopped, 'area_code' => null, 'items' => [], 'cached' => false, 'fetched_at' => null] : $collector->collect($keyword, 'mobile');
            if (in_array($data['status'], ['blocked', 'busy'], true)) {
                $stopped = $data['status'];
            }
            $matches = array_values(array_filter($data['items'], fn ($item) => ($item[$input['target']['type']] ?? null) === trim($input['target']['value'])));
            $success = in_array($data['status'], ['ok', 'area_absent'], true);
            $data['received_count'] = $success ? count($data['items']) : null;
            $data['organic_received_count'] = $success ? count(array_filter($data['items'], fn ($item) => $item['organic_rank'] !== null)) : null;
            $data['matches'] = $matches;
            $data['rank'] = $matches[0]['rank'] ?? null;
            $organic = array_column(array_filter($matches, fn ($m) => $m['organic_rank'] !== null), 'organic_rank');
            $data['organic_rank'] = $organic ? min($organic) : null;
            $data['is_ad'] = $matches[0]['is_ad'] ?? null;
            $rank = $data[$rankBy];
            // 고른 기준(rank_by)으로 본 상태 — 광고 제외 기준에서 광고로만 나온 대상은 ad_only(순위 없음)
            $data['rank_status'] = ! $success ? 'collection_failed' : ($rank !== null ? 'found' : ($matches ? 'ad_only' : 'outside'));
            $total = $rankBy === 'organic_rank' ? $data['organic_received_count'] : $data['received_count'];
            $data['rank_label'] = ! $success ? null : ($rank !== null ? $rank.'위' : ($matches ? '광고 노출만' : $total.'위 밖'));
            if (! $success) {
                $errors[] = ['keyword' => $keyword, 'device' => 'mobile', 'status' => $data['status']];
            }
            $row = ['keyword' => $keyword, 'mobile' => $data, 'elapsed_ms' => (int) round((microtime(true) - $start) * 1000)];
            if (! isset($input['top']) || ($rank !== null && $rank <= $input['top'])) {
                $results[] = $row;
            }
        }

        return response()->json(['data' => $results, 'errors' => $errors, 'rank_by' => $rankBy, 'cache_ttl_seconds' => 600]);
    }
}
