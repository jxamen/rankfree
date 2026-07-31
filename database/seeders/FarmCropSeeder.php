<?php

namespace Database\Seeders;

use App\Models\FarmCrop;
use Illuminate\Database\Seeder;

/**
 * 작물 6종(design-01 §2-2). points 초기값은 클라이언트 DEFAULT_CONFIG(server-api-spec §0 예시)와 동일 —
 * 실제 지급액은 사업 결정 후 어드민에서 조정한다(진행 중 작물엔 소급되지 않음).
 */
class FarmCropSeeder extends Seeder
{
    public function run(): void
    {
        $crops = [
            ['code' => 'lettuce', 'name' => '상추', 'emoji' => '🥬', 'points' => 50, 'sort_order' => 1],
            ['code' => 'carrot', 'name' => '당근', 'emoji' => '🥕', 'points' => 70, 'sort_order' => 2],
            ['code' => 'onion', 'name' => '양파', 'emoji' => '🧅', 'points' => 70, 'sort_order' => 3],
            ['code' => 'potato', 'name' => '감자', 'emoji' => '🥔', 'points' => 100, 'sort_order' => 4],
            ['code' => 'tomato', 'name' => '토마토', 'emoji' => '🍅', 'points' => 150, 'sort_order' => 5],
            ['code' => 'corn', 'name' => '옥수수', 'emoji' => '🌽', 'points' => 200, 'sort_order' => 6],
        ];

        foreach ($crops as $crop) {
            FarmCrop::query()->updateOrCreate(['code' => $crop['code']], $crop + ['days' => 7]);
        }
    }
}
