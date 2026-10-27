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
        $results = [];
        $errors = [];
        $stopped = null;
        foreach (array_unique(array_map('trim', $input['keywords'])) as $keyword) {
            $start = microtime(true);
            $row = ['keyword' => $keyword];
            $qualifies = false;
            foreach (['pc', 'mobile'] as $device) {
                $data = $stopped ? ['status' => $stopped, 'area_code' => null, 'items' => [], 'cached' => false, 'fetched_at' => null] : $collector->collect($keyword, $device);
                if (in_array($data['status'], ['blocked', 'busy'], true)) {
                    $stopped = $data['status'];
                }
                $matches = array_values(array_filter($data['items'], fn ($item) => ($item[$input['target']['type']] ?? null) === trim($input['target']['value'])));
                $success = in_array($data['status'], ['ok', 'area_absent'], true);
                $data['received_count'] = $success ? count($data['items']) : null;
                $data['matches'] = $matches;
                $data['rank'] = $matches[0]['rank'] ?? null;
                $organic = array_column(array_filter($matches, fn ($m) => $m['organic_rank'] !== null), 'organic_rank');
                $data['organic_rank'] = $organic ? min($organic) : null;
                $data['is_ad'] = $matches[0]['is_ad'] ?? null;
                $rank = $data[$input['rank_by'] ?? 'rank'];
                $data['rank_status'] = ! $success ? 'collection_failed' : ($matches ? 'found' : 'outside');
                $data['rank_label'] = ! $success ? null : ($data['rank'] !== null ? $data['rank'].'위' : $data['received_count'].'위 밖');
                $qualifies = $qualifies || ($rank !== null && $rank <= ($input['top'] ?? PHP_INT_MAX));
                $row[$device] = $data;
                if (! $success) {
                    $errors[] = ['keyword' => $keyword, 'device' => $device, 'status' => $data['status']];
                }
            }
            $row['elapsed_ms'] = (int) round((microtime(true) - $start) * 1000);
            if (! isset($input['top']) || $qualifies) {
                $results[] = $row;
            }
        }

        return response()->json(['data' => $results, 'errors' => $errors, 'rank_by' => $input['rank_by'] ?? 'rank', 'cache_ttl_seconds' => 600]);
    }
}
