<?php

namespace Tests\Feature;

use App\Domain\Community\CommunityActivitySimulator;
use App\Domain\Community\PersonaContentGenerator;
use App\Models\CommunityCategory;
use App\Models\CommunityComment;
use App\Models\CommunityLike;
use App\Models\CommunityPost;
use App\Models\CommunitySeed;
use App\Models\Persona;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 커뮤니티 — 페르소나 자동 활동(폴백) + 공개 열람 + 실사용자 글/댓글/좋아요. */
class CommunityTest extends TestCase
{
    use RefreshDatabase;

    private function category(string $slug = 'free'): CommunityCategory
    {
        return CommunityCategory::create(['slug' => $slug, 'name' => '자유', 'description' => 'd', 'sort_order' => 0, 'is_active' => true]);
    }

    private function persona(array $over = []): Persona
    {
        return Persona::create(array_merge([
            'nickname' => '테스트너구리'.mt_rand(1, 99999),
            'tone' => 'friendly', 'emoji_level' => 1, 'post_length' => 'mid',
            'activity_level' => 'active', 'post_weight' => 5, 'comment_weight' => 5, 'like_weight' => 5,
            'interests' => ['맛집'], 'is_active' => true, 'auto_active' => true,
        ], $over));
    }

    private function user(string $email = 'u@rf.kr', bool $super = false): User
    {
        return User::create(['name' => 'u', 'email' => $email, 'password' => 'x1234567'] + ($super ? ['role' => 'super'] : []));
    }

    public function test_simulator_creates_posts_comments_likes_via_fallback(): void
    {
        config(['services.anthropic.key' => null]); // API 없이 템플릿 폴백
        $this->category();
        for ($i = 0; $i < 6; $i++) {
            $this->persona();
        }

        $r = app(CommunityActivitySimulator::class)->run(40);

        $this->assertGreaterThan(0, $r['posts'] + $r['comments'] + $r['likes']);
        // 글이 하나라도 생겼으면 페르소나 작성자로 저장됨
        if ($r['posts'] > 0) {
            $this->assertDatabaseHas('community_posts', ['author_type' => 'persona']);
        }
    }

    public function test_public_index_lists_posts_without_login(): void
    {
        $cat = $this->category();
        $p = $this->persona();
        CommunityPost::create(['category_id' => $cat->id, 'author_type' => 'persona', 'persona_id' => $p->id, 'title' => '테스트 글 제목', 'body' => '본문내용']);

        $this->get('/community')->assertOk()->assertSee('테스트 글 제목')->assertSee($p->nickname);
    }

    public function test_user_can_post_comment_and_like(): void
    {
        $cat = $this->category();
        $user = $this->user();

        // 글쓰기
        $this->actingAs($user)->post('/community', [
            'category_id' => $cat->id, 'title' => '내 첫 글', 'body' => '반갑습니다',
        ])->assertRedirect();
        $post = CommunityPost::where('title', '내 첫 글')->firstOrFail();
        $this->assertSame('user', $post->author_type);
        $this->assertSame($user->id, $post->user_id);

        // 댓글
        $this->actingAs($user)->post("/community/post/{$post->id}/comment", ['body' => '좋은 글이네요'])->assertRedirect();
        $this->assertDatabaseHas('community_comments', ['post_id' => $post->id, 'author_type' => 'user', 'user_id' => $user->id]);
        $this->assertSame(1, $post->fresh()->comments_count);

        // 좋아요 토글(on)
        $this->actingAs($user)->postJson("/community/post/{$post->id}/like")->assertOk()->assertJson(['liked' => true, 'count' => 1]);
        $this->assertDatabaseHas('community_likes', ['likeable_type' => 'post', 'likeable_id' => $post->id, 'liker_type' => 'user', 'liker_id' => $user->id]);
        // 다시 누르면 off
        $this->actingAs($user)->postJson("/community/post/{$post->id}/like")->assertOk()->assertJson(['liked' => false, 'count' => 0]);
        $this->assertSame(0, CommunityLike::count());
    }

    public function test_guest_cannot_write(): void
    {
        $cat = $this->category();
        $this->post('/community', ['category_id' => $cat->id, 'title' => 't', 'body' => 'b'])->assertRedirect('/login');
        $this->get('/community/new')->assertRedirect('/login');
    }

    public function test_post_show_increments_views_and_shows_comments(): void
    {
        $cat = $this->category();
        $p = $this->persona();
        $post = CommunityPost::create(['category_id' => $cat->id, 'author_type' => 'persona', 'persona_id' => $p->id, 'title' => '조회수 테스트', 'body' => 'x']);
        CommunityComment::create(['post_id' => $post->id, 'author_type' => 'persona', 'persona_id' => $p->id, 'body' => '페르소나 댓글']);

        $this->get("/community/post/{$post->id}")->assertOk()->assertSee('조회수 테스트')->assertSee('페르소나 댓글');
        $this->assertSame(1, $post->fresh()->views);
    }

    public function test_generator_uses_seed_material_when_present(): void
    {
        config(['services.anthropic.key' => null]); // 폴백 경로 — 글밥을 소재로 변형
        $cat = $this->category();
        $persona = $this->persona(['post_length' => 'mid']);
        $seed = CommunitySeed::create([
            'kind' => 'post', 'category_id' => $cat->id,
            'title' => '수집한 글감 제목', 'body' => '수집한 글감 본문입니다. 리뷰 관리 고민이 많네요.', 'is_active' => true,
        ]);

        $post = app(PersonaContentGenerator::class)->generatePost($persona, $cat);

        $this->assertNotNull($post);
        // 폴백은 소재 본문을 재료로 사용 → 본문이 글감에서 파생됨
        $this->assertStringContainsString('리뷰 관리', $post['body']);
        $this->assertSame(1, $seed->fresh()->used_count); // 소진 카운트 증가
    }

    public function test_comment_seed_used_in_fallback(): void
    {
        config(['services.anthropic.key' => null]);
        $cat = $this->category();
        $persona = $this->persona(['emoji_level' => 0]);
        $post = CommunityPost::create(['category_id' => $cat->id, 'author_type' => 'user', 'user_id' => $this->user()->id, 'title' => 't', 'body' => 'b']);
        $seed = CommunitySeed::create(['kind' => 'comment', 'body' => '완전 공감되는 글이에요', 'is_active' => true]);

        $comment = app(PersonaContentGenerator::class)->generateComment($persona, $post);

        $this->assertSame('완전 공감되는 글이에요', $comment);
        $this->assertSame(1, $seed->fresh()->used_count);
    }

    public function test_admin_bulk_add_seeds(): void
    {
        $cat = $this->category();
        $super = $this->user('admin2@rf.kr', true);

        // --- 로 구분한 2개의 글 소재 (첫 줄 제목 + 본문)
        $bulk = "첫 글감 제목\n첫 글감 본문 내용\n---\n둘째 글감 제목\n둘째 본문";
        $this->actingAs($super)->post(route('admin.community-seeds.store'), [
            'kind' => 'post', 'category_id' => $cat->id, 'bulk' => $bulk,
        ])->assertRedirect();

        $this->assertSame(2, CommunitySeed::where('kind', 'post')->count());
        $this->assertDatabaseHas('community_seeds', ['title' => '첫 글감 제목', 'body' => '첫 글감 본문 내용', 'category_id' => $cat->id]);
    }

    public function test_admin_can_manage_and_simulate_personas(): void
    {
        config(['services.anthropic.key' => null]);
        $this->category();
        $super = $this->user('admin@rf.kr', true);

        // 목록
        $this->actingAs($super)->get('/admin/personas')->assertOk()->assertSee('페르소나');

        // 새 페르소나 생성 (콤마 관심사 파싱)
        $this->actingAs($super)->post('/admin/personas', [
            'nickname' => '수동페르소나', 'tone' => 'expert', 'emoji_level' => 0, 'post_length' => 'short',
            'activity_level' => 'normal', 'post_weight' => 3, 'comment_weight' => 6, 'like_weight' => 8,
            'interests' => '맛집, 카페', 'is_active' => 1, 'auto_active' => 1,
        ])->assertRedirect(route('admin.personas'));
        $persona = Persona::where('nickname', '수동페르소나')->firstOrFail();
        $this->assertSame(['맛집', '카페'], $persona->interests);

        // 자동활동 토글
        $this->actingAs($super)->post(route('admin.personas.toggle', $persona))->assertRedirect();
        $this->assertFalse($persona->fresh()->auto_active);

        // 지금 활동 생성
        $this->actingAs($super)->post(route('admin.personas.simulate'), ['count' => 5])->assertRedirect();
    }
}
