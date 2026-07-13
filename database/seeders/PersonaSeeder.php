<?php

namespace Database\Seeders;

use App\Models\CommunityCategory;
use App\Models\Persona;
use Illuminate\Database\Seeder;

/** 50개 페르소나 생성 — 다양한 나이·성별·관심사·말투·활동설정. 어드민에서 개별 조정 가능. */
class PersonaSeeder extends Seeder
{
    public function run(): void
    {
        $adjs = ['행복한', '느긋한', '부지런한', '소심한', '까칠한', '엉뚱한', '똑똑한', '수줍은', '열정적인', '차분한', '깐깐한', '유쾌한'];
        $nouns = ['너구리', '판다', '고양이', '사장님', '마케터', '초보', '고수', '감자', '토끼', '부엉이', '여우', '단호박', '치즈', '수달', '펭귄'];
        $regions = ['서울', '경기', '부산', '대구', '인천', '광주', '대전', '제주', '강원', '전주', '창원', '수원'];
        $interests = ['맛집', '카페', '뷰티', '쇼핑', '육아', '여행', '헬스', '패션', '반려동물', '인테리어', '스마트스토어', '플레이스', '블로그', '광고', '요식업'];
        $colors = ['#0052ff', '#05b169', '#f4b000', '#8b5cf6', '#ec4899', '#fb923c', '#0891b2', '#e11d48', '#7c3aed', '#059669'];
        $tones = array_keys(Persona::TONES);
        $genders = ['male', 'female', 'none'];
        $levels = ['active', 'active', 'normal', 'normal', 'normal', 'rare']; // 보통이 많게
        $lengths = ['short', 'mid', 'mid', 'long'];
        $hourSets = [['morning'], ['noon'], ['evening'], ['night'], ['morning', 'evening'], ['noon', 'evening'], ['evening', 'night']];

        $catIds = CommunityCategory::pluck('id')->all();

        $used = [];
        $created = 0;
        $attempt = 0;
        while ($created < 50 && $attempt < 400) {
            $attempt++;
            $nick = $adjs[array_rand($adjs)].$nouns[array_rand($nouns)].mt_rand(1, 99);
            if (isset($used[$nick]) || Persona::where('nickname', $nick)->exists()) {
                continue;
            }
            $used[$nick] = true;

            $myInterests = collect($interests)->shuffle()->take(mt_rand(2, 4))->values()->all();
            // 관심사와 겹치는 선호 카테고리 1~3개
            $prefCats = collect($catIds)->shuffle()->take(mt_rand(1, 3))->values()->all();

            Persona::create([
                'nickname' => $nick,
                'avatar_color' => $colors[array_rand($colors)],
                'bio' => $myInterests[0].' 좋아하는 '.$adjs[array_rand($adjs)].' 사람',
                'age' => mt_rand(20, 55),
                'gender' => $genders[array_rand($genders)],
                'region' => $regions[array_rand($regions)],
                'interests' => $myInterests,
                'tone' => $tones[array_rand($tones)],
                'emoji_level' => mt_rand(0, 2),
                'post_length' => $lengths[array_rand($lengths)],
                'activity_level' => $levels[array_rand($levels)],
                'post_weight' => mt_rand(1, 5),
                'comment_weight' => mt_rand(3, 9),
                'like_weight' => mt_rand(5, 10),
                'active_hours' => $hourSets[array_rand($hourSets)],
                'preferred_categories' => $prefCats,
                'is_active' => true,
                'auto_active' => true,
                'joined_at' => now()->subDays(mt_rand(10, 400))->subHours(mt_rand(0, 23)),
            ]);
            $created++;
        }
    }
}
