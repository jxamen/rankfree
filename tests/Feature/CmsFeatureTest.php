<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Faq;
use App\Models\MemberGrade;
use App\Models\Notice;
use App\Models\OperatorRole;
use App\Models\Popup;
use App\Models\Qna;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 콘텐츠(공지·FAQ·QnA·배너·팝업) — 대시보드·콘솔 고객센터·어드민 CMS 검증. */
class CmsFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $role = OperatorRole::create(['name' => '슈퍼관리자', 'slug' => 'super', 'level' => 100, 'is_super' => true]);

        return User::create(['name' => '관리자', 'email' => 'admin@rankfree.kr', 'password' => 'secret1234', 'role' => 'super', 'operator_role_id' => $role->id]);
    }

    private function member(): User
    {
        $grade = MemberGrade::create(['name' => '무료', 'slug' => 'free', 'is_paid' => false, 'tier' => 0, 'rank_slot_limit' => 100]);

        return User::create(['name' => '회원', 'email' => 'member@test.kr', 'password' => 'x1234567', 'grade_id' => $grade->id]);
    }

    // ── 대시보드 ──
    public function test_dashboard_shows_plan_usage_notice_banner(): void
    {
        $user = $this->member();
        Notice::create(['category' => '업데이트', 'title' => '대시보드 개편 안내', 'body' => '<p>내용</p>', 'is_published' => true, 'published_at' => now()]);
        Banner::create(['title' => '신규 확장 출시', 'type' => 'product', 'is_active' => true]);

        $this->actingAs($user)->get('/console')
            ->assertOk()
            ->assertSee('현재 요금제')
            ->assertSee('무료')
            ->assertSee('이번 달 기능별 이용량')
            ->assertSee('대시보드 개편 안내')
            ->assertSee('신규 확장 출시');
    }

    public function test_dashboard_popup_renders(): void
    {
        $user = $this->member();
        Popup::create(['title' => '점검 안내 팝업', 'body' => '<p>점검</p>', 'position' => 'center', 'is_active' => true]);

        $this->actingAs($user)->get('/console')->assertOk()->assertSee('점검 안내 팝업');
    }

    // ── 콘솔 고객센터 ──
    public function test_console_notice_list_and_detail(): void
    {
        $user = $this->member();
        $notice = Notice::create(['category' => '일반', 'title' => '테스트 공지 제목', 'body' => '<p>공지 본문입니다</p>', 'is_published' => true, 'published_at' => now()]);

        $this->actingAs($user)->get('/console/notices')->assertOk()->assertSee('테스트 공지 제목');
        $this->actingAs($user)->get("/console/notices/{$notice->id}")->assertOk()->assertSee('공지 본문입니다', false);
        $this->assertSame(1, $notice->fresh()->views);
    }

    public function test_console_faq_search(): void
    {
        $user = $this->member();
        Faq::create(['category' => '순위 추적', 'question' => '순위 슬롯이 무엇인가요', 'answer' => '<p>키워드x플레이스 조합</p>', 'is_published' => true]);
        Faq::create(['category' => '결제', 'question' => '환불은 어떻게 하나요', 'answer' => '<p>문의 주세요</p>', 'is_published' => true]);

        // 검색어 "슬롯" → 첫 FAQ만
        $this->actingAs($user)->get('/console/faq?q=슬롯')
            ->assertOk()->assertSee('순위 슬롯이 무엇인가요')->assertDontSee('환불은 어떻게 하나요');
    }

    public function test_console_qna_create_and_view(): void
    {
        $user = $this->member();

        $this->actingAs($user)->post('/console/support', [
            'category' => '서비스 이용', 'title' => '문의 제목입니다', 'body' => '문의 내용입니다', 'is_secret' => '1',
        ])->assertRedirect();

        $qna = Qna::where('user_id', $user->id)->first();
        $this->assertNotNull($qna);
        $this->assertTrue($qna->is_secret);
        $this->assertSame('pending', $qna->status);

        $this->actingAs($user)->get("/console/support/{$qna->id}")->assertOk()->assertSee('문의 제목입니다');
    }

    public function test_console_qna_owner_only(): void
    {
        $owner = $this->member();
        $other = User::create(['name' => '타인', 'email' => 'other@test.kr', 'password' => 'x1234567']);
        $qna = Qna::create(['user_id' => $owner->id, 'category' => '기타', 'title' => '비공개', 'body' => 'x']);

        $this->actingAs($other)->get("/console/support/{$qna->id}")->assertForbidden();
    }

    // ── 어드민 CMS ──
    public function test_admin_notice_crud(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/notices')->assertOk()->assertSee('공지사항 관리');
        $this->actingAs($admin)->post('/admin/notices', [
            'category' => '업데이트', 'title' => '새 공지 등록', 'body' => '<p>본문</p>', 'is_published' => '1',
        ])->assertRedirect(route('admin.notices'));

        $notice = Notice::where('title', '새 공지 등록')->first();
        $this->assertNotNull($notice);
        $this->assertTrue($notice->is_published);

        $this->actingAs($admin)->put("/admin/notices/{$notice->id}", [
            'category' => '점검', 'title' => '수정된 공지', 'body' => '<p>수정</p>',
        ])->assertRedirect();
        $this->assertSame('수정된 공지', $notice->fresh()->title);
    }

    public function test_admin_faq_create(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/faqs', [
            'category' => '시작하기', 'question' => 'FAQ 질문 등록', 'answer' => '<p>답변</p>', 'is_published' => '1', 'sort_order' => 3,
        ])->assertRedirect(route('admin.faqs'));

        $this->assertDatabaseHas('faqs', ['question' => 'FAQ 질문 등록', 'category' => '시작하기']);
    }

    public function test_admin_qna_answer(): void
    {
        $admin = $this->admin();
        $member = $this->member();
        $qna = Qna::create(['user_id' => $member->id, 'category' => '기타', 'title' => '답변 대상', 'body' => 'x']);

        $this->actingAs($admin)->post("/admin/qnas/{$qna->id}/answer", ['answer' => '<p>답변 내용</p>'])->assertRedirect();

        $qna->refresh();
        $this->assertSame('answered', $qna->status);
        $this->assertSame($admin->id, $qna->answered_by);
        $this->assertNotNull($qna->answered_at);
    }

    public function test_admin_banner_and_popup_create(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/banners', [
            'title' => '새 배너', 'type' => 'promo', 'bg_color' => '#111111', 'text_color' => '#ffffff', 'is_active' => '1',
        ])->assertRedirect(route('admin.banners'));
        $this->assertDatabaseHas('banners', ['title' => '새 배너', 'type' => 'promo']);

        $this->actingAs($admin)->post('/admin/popups', [
            'title' => '새 팝업', 'body' => '<p>팝업</p>', 'position' => 'top-right', 'width' => 400, 'is_active' => '1', 'dismissible' => '1',
        ])->assertRedirect(route('admin.popups'));
        $this->assertDatabaseHas('popups', ['title' => '새 팝업', 'position' => 'top-right']);
    }

    public function test_admin_banner_image_upload(): void
    {
        $admin = $this->admin();
        Storage::fake('public');
        $file = UploadedFile::fake()->image('banner.jpg', 800, 200);

        $this->actingAs($admin)->post('/admin/banners', [
            'title' => '이미지 배너', 'type' => 'product', 'is_active' => '1', 'image_file' => $file,
        ])->assertRedirect(route('admin.banners'));

        $banner = Banner::where('title', '이미지 배너')->first();
        $this->assertNotNull($banner);
        $this->assertStringContainsString('banners/', (string) $banner->image_url);
        Storage::disk('public')->assertExists('banners/'.basename($banner->image_url));
    }

    public function test_member_cannot_access_admin_cms(): void
    {
        $user = $this->member();
        $this->actingAs($user)->get('/admin/notices')->assertForbidden();
    }
}
