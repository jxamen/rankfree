<?php

namespace Database\Seeders;

use App\Models\CommunityCategory;
use Illuminate\Database\Seeder;

class CommunityCategorySeeder extends Seeder
{
    public function run(): void
    {
        $cats = [
            ['slug' => 'free', 'name' => '자유게시판', 'description' => '무엇이든 편하게 이야기하는 공간', 'icon' => '💬'],
            ['slug' => 'marketing', 'name' => '마케팅 노하우', 'description' => '네이버 마케팅 팁과 경험 공유', 'icon' => '📈'],
            ['slug' => 'place', 'name' => '플레이스 후기', 'description' => '플레이스 순위·리뷰 관리 이야기', 'icon' => '📍'],
            ['slug' => 'shopping', 'name' => '쇼핑·판매 팁', 'description' => '스마트스토어 판매·상위노출 노하우', 'icon' => '🛒'],
            ['slug' => 'qna', 'name' => '질문답변', 'description' => '궁금한 점을 묻고 답하는 공간', 'icon' => '❓'],
        ];
        foreach ($cats as $i => $c) {
            CommunityCategory::updateOrCreate(['slug' => $c['slug']], $c + ['sort_order' => $i, 'is_active' => true]);
        }
    }
}
