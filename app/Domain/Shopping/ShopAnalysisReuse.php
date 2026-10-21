<?php

namespace App\Domain\Shopping;

use App\Models\ShopKeywordAnalysis;
use App\Models\ShopProductInfo;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ShopAnalysisReuse
{
    public static function canonicalUrl(string $url): string
    {
        $parts = parse_url(preg_match('#^https?://#i', trim($url)) ? trim($url) : 'https://'.trim($url));
        if (!$parts || empty($parts['host'])) return '';
        $host = strtolower($parts['host']);
        $host = preg_replace('/^www\./', '', $host);
        $host = preg_replace('/^m\.(smartstore|brand)\.naver\.com$/', '$1.naver.com', $host);
        $query = [];
        foreach (explode('&', $parts['query'] ?? '') as $part) {
            if ($part === '') continue;
            [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
            $name = urldecode($name);
            if (preg_match('/^(utm_.+|napm|n_media|n_query|n_rank|n_ad_group|n_ad|n_keyword_id|n_keyword|n_campaign_type|n_ad_group_type|n_match|nt_source|nt_medium|nt_detail|fbclid|gclid)$/i', $name)) continue;
            $query[] = rawurlencode($name).'='.rawurlencode(urldecode($value));
        }
        sort($query, SORT_STRING);
        $port = isset($parts['port']) && !in_array($parts['port'], [80, 443]) ? ':'.$parts['port'] : '';
        return $host.$port.rtrim($parts['path'] ?? '', '/').($query ? '?'.implode('&', $query) : '');
    }

    public function forOrder(User $user, string $order): ?ShopKeywordAnalysis
    {
        return ShopKeywordAnalysis::where('user_id', $user->id)
            ->where('product_collection->_boost->source_order', $order)->latest('id')->first();
    }

    public function copyLatest(User $user, string $url, string $core, string $order, array $orderMeta): ?ShopKeywordAnalysis
    {
        $canonical = self::canonicalUrl($url);
        if ($canonical === '') return null;
        $source = ShopKeywordAnalysis::where('user_id', $user->id)
            ->whereIn('status', ['done', 'target_reached', 'ad_ended'])
            ->whereNotNull('product_url')->where('product_title', '!=', '')
            ->latest('id')->cursor()->first(fn ($a) => self::canonicalUrl($a->product_url) === $canonical);
        if (!$source) return null;

        return DB::transaction(function () use ($source, $url, $core, $order, $orderMeta) {
            $source = ShopKeywordAnalysis::whereKey($source->id)->lockForUpdate()->firstOrFail();
            $copy = $source->replicate();
            $copy->fill(array_merge($orderMeta, ['marketing_order_id' => null, 'core_keyword' => $core,
                'product_url' => $url, 'status' => 'done', 'created_via' => 'api',
                'product_collection' => ['state' => 'completed'], 'check_method' => 'search']));
            $pi = ShopProductInfo::where('user_id', $source->user_id)->where('channel_product_id', $source->product_id)->first();
            $snapshot = $source->boostMeta()['product_snapshot'] ?? [
                'title' => $pi?->title ?: $source->product_title, 'brand' => $pi?->brand ?: $source->brand,
                'mall_name' => $pi?->mall_name ?: $source->mall_name, 'price' => $pi?->price ?? $source->product_price,
                'delivery_fee' => $pi?->delivery_fee, 'category' => $pi?->category,
                'thumbnail_url' => $pi?->thumbnail_url, 'seller_tags' => $pi?->seller_tags ?? [],
                'collected_at' => $pi?->collected_at?->toIso8601String(),
            ];
            $copy->setBoostMeta(['source_order' => $order, 'reused_from' => $source->id,
                'reused_at' => now()->toIso8601String(), 'source_date' => $source->boostMeta()['source_date'] ?? $source->created_at->toDateString(),
                'product_snapshot' => $snapshot]);
            // No collector lease or IP state is inherited by another order.
            $copy->forceFill(['collection_seen_at' => null, 'collection_ip' => null, 'collection_source' => null]);
            $copy->save();
            foreach ($source->items()->where(fn ($q) => $q->where('kind', 'token')->orWhereNotNull('rank'))->get() as $item) {
                $new = $item->replicate();
                $new->analysis_id = $copy->id;
                $new->save();
            }
            $copy->update(['token_count' => $copy->tokens()->count(), 'combo_count' => $copy->combos()->count(),
                'checked_count' => $copy->combos()->count(), 'exposed_count' => $copy->exposed()->count()]);
            // Short links are intentionally independent and created for this order later.
            return $copy;
        });
    }
}
