<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shopping\ShopSerpStore;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class KeywordProductsController extends Controller
{
    public function index(Request $request, ShopSerpStore $store)
    {
        $input = $request->validate([
            'keyword' => ['required', 'string', 'max:120'],
            'month' => ['nullable', 'date_format:Ym'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $keyword = trim($input['keyword']);
        $month = isset($input['month']) ? (int) $input['month'] : $store->latestMonth($keyword);
        $page = (int) ($input['page'] ?? 1);
        $limit = (int) ($input['limit'] ?? 20);
        $query = $store->itemsQuery($keyword, $month ?? 0);
        $products = (clone $query)->paginate($limit, ['*'], 'page', $page);
        $collectedAt = (clone $query)->reorder()->max('r.collected_at');

        return response()->json(['data' => [
            'keyword' => $keyword,
            'status' => $products->total() > 0 ? 'collected' : 'not_collected',
            'month' => $month,
            'available_months' => array_map('intval', $store->months($keyword)),
            'collected_at' => $collectedAt,
            'items' => $products->items(),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $products->total(),
                'last_page' => $products->lastPage(),
                'has_more' => $products->hasMorePages(),
            ],
        ]]);
    }
}
