<?php

namespace Tests\Feature;

use App\Models\CommunityCategory;
use App\Models\CommunityPost;
use App\Models\CommunityPostAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 커뮤니티 첨부파일 — 등록·삭제는 운영자만, 다운로드는 글을 보는 누구나(비로그인 포함). */
class CommunityAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(CommunityPostAttachment::DISK);
    }

    private function category(): CommunityCategory
    {
        return CommunityCategory::create(['slug' => 'free', 'name' => '자유', 'description' => 'd', 'sort_order' => 0, 'is_active' => true]);
    }

    private function operator(): User
    {
        return User::create(['name' => 'op', 'email' => 'op@rf.kr', 'password' => 'x1234567', 'role' => 'super']);
    }

    private function member(): User
    {
        return User::create(['name' => 'me', 'email' => 'me@rf.kr', 'password' => 'x1234567']);
    }

    private function postPayload(CommunityCategory $cat, array $over = []): array
    {
        return array_merge(['category_id' => $cat->id, 'title' => '첨부 테스트', 'body' => '본문입니다'], $over);
    }

    public function test_operator_can_attach_files_when_creating_post(): void
    {
        $cat = $this->category();

        $this->actingAs($this->operator())
            ->post(route('community.store'), $this->postPayload($cat, [
                'attachments' => [
                    UploadedFile::fake()->create('가격표.pdf', 20),
                    UploadedFile::fake()->image('썸네일.png'),
                ],
            ]))
            ->assertRedirect();

        $post = CommunityPost::firstOrFail();
        $this->assertCount(2, $post->attachments);
        $this->assertEquals('가격표.pdf', $post->attachments[0]->original_name);
        foreach ($post->attachments as $file) {
            Storage::disk(CommunityPostAttachment::DISK)->assertExists($file->path);
        }
    }

    public function test_member_upload_is_ignored_without_operator_role(): void
    {
        $cat = $this->category();

        $this->actingAs($this->member())
            ->post(route('community.store'), $this->postPayload($cat, [
                'attachments' => [UploadedFile::fake()->create('몰래.pdf', 10)],
            ]))
            ->assertRedirect();

        $this->assertSame(0, CommunityPostAttachment::count());
        $this->assertCount(0, Storage::disk(CommunityPostAttachment::DISK)->allFiles());
    }

    public function test_disallowed_extension_is_rejected(): void
    {
        $cat = $this->category();

        $this->actingAs($this->operator())
            ->post(route('community.store'), $this->postPayload($cat, [
                'attachments' => [UploadedFile::fake()->create('악성.exe', 10)],
            ]))
            ->assertSessionHasErrors('attachments.0');

        $this->assertSame(0, CommunityPostAttachment::count());
    }

    public function test_guest_can_download_attachment_and_count_increases(): void
    {
        $cat = $this->category();
        $this->actingAs($this->operator())->post(route('community.store'), $this->postPayload($cat, [
            'attachments' => [UploadedFile::fake()->create('안내문.pdf', 12)],
        ]));
        $file = CommunityPostAttachment::firstOrFail();

        // 로그아웃 상태(비로그인)에서 내려받기
        $res = $this->get(route('community.attachment.download', $file));
        $res->assertOk();
        $this->assertStringContainsString('attachment;', (string) $res->headers->get('content-disposition'));
        $this->assertSame(1, $file->fresh()->download_count);
    }

    public function test_only_operator_can_delete_attachment(): void
    {
        $cat = $this->category();
        $op = $this->operator();
        $this->actingAs($op)->post(route('community.store'), $this->postPayload($cat, [
            'attachments' => [UploadedFile::fake()->create('삭제대상.pdf', 12)],
        ]));
        $file = CommunityPostAttachment::firstOrFail();

        $this->actingAs($this->member())
            ->delete(route('community.attachment.destroy', $file))->assertForbidden();
        $this->assertSame(1, CommunityPostAttachment::count());

        $this->actingAs($op)
            ->delete(route('community.attachment.destroy', $file))->assertRedirect();
        $this->assertSame(0, CommunityPostAttachment::count());
        Storage::disk(CommunityPostAttachment::DISK)->assertMissing($file->path);
    }

    public function test_deleting_post_removes_attachment_files(): void
    {
        $cat = $this->category();
        $op = $this->operator();
        $this->actingAs($op)->post(route('community.store'), $this->postPayload($cat, [
            'attachments' => [UploadedFile::fake()->create('본문첨부.pdf', 12)],
        ]));
        $post = CommunityPost::firstOrFail();
        $path = $post->attachments->first()->path;

        $this->actingAs($op)->delete(route('community.destroy', $post))->assertRedirect();

        $this->assertSame(0, CommunityPostAttachment::count());
        Storage::disk(CommunityPostAttachment::DISK)->assertMissing($path);
    }

    public function test_show_page_lists_attachment_for_guest(): void
    {
        $cat = $this->category();
        $this->actingAs($this->operator())->post(route('community.store'), $this->postPayload($cat, [
            'attachments' => [UploadedFile::fake()->create('공개자료.pdf', 12)],
        ]));
        $post = CommunityPost::firstOrFail();
        $file = $post->attachments->first();

        $this->get(route('community.show', $post))
            ->assertOk()
            ->assertSee('공개자료.pdf')
            ->assertSee(route('community.attachment.download', $file), false);
    }
}
