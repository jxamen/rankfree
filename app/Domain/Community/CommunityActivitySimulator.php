<?php

namespace App\Domain\Community;

use App\Models\CommunityCategory;
use App\Models\CommunityComment;
use App\Models\CommunityLike;
use App\Models\CommunityPost;
use App\Models\Persona;
use Illuminate\Support\Carbon;

/**
 * 페르소나 자동 활동 시뮬레이터 — 어드민 수동 버튼과 스케줄러가 공용으로 호출.
 * 활동 설정(가중치·활동수준·선호 카테고리)에 따라 글/댓글/좋아요를 확률적으로 생성한다.
 */
class CommunityActivitySimulator
{
    public function __construct(private PersonaContentGenerator $generator) {}

    /**
     * count 개의 활동을 수행. 반환: ['posts'=>n,'comments'=>n,'likes'=>n].
     */
    public function run(int $count): array
    {
        $result = ['posts' => 0, 'comments' => 0, 'likes' => 0];

        $personas = Persona::where('is_active', true)->where('auto_active', true)->get();
        if ($personas->isEmpty()) {
            return $result;
        }
        $categories = CommunityCategory::where('is_active', true)->get();
        if ($categories->isEmpty()) {
            return $result;
        }

        $mix = config('rankfree.community.mix', ['post' => 1, 'comment' => 3, 'like' => 5]);

        for ($i = 0; $i < $count; $i++) {
            $persona = $this->pickPersona($personas);
            if (! $persona) {
                continue;
            }
            $action = $this->pickAction($persona, $mix);

            if ($action === 'post') {
                if ($this->doPost($persona, $categories)) {
                    $result['posts']++;
                }
            } elseif ($action === 'comment') {
                if ($this->doComment($persona)) {
                    $result['comments']++;
                } elseif ($this->doPost($persona, $categories)) { // 댓글 달 글 없으면 글로 대체
                    $result['posts']++;
                }
            } else {
                if ($this->doLike($persona)) {
                    $result['likes']++;
                }
            }
        }

        return $result;
    }

    /** 활동 수준 가중 랜덤으로 페르소나 1명. */
    private function pickPersona($personas): ?Persona
    {
        $weighted = [];
        foreach ($personas as $p) {
            // 활동수준을 확률로 반영 — active 는 자주, rare 는 드물게
            if (mt_rand() / mt_getrandmax() <= $p->activityFactor()) {
                $weighted[] = $p;
            }
        }
        $pool = $weighted ?: $personas->all();

        return $pool ? $pool[array_rand($pool)] : null;
    }

    /** 페르소나별 글/댓글/좋아요 가중치 × 전역 mix 로 행동 선택. */
    private function pickAction(Persona $persona, array $mix): string
    {
        $w = [
            'post' => max(0, $persona->post_weight) * ($mix['post'] ?? 1),
            'comment' => max(0, $persona->comment_weight) * ($mix['comment'] ?? 3),
            'like' => max(0, $persona->like_weight) * ($mix['like'] ?? 5),
        ];
        $total = array_sum($w);
        if ($total <= 0) {
            return 'like';
        }
        $r = mt_rand(1, $total);
        $acc = 0;
        foreach ($w as $action => $weight) {
            $acc += $weight;
            if ($r <= $acc) {
                return $action;
            }
        }

        return 'like';
    }

    private function doPost(Persona $persona, $categories): bool
    {
        $category = $this->preferredCategory($persona, $categories);
        $content = $this->generator->generatePost($persona, $category);
        if (! $content) {
            return false;
        }
        $at = $this->naturalTimestamp();
        CommunityPost::create([
            'category_id' => $category->id,
            'author_type' => 'persona',
            'persona_id' => $persona->id,
            'title' => $content['title'],
            'body' => $content['body'],
            'views' => mt_rand(3, 80),
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $persona->increment('posts_count');
        $persona->update(['last_acted_at' => now()]);

        return true;
    }

    private function doComment(Persona $persona): bool
    {
        // 최근 글 중 본인 글이 아닌 것에 댓글
        $post = CommunityPost::where(function ($q) use ($persona) {
            $q->where('author_type', '!=', 'persona')->orWhere('persona_id', '!=', $persona->id);
        })->latest('id')->limit(40)->get()->shuffle()->first();
        if (! $post) {
            return false;
        }
        $text = $this->generator->generateComment($persona, $post);
        if (! $text) {
            return false;
        }
        $at = $this->naturalTimestamp($post->created_at);
        CommunityComment::create([
            'post_id' => $post->id,
            'author_type' => 'persona',
            'persona_id' => $persona->id,
            'body' => $text,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $post->increment('comments_count');
        $persona->increment('comments_count');
        $persona->update(['last_acted_at' => now()]);

        return true;
    }

    private function doLike(Persona $persona): bool
    {
        $post = CommunityPost::where(function ($q) use ($persona) {
            $q->where('author_type', '!=', 'persona')->orWhere('persona_id', '!=', $persona->id);
        })->latest('id')->limit(60)->get()->shuffle()->first();
        if (! $post) {
            return false;
        }
        $like = CommunityLike::firstOrCreate([
            'likeable_type' => 'post',
            'likeable_id' => $post->id,
            'liker_type' => 'persona',
            'liker_id' => $persona->id,
        ]);
        if (! $like->wasRecentlyCreated) {
            return false; // 이미 좋아요함
        }
        $post->increment('likes_count');
        $persona->update(['last_acted_at' => now()]);

        return true;
    }

    /** 선호 카테고리 우선, 없으면 랜덤. */
    private function preferredCategory(Persona $persona, $categories): CommunityCategory
    {
        $pref = (array) ($persona->preferred_categories ?? []);
        if ($pref) {
            $match = $categories->whereIn('id', $pref);
            if ($match->isNotEmpty()) {
                return $match->random();
            }
        }

        return $categories->random();
    }

    /** 자연스러운 작성 시각 — 최근 몇 시간 내로 분산(부모 글 이후). */
    private function naturalTimestamp(?Carbon $after = null): Carbon
    {
        $at = now()->subMinutes(mt_rand(0, 240));
        if ($after && $at->lt($after)) {
            $at = $after->copy()->addMinutes(mt_rand(1, 120));
            if ($at->gt(now())) {
                $at = now();
            }
        }

        return $at;
    }
}
