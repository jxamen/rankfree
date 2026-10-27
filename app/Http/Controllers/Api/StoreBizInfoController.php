<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopSellerInfo;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreBizInfoController extends Controller
{
    private const FIELDS = ['biz_name', 'representative', 'biz_reg_no', 'mail_order_no', 'address', 'customer_phone', 'email'];

    public function index(Request $request)
    {
        $input = $request->validate([
            'after_store_id' => ['sometimes', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'store_id' => ['sometimes', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'stale_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $days = (int) ($input['stale_days'] ?? 30);
        $limit = (int) ($input['limit'] ?? 20);
        $cutoff = now()->subDays($days);
        // Select one product per store, and the same latest seller row the admin screen uses.
        $stores = DB::table('shop_products')->select('store_id')->selectRaw('MIN(product_key) as product_key')
            ->whereNotNull('store_id')->where('store_id', '<>', '')
            ->when(isset($input['store_id']), fn ($q) => $q->where('store_id', $input['store_id']))
            ->when(isset($input['after_store_id']), fn ($q) => $q->where('store_id', '>', $input['after_store_id']))
            ->groupBy('store_id');
        $latest = DB::table('shop_seller_infos as newest')->select('newest.id')
            ->whereColumn('newest.store_id', 'stores.store_id')
            ->orderByDesc('newest.captured_at')->orderByDesc('newest.id')->limit(1);
        $rows = DB::query()->fromSub($stores, 'stores')
            ->join('shop_products as p', 'p.product_key', '=', 'stores.product_key')
            ->leftJoin('shop_seller_infos as s', fn ($join) => $join->where('s.id', '=', $latest))
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('s.id')->orWhereNull('s.captured_at')->orWhere('s.captured_at', '<', $cutoff);
                foreach (['biz_name', 'biz_reg_no', 'representative', 'address'] as $field) {
                    $q->orWhereNull('s.'.$field)->orWhere('s.'.$field, '');
                }
            })
            ->orderBy('stores.store_id')->limit($limit + 1)
            ->get(['stores.store_id', 'p.product_key', 'p.channel_uid', 'p.channel_id', 'p.channel_no', 'p.mall_name', 'p.link',
                's.id as seller_info_id', 's.captured_at', 's.biz_name', 's.biz_reg_no', 's.representative', 's.address']);
        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit)->map(function ($row) {
            $missing = array_values(array_filter(['biz_name', 'biz_reg_no', 'representative', 'address'], fn ($f) => ! $row->$f));
            $row->reason = $missing ? 'missing' : 'stale';
            $row->missing_fields = $missing;
            $row->captured_at = $row->captured_at ? CarbonImmutable::parse($row->captured_at)->toIso8601String() : null;

            return $row;
        })->values();

        return response()->json(['data' => ['items' => $items, 'stale_days' => $days,
            'pagination' => ['limit' => $limit, 'has_more' => $hasMore,
                'next_after_store_id' => $hasMore ? $items->last()->store_id : null]]]);
    }

    public function store(Request $request)
    {
        $rules = [
            'store_id' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'source_url' => ['required', 'string', 'max:2000', 'url:https', function ($a, $v, $fail) {
                $parts = parse_url($v);
                if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                    $fail('Use a public HTTPS source URL without credentials, query or fragment.');
                }
            }],
            'observed_at' => ['required', 'date', 'before_or_equal:now', 'regex:/T[0-9:]+(?:Z|[+-][0-9]{2}:[0-9]{2})$/'],
            'public_business_info' => ['required', 'accepted'],
            'biz_name' => ['required', 'string', 'max:200'],
            'representative' => ['required', 'string', 'max:120'],
            'biz_reg_no' => ['required', 'string', 'regex:/^(?:[0-9]{10}|[0-9]{3}-[0-9]{2}-[0-9]{5})$/'],
            'mail_order_no' => ['sometimes', 'required', 'string', 'max:80'],
            'address' => ['required', 'string', 'max:1000'],
            'customer_phone' => ['sometimes', 'required', 'string', 'max:60', 'regex:/^[+0-9() -]+$/', function ($a, $v, $fail) {
                $digits = preg_replace('/\D/', '', $v);
                if (preg_match('/^(?:0|82(?:0)?)1[016789]/', $digits) || strlen($digits) < 8) {
                    $fail('Only public business landline/customer service numbers are accepted; omit mobile numbers.');
                }
            }],
            'email' => ['sometimes', 'required', 'email:rfc', 'max:190'],
        ];
        $data = $request->validate($rules);
        if (array_diff(array_keys($request->all()), array_keys($rules))) {
            throw ValidationException::withMessages(['payload' => 'Unknown fields are not accepted. Do not send raw pages or private data.']);
        }
        $fields = array_intersect_key($data, array_flip(self::FIELDS));
        if (! $fields) {
            throw ValidationException::withMessages(['payload' => 'At least one business field is required.']);
        }
        foreach ($fields as $key => $value) {
            $fields[$key] = trim($value);
        }
        if (isset($fields['biz_reg_no'])) {
            $fields['biz_reg_no'] = str_replace('-', '', $fields['biz_reg_no']);
        }
        $observed = CarbonImmutable::parse($data['observed_at'])->setTimezone(config('app.timezone'))->startOfSecond();

        return DB::transaction(function () use ($request, $data, $fields, $observed) {
            // Every API writer locks the same existing product, including first insert.
            $product = DB::table('shop_products')->where('store_id', $data['store_id'])
                ->orderBy('product_key')->lockForUpdate()->first();
            if (! $product) {
                return response()->json(['code' => 'unknown_store', 'message' => 'Store not found in collected products.'], 404);
            }
            $row = ShopSellerInfo::where('store_id', $product->store_id)
                ->orderByDesc('captured_at')->orderByDesc('id')->lockForUpdate()->first();
            $created = ! $row;
            if (! $row) {
                if (! isset($fields['biz_name'], $fields['biz_reg_no'])) {
                    throw ValidationException::withMessages(['payload' => 'A new seller record requires biz_name and biz_reg_no.']);
                }
                // Legacy schema requires a channel UID; this internal key is NOT a Naver channel UID.
                $uid = $product->channel_uid ?: 'store:'.$product->store_id;
                if (ShopSellerInfo::where('channel_uid', $uid)->exists()) {
                    return response()->json(['code' => 'store_identity_conflict', 'message' => 'Existing channel belongs to a different store; review mapping.'], 409);
                }
                $row = new ShopSellerInfo(['store_id' => $product->store_id, 'channel_uid' => $uid, 'channel_id' => $product->channel_id]);
            }
            $changes = [];
            foreach ($fields as $key => $value) {
                $old = $row->$key;
                if ($key === 'biz_reg_no' && $old !== null) {
                    $old = str_replace('-', '', $old);
                }
                if ($old !== $value) {
                    $changes[$key] = ['old' => $row->$key, 'new' => $value];
                }
            }
            if ($row->captured_at && ($observed->lt($row->captured_at) || ($observed->eq($row->captured_at) && $changes))) {
                return response()->json(['code' => 'stale_observation', 'message' => 'Observation predates the current record or conflicts at the same timestamp.'], 409);
            }
            foreach ($changes as $key => $change) {
                $row->$key = $change['new'];
            }
            if ($created || ! $row->captured_at || $observed->gt($row->captured_at)) {
                $row->captured_at = $observed;
                $row->seller_info_url = $data['source_url'];
            }
            if ($changes) {
                $row->user_id = $request->user()->id;
            }
            if ($row->isDirty()) {
                $row->save();
            }
            if ($changes) {
                DB::table('shop_seller_info_changes')->insert([
                    'shop_seller_info_id' => $row->id, 'store_id' => $row->store_id,
                    'user_id' => $request->user()->id, 'api_key_id' => $request->attributes->get('api_key')->id,
                    'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'source_url' => $data['source_url'], 'observed_at' => $observed, 'created_at' => now(),
                ]);
            }

            return response()->json(['data' => ['store_id' => $row->store_id, 'seller_info_id' => $row->id,
                'status' => $created ? 'created' : ($changes ? 'updated' : 'unchanged'),
                'changed_fields' => array_keys($changes), 'captured_at' => $row->captured_at->toIso8601String()]]);
        }, 3);
    }
}
