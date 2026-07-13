<?php

namespace Database\Seeders;

use App\Models\PlaceRankRecord;
use App\Models\PlaceRankSlot;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * 개발용 더미 — 모든 슬롯에 최근 N일(기본 50일) 순위/리뷰 기록을 채운다.
 * 슬롯이 없는 사용자에게는 데모 슬롯 2개(미용실·음식점)를 만들어준다.
 * 로컬 확인용: php artisan db:seed --class=RankDummySeeder (DatabaseSeeder 미포함)
 */
class RankDummySeeder extends Seeder
{
    private const DAYS = 50;

    public function run(): void
    {
        foreach (User::all() as $user) {
            if ($user->rankSlots()->count() === 0) {
                $user->rankSlots()->create([
                    'keyword' => '강남 미용실',
                    'place_id' => '1145161001',
                    'place_name' => '라온헤어 강남점',
                    'place_url' => 'https://m.place.naver.com/hairshop/1145161001',
                    'category' => 'hairshop',
                    'share_token' => Str::random(32),
                ]);
                $user->rankSlots()->create([
                    'keyword' => '부천 맛집',
                    'place_id' => '1145161002',
                    'place_name' => '소호식당',
                    'place_url' => 'https://m.place.naver.com/restaurant/1145161002',
                    'category' => 'restaurant',
                    'label' => '본점',
                    'share_token' => Str::random(32),
                ]);
            }
        }

        foreach (PlaceRankSlot::all() as $slot) {
            $rank = random_int(15, 30);
            $rev = random_int(120, 200);
            $blog = random_int(5, 20);
            $save = random_int(50, 90);

            for ($i = self::DAYS - 1; $i >= 0; $i--) {
                $rank = max(1, min(299, $rank + random_int(-3, 3)));
                $rev += random_int(0, 4);
                $blog += random_int(0, 1);
                $save += random_int(0, 3);

                PlaceRankRecord::updateOrCreate(
                    ['slot_id' => $slot->id, 'checked_date' => now()->subDays($i)->toDateString()],
                    [
                        'rank' => $rank,
                        'review_count' => $rev,
                        'blog_review_count' => $blog,
                        'save_count' => $slot->category === 'restaurant' ? $save : null,
                        'review_score' => null,
                        'list_total' => 300,
                        'created_at' => now()->subDays($i),
                    ],
                );
            }

            $last = $slot->records()->orderByDesc('checked_date')->first();
            $slot->update([
                'last_rank' => $last->rank,
                'last_review_count' => $last->review_count,
                'last_checked_at' => now(),
            ]);
        }

        $this->command?->info('슬롯 '.PlaceRankSlot::count().'개 × '.self::DAYS.'일 더미 기록 생성 완료.');
    }
}
