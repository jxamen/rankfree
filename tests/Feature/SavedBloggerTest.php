<?php

namespace Tests\Feature;

use App\Models\BlogIndexAnalysis;
use App\Models\SavedBlogger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 저장 블로거 — 키워드 분석에서 (키워드×blog_id) 조합 저장·해제·목록·엑셀·삭제. */
class SavedBloggerTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email = 'u@rf.kr'): User
    {
        return User::create(['name' => 'u', 'email' => $email, 'password' => 'x1234567']);
    }

    private function keywordAnalysis(User $user, string $kw = '강남 맛집'): BlogIndexAnalysis
    {
        $blogger = fn (string $id, float $score, string $grade, int $rank) => [
            'blog_id' => $id, 'score' => $score, 'grade' => $grade, 'search_rank' => $rank,
            'profile' => ['blog_name' => $id.' 블로그', 'subscriber_cnt' => 100, 'day_visitor_avg' => 500, 'post_total' => 300],
            'quality' => ['avg_photos' => 10, 'avg_length' => 2000, 'top_words' => [['word' => '맛집', 'count' => 9]]],
            'featured' => ['title' => $id.' 글', 'log_no' => '1', 'date' => '20260701'],
        ];

        return BlogIndexAnalysis::create([
            'user_id' => $user->id, 'type' => 'keyword', 'query' => $kw, 'title' => $kw,
            'score' => 70, 'blogger_count' => 3,
            'snapshot' => ['keyword' => $kw, 'bloggers' => [$blogger('aaa', 80, 'A', 1), $blogger('bbb', 60, 'B', 2), $blogger('ccc', 40, 'C', 3)]],
        ]);
    }

    public function test_save_multiple_and_single_bloggers(): void
    {
        $user = $this->user();
        $analysis = $this->keywordAnalysis($user);

        // 다중 저장 — 스냅샷에 없는 ID(zzz)는 무시
        $this->actingAs($user)->postJson("/console/blog-index/{$analysis->id}/save", ['blog_ids' => ['aaa', 'bbb', 'zzz']])
            ->assertOk()->assertJson(['saved' => 2]);

        // 단건 저장
        $this->actingAs($user)->postJson("/console/blog-index/{$analysis->id}/save", ['blog_ids' => ['ccc']])
            ->assertOk()->assertJson(['saved' => 1]);

        $this->assertSame(3, SavedBlogger::where('user_id', $user->id)->where('keyword', '강남 맛집')->count());
        $this->assertDatabaseHas('saved_bloggers', ['user_id' => $user->id, 'keyword' => '강남 맛집', 'blog_id' => 'aaa', 'grade' => 'A']);

        // 같은 조합 재저장 → 중복 생성 없이 갱신
        $this->actingAs($user)->postJson("/console/blog-index/{$analysis->id}/save", ['blog_ids' => ['aaa']])->assertOk();
        $this->assertSame(3, SavedBlogger::where('user_id', $user->id)->count());
    }

    public function test_unsave_removes_only_requested_combo(): void
    {
        $user = $this->user();
        $analysis = $this->keywordAnalysis($user);
        $this->actingAs($user)->postJson("/console/blog-index/{$analysis->id}/save", ['blog_ids' => ['aaa', 'bbb']]);

        $this->actingAs($user)->postJson("/console/blog-index/{$analysis->id}/unsave", ['blog_ids' => ['aaa']])
            ->assertOk()->assertJson(['removed' => 1]);
        $this->assertDatabaseMissing('saved_bloggers', ['user_id' => $user->id, 'keyword' => '강남 맛집', 'blog_id' => 'aaa']);
        $this->assertDatabaseHas('saved_bloggers', ['user_id' => $user->id, 'keyword' => '강남 맛집', 'blog_id' => 'bbb']);
    }

    public function test_save_guards_owner_and_type(): void
    {
        $owner = $this->user();
        $other = $this->user('o@rf.kr');
        $analysis = $this->keywordAnalysis($owner);

        // 남의 분석엔 저장 불가
        $this->actingAs($other)->postJson("/console/blog-index/{$analysis->id}/save", ['blog_ids' => ['aaa']])->assertForbidden();

        // 블로그 단건 분석엔 저장 불가(키워드 조합이 없음)
        $blog = BlogIndexAnalysis::create([
            'user_id' => $owner->id, 'type' => 'blog', 'query' => 'aaa', 'title' => 'aaa', 'snapshot' => [],
        ]);
        $this->actingAs($owner)->postJson("/console/blog-index/{$blog->id}/save", ['blog_ids' => ['aaa']])->assertStatus(400);
    }

    public function test_saved_page_lists_own_rows_with_keyword_filter(): void
    {
        $user = $this->user();
        $other = $this->user('o@rf.kr');
        $a1 = $this->keywordAnalysis($user, '강남 맛집');
        $a2 = $this->keywordAnalysis($user, '홍대 카페');
        $this->actingAs($user)->postJson("/console/blog-index/{$a1->id}/save", ['blog_ids' => ['aaa']]);
        $this->actingAs($user)->postJson("/console/blog-index/{$a2->id}/save", ['blog_ids' => ['bbb']]);
        // 다른 사용자의 저장 — 목록에 안 보여야 함
        $b = $this->keywordAnalysis($other, '분당 미용실');
        $this->actingAs($other)->postJson("/console/blog-index/{$b->id}/save", ['blog_ids' => ['ccc']]);

        $this->actingAs($user)->get('/console/blog-saved')
            ->assertOk()->assertSee('강남 맛집')->assertSee('홍대 카페')->assertSee('aaa')->assertSee('bbb')
            ->assertDontSee('분당 미용실');

        // 키워드 필터 — 해당 키워드 행만
        $this->actingAs($user)->get('/console/blog-saved?kw='.urlencode('강남 맛집'))
            ->assertOk()->assertSee('aaa 블로그')->assertDontSee('bbb 블로그');
    }

    public function test_show_page_renders_save_ui_with_saved_state(): void
    {
        $user = $this->user();
        $analysis = $this->keywordAnalysis($user);
        $this->actingAs($user)->postJson("/console/blog-index/{$analysis->id}/save", ['blog_ids' => ['aaa']]);

        $this->actingAs($user)->get("/console/blog-index/{$analysis->id}")
            ->assertOk()
            ->assertSee('선택 저장')          // 다중 저장 버튼
            ->assertSee('저장 블로거')        // 모아보기 링크
            ->assertSee('bi-check-all', false) // 전체 선택 체크박스
            ->assertSee('data-saved="1"', false)  // 저장된 행 표시(aaa)
            ->assertSee('data-saved="0"', false); // 미저장 행(bbb·ccc)
    }

    public function test_saved_export_downloads_xlsx(): void
    {
        $user = $this->user();
        $analysis = $this->keywordAnalysis($user);
        $this->actingAs($user)->postJson("/console/blog-index/{$analysis->id}/save", ['blog_ids' => ['aaa']]);

        $this->actingAs($user)->get('/console/blog-saved/export?kw='.urlencode('강남 맛집'))
            ->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_saved_destroy_deletes_only_own_rows(): void
    {
        $user = $this->user();
        $other = $this->user('o@rf.kr');
        $a = $this->keywordAnalysis($user);
        $this->actingAs($user)->postJson("/console/blog-index/{$a->id}/save", ['blog_ids' => ['aaa', 'bbb']]);
        $mine = SavedBlogger::where('user_id', $user->id)->pluck('id')->all();
        $b = $this->keywordAnalysis($other);
        $this->actingAs($other)->postJson("/console/blog-index/{$b->id}/save", ['blog_ids' => ['ccc']]);
        $theirs = SavedBlogger::where('user_id', $other->id)->value('id');

        // 남의 행 ID를 섞어도 내 것만 삭제
        $this->actingAs($user)->deleteJson('/console/blog-saved', ['ids' => array_merge($mine, [$theirs])])
            ->assertOk()->assertJson(['removed' => 2]);
        $this->assertSame(0, SavedBlogger::where('user_id', $user->id)->count());
        $this->assertSame(1, SavedBlogger::where('user_id', $other->id)->count());
    }
}
