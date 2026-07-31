<?php

namespace Database\Seeders;

use App\Models\RewardMedia;
use Illuminate\Database\Seeder;

/** 첫 매체: 퀴즈농장(miniapp). 설정은 비워 config('reward.defaults') 폴백을 쓴다 — 운영 조정은 어드민(Phase 7). */
class RewardMediaSeeder extends Seeder
{
    public function run(): void
    {
        RewardMedia::query()->updateOrCreate(
            ['slug' => 'quiz-farm'],
            ['name' => '퀴즈농장', 'type' => RewardMedia::TYPE_MINIAPP, 'is_active' => true],
        );
    }
}
