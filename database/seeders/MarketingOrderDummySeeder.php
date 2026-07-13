<?php

namespace Database\Seeders;

use App\Models\MarketingOrder;
use App\Models\MarketingProduct;
use App\Models\User;
use Illuminate\Database\Seeder;

/** 주문 관리 페이지 확인용 더미 주문. 활성 상품별로 상태가 다른 샘플 주문을 생성한다(재실행 시 초기화 후 재생성). */
class MarketingOrderDummySeeder extends Seeder
{
    public function run(): void
    {
        // 더미(orderer_contact 에 표식)만 초기화 — 실제 주문은 보존
        MarketingOrder::where('orderer_contact', 'like', '%@dummy.test')->delete();

        $userId = User::query()->orderBy('id')->value('id');
        $statuses = ['pending', 'processing', 'completed', 'canceled'];
        $names = ['김민준', '이서연', '박지후', '최유진', '정도윤', '강하은', '조현우', '윤서아'];
        $sampleByType = [
            'TEXT' => ['강남 맛집', '역삼 미용실', '분당 카페', '신촌 네일'],
            'URL' => ['https://m.place.naver.com/place/1145161001', 'https://m.place.naver.com/place/1145161002'],
            'NUMBER' => ['100', '50', '30'],
            'DATE' => ['2026-07-15', '2026-07-20'],
            'TAGS' => ['맛집,분위기,데이트', '가성비,친절'],
            'TEXTAREA' => ['정성껏 리뷰 부탁드립니다.', '방문 후 솔직 후기 작성 바랍니다.'],
        ];

        $seq = 0;
        foreach (MarketingProduct::where('is_active', true)->orderBy('id')->take(6)->get() as $pi => $product) {
            $fields = $product->fields()->where('is_active', true)->get();
            for ($k = 0; $k < 3; $k++) {
                $seq++;
                $status = $statuses[($pi + $k) % count($statuses)];
                $qty = $product->min_quantity + ($k * 10);
                $days = $product->quantity_mode === 'daily' ? (3 + $k) : null;
                $unit = (float) $product->min_price;
                $total = $unit * $qty * ($days ?? 1);

                $values = [];
                foreach ($fields as $fi => $f) {
                    $pool = $sampleByType[$f->field_type] ?? null;
                    if ($f->field_type === 'MULTI_SELECT' || $f->field_type === 'SELECT') {
                        $opts = $f->options();
                        if ($opts) {
                            $pick = $opts[($seq + $fi) % count($opts)]['value'];
                            $values[$f->field_key] = $f->field_type === 'MULTI_SELECT' ? [$pick] : $pick;
                        }

                        continue;
                    }
                    if ($f->field_type === 'TOGGLE') {
                        $values[$f->field_key] = ($seq + $fi) % 2 ? '1' : null;

                        continue;
                    }
                    if ($pool) {
                        $values[$f->field_key] = $pool[($seq + $fi) % count($pool)];
                    }
                }

                MarketingOrder::create([
                    'product_id' => $product->id,
                    'user_id' => ($seq % 2) ? $userId : null,
                    'quantity' => $qty,
                    'days' => $days,
                    'field_values' => $values,
                    'unit_price' => $unit,
                    'total_price' => $total,
                    'status' => $status,
                    'orderer_name' => $names[$seq % count($names)],
                    'orderer_contact' => 'buyer'.$seq.'@dummy.test',
                ]);
            }
        }

        $this->command?->info('더미 주문 '.MarketingOrder::where('orderer_contact', 'like', '%@dummy.test')->count().'건 생성');
    }
}
