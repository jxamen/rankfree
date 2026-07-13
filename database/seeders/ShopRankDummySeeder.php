<?php

namespace Database\Seeders;

use App\Models\ShopRankRecord;
use App\Models\ShopRankSlot;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * 쇼핑 순위변화 레퍼런스(개발용 더미) — 리워드 상품별 최근 1개월(30일) 일별 순위.
 * 순위는 매일 조금씩 상승/유지/하락이 섞이되 **75% 비중으로 상승**(순위 숫자↓)한다.
 * 각 슬롯: 최종 카테고리명 · 월간 조회수 · 리워드 상품명 포함.
 * 실행: php artisan db:seed --class=ShopRankDummySeeder
 */
class ShopRankDummySeeder extends Seeder
{
    private const DAYS = 30;
    private const LIST_TOTAL = 1200;

    /** 리워드 상품 레퍼런스 세트. */
    private const PRODUCTS = [
        ['keyword' => '무선 이어폰', 'title' => '레인 노이즈캔슬링 블루투스 이어폰 Pro3', 'category' => '디지털/가전 > 이어폰 > 블루투스 이어폰', 'views' => 68400, 'price' => 39900, 'pid' => '8123456701'],
        ['keyword' => '강아지 사료', 'title' => '네이처펫 유기농 오리 대형견 건식사료 5kg', 'category' => '반려동물 > 강아지 사료 > 건식사료', 'views' => 41200, 'price' => 28900, 'pid' => '8123456702'],
        ['keyword' => '여성 원피스', 'title' => '미니라 플리츠 롱 원피스 5color', 'category' => '패션의류 > 여성의류 > 원피스', 'views' => 93800, 'price' => 32000, 'pid' => '8123456703'],
        ['keyword' => '캠핑 의자', 'title' => '아웃도어릴렉스 경량 접이식 캠핑체어', 'category' => '스포츠/레저 > 캠핑 > 캠핑의자', 'views' => 33500, 'price' => 24800, 'pid' => '8123456704'],
    ];

    public function run(): void
    {
        $created = 0;
        foreach (User::all() as $user) {
            foreach (self::PRODUCTS as $p) {
                $slot = ShopRankSlot::firstOrCreate(
                    ['user_id' => $user->id, 'keyword' => $p['keyword'], 'product_id' => $p['pid']],
                    [
                        'category' => $p['category'],
                        'monthly_views' => $p['views'],
                        'target_type' => 'product',
                        'product_title' => $p['title'],
                        'product_url' => 'https://smartstore.naver.com/example/products/'.$p['pid'],
                        'label' => '리워드',
                        'share_token' => Str::random(32),
                        'is_active' => true,
                    ],
                );
                // 기존 슬롯도 카테고리/조회수 최신화
                $slot->update(['category' => $p['category'], 'monthly_views' => $p['views'], 'product_title' => $p['title']]);

                $this->fillRecords($slot, $p['price']);
                $created++;
            }
        }

        $this->command?->info('쇼핑 순위 레퍼런스 '.$created.'개 슬롯 × '.self::DAYS.'일(75% 상승) 더미 생성 완료.');
    }

    /** 슬롯에 30일 일별 순위 기록 — 75% 상승 편향 랜덤워크. */
    private function fillRecords(ShopRankSlot $slot, int $basePrice): void
    {
        $rank = random_int(90, 200);      // 캠페인 시작 순위
        $price = $basePrice;

        for ($i = self::DAYS - 1; $i >= 0; $i--) {
            ShopRankRecord::updateOrCreate(
                ['slot_id' => $slot->id, 'checked_date' => now()->subDays($i)->toDateString()],
                [
                    'rank' => $rank,
                    'price' => $price,
                    'list_total' => self::LIST_TOTAL,
                    'created_at' => now()->subDays($i),
                ],
            );

            // 다음(더 최근) 날 순위 변화 — 상승 75% / 유지 15% / 하락 10%
            $roll = random_int(1, 100);
            if ($roll <= 75) {
                $rank = max(1, $rank - random_int(1, 5));           // 상승(순위↓)
            } elseif ($roll <= 90) {
                // 유지
            } else {
                $rank = min(self::LIST_TOTAL, $rank + random_int(1, 3)); // 하락
            }
            $price = max(1000, $price + random_int(-500, 500));    // 가격 소폭 변동
        }

        $last = $slot->records()->orderByDesc('checked_date')->first();
        $slot->update([
            'last_rank' => $last->rank,
            'last_price' => $last->price,
            'last_checked_at' => now(),
        ]);
    }
}
