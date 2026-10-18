<?php

namespace App\Domain\Shopping;

use App\Models\ShopKeywordAnalysis;
use App\Models\ShopKeywordAnalysisItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Shared by page and extension runners; expired work is available after 120 seconds. */
class ShopKeywordClaims
{
    public function claim(ShopKeywordAnalysis $analysis, string $runner, int $limit, array $exclude = [])
    {
        return Cache::lock('shop-claims:'.$analysis->id, 15)->block(3, function () use ($analysis, $runner, $limit, $exclude) {
            $claims = Cache::get('shop-claims:data:'.$analysis->id, []);
            $claims = array_filter($claims, fn ($c) => $c['until'] > now()->timestamp);
            $items = $analysis->combos()->whereNull('rank')->whereNotIn('id', array_merge($exclude, array_keys($claims)))->orderBy('id')->limit(max(1, min(4, $limit)))->get(['id', 'keyword']);
            $result = $items->map(function ($item) use (&$claims, $runner) {
                $token = (string) Str::uuid();
                $claims[$item->id] = ['runner' => $runner, 'token' => $token, 'until' => now()->timestamp + 120];
                return ['id' => $item->id, 'keyword' => $item->keyword, 'claim_token' => $token];
            });
            Cache::put('shop-claims:data:'.$analysis->id, $claims, 120);
            return $result;
        });
    }

    public function renew(ShopKeywordAnalysis $analysis, string $runner): void
    {
        Cache::lock('shop-claims:'.$analysis->id, 15)->block(3, function () use ($analysis, $runner) {
            $claims = Cache::get('shop-claims:data:'.$analysis->id, []);
            foreach ($claims as &$claim) {
                if ($claim['runner'] === $runner && $claim['until'] > now()->timestamp) $claim['until'] = now()->timestamp + 120;
            }
            unset($claim);
            Cache::put('shop-claims:data:'.$analysis->id, $claims, 120);
        });
    }

    public function save(ShopKeywordAnalysis $analysis, ShopKeywordAnalysisItem $item, string $runner, string $token, callable $apply)
    {
        return Cache::lock('shop-claims:'.$analysis->id, 15)->block(3, function () use ($analysis, $item, $runner, $token, $apply) {
            $claims = Cache::get('shop-claims:data:'.$analysis->id, []);
            if ($item->fresh()->rank !== null) return $apply();
            $claim = $claims[$item->id] ?? null;
            if (($claim && ($claim['until'] <= now()->timestamp || $claim['runner'] !== $runner || ! hash_equals($claim['token'], $token))) || ($token !== '' && ! $claim)) {
                return response()->json(['skipped' => true, 'claim_lost' => true], 409);
            }
            $result = $apply();
            unset($claims[$item->id]);
            Cache::put('shop-claims:data:'.$analysis->id, $claims, 120);
            return $result;
        });
    }
}
