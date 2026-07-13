<?php

namespace Database\Seeders;

use App\Models\MarketingProduct;
use App\Models\ProductSubType;
use App\Models\ProductType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * self_marketing에 등록된 마케팅 상품(유형·세부유형·상품·필드그룹·동적필드)을
 * rankfree 스키마로 1:1 복제하는 더미 시더.
 * 원본 데이터는 database/seeders/data/marketing_products.json (self_marketing D1에서 추출).
 * 재실행 시 동일 title 상품을 재생성한다(idempotent).
 */
class MarketingProductSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/marketing_products.json');
        if (! is_file($path)) {
            $this->command?->warn("marketing_products.json 없음 — 건너뜀: {$path}");

            return;
        }
        $data = json_decode(file_get_contents($path), true);

        // ── 유형 ──
        foreach ($data['types'] as $t) {
            ProductType::updateOrCreate(['code' => $t['code']], [
                'name' => $t['name'],
                'description' => $t['description'] ?? null,
                'has_fulfillment' => (bool) $t['has_fulfillment'],
                'sort_order' => (int) $t['sort_order'],
                'is_active' => true,
            ]);
        }

        // ── 세부 유형 ──
        foreach ($data['sub_types'] as $s) {
            ProductSubType::updateOrCreate(
                ['product_type' => $s['product_type'], 'code' => $s['code']],
                [
                    'name' => $s['name'],
                    'description' => $s['description'] ?? null,
                    'sort_order' => (int) $s['sort_order'],
                    'is_active' => true,
                ],
            );
        }

        // ── 상품 + 필드그룹 + 동적필드 ──
        $creatorId = User::query()->orderBy('id')->value('id');

        foreach ($data['products'] as $pd) {
            DB::transaction(function () use ($pd, $creatorId) {
                // title 을 안정 키로 upsert — 재실행해도 id·order_token 유지(열린 수정 폼이 깨지지 않음)
                $product = MarketingProduct::updateOrCreate(
                    ['title' => $pd['title']],
                    [
                        'product_type' => $pd['product_type'],
                        'sub_type_code' => $pd['sub_type_code'] ?? null,
                        'description' => $pd['description'] ?? null,
                        'base_cost' => $pd['base_cost'],
                        'min_price' => $pd['min_price'],
                        'min_quantity' => $pd['min_quantity'],
                        'max_quantity' => $pd['max_quantity'],
                        'min_days' => $pd['min_days'],
                        'quantity_mode' => $pd['quantity_mode'],
                        'min_daily_quantity' => $pd['min_daily_quantity'],
                        'field_render_mode' => $pd['field_render_mode'],
                        'default_fulfillment' => $pd['default_fulfillment'],
                        'daily_cutoff_hour' => $pd['daily_cutoff_hour'],
                        'process_weekends' => (bool) $pd['process_weekends'],
                        'process_holidays' => (bool) $pd['process_holidays'],
                        'processing_lag_days' => $pd['processing_lag_days'],
                        'is_active' => (bool) $pd['is_active'],
                        'created_by' => $creatorId,
                    ],
                );

                // 필드 그룹 (code → id 매핑) — upsert 후 제거된 그룹 정리
                $groupIds = [];
                $keepGroups = [];
                foreach ($pd['groups'] as $g) {
                    $grp = $product->fieldGroups()->updateOrCreate(
                        ['code' => $g['code']],
                        ['name' => $g['name'], 'is_system' => (bool) $g['is_system'], 'sort_order' => (int) $g['sort_order']],
                    );
                    $groupIds[$g['code']] = $grp->id;
                    $keepGroups[] = $g['code'];
                }

                // 동적 필드 — field_key 를 안정 키로 upsert 후 제거된 필드 정리
                $keepKeys = [];
                foreach ($pd['fields'] as $f) {
                    $product->fields()->updateOrCreate(
                        ['field_key' => $f['field_key']],
                        [
                            'group_id' => $groupIds[$f['group_code']] ?? null,
                            'field_type' => $f['field_type'],
                            'label' => $f['label'],
                            'placeholder' => $f['placeholder'] ?? null,
                            'help_text' => $f['help_text'] ?? null,
                            'is_required' => (bool) $f['is_required'],
                            'default_value' => $f['default_value'] ?? null,
                            'options_json' => $f['options'] ?? null,
                            'condition_json' => $f['condition'] ?? null,
                            'sort_order' => (int) $f['sort_order'],
                            'is_active' => true,
                        ],
                    );
                    $keepKeys[] = $f['field_key'];
                }
                $product->fields()->whereNotIn('field_key', $keepKeys ?: ['__none__'])->delete();
                $product->fieldGroups()->whereNotIn('code', $keepGroups ?: ['__none__'])->delete();
            });
        }

        // ── 정리(prune): JSON에 없는 잔여 세부유형·상품 제거 → self_marketing 구조와 정확히 일치 ──
        $keepSubs = collect($data['sub_types'])->map(fn ($s) => $s['product_type'].'|'.$s['code'])->all();
        foreach (ProductSubType::all() as $sub) {
            if (! in_array($sub->product_type.'|'.$sub->code, $keepSubs, true)) {
                $sub->delete();
            }
        }

        $keepTitles = collect($data['products'])->pluck('title')->all();
        foreach (MarketingProduct::whereNotIn('title', $keepTitles)->get() as $stray) {
            $stray->fields()->delete();
            $stray->fieldGroups()->delete();
            $stray->delete();
        }

        $this->command?->info('마케팅 상품 복제 완료: 유형 '.count($data['types']).' · 세부유형 '.count($data['sub_types']).' · 상품 '.count($data['products']));
    }
}
