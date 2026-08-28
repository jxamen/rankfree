<?php

namespace Tests\Feature;

use App\Domain\Reward\ImageProofVerifier;
use App\Domain\Reward\MissionGrader;
use App\Models\RewardMedia;
use App\Models\RewardMission;
use App\Models\RewardUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 유형별 정답 확인 방식(2026-08-28) — boosting_shop quiz 이식.
 *  · 플레이스 유입: 참여자가 붙여넣는 URL 에 정답(고유번호 등)이 **들어 있으면** 통과(contains)
 *  · 플레이스 저장 · 쇼핑 찜: 텍스트로 물을 수 없어 **스크린샷에 표식이 있는지**로 판정(ImageProofVerifier)
 */
class RewardProofMethodTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-07-31';

    private function mission(array $attrs = []): RewardMission
    {
        return RewardMission::query()->create(array_merge([
            'order_item_id' => 930001, 'order_id' => 930, 'status' => 'active', 'kind' => 'place',
            'starts_on' => self::DAY, 'ends_on' => Carbon::parse(self::DAY)->addDays(6)->toDateString(),
            'daily_quota' => 5, 'total_quota' => 35, 'unit_revenue' => 375, 'payout_point' => 10,
            'per_user_limit' => 7, 'per_user_daily_limit' => 1,
            'title' => '플레이스 미션', 'description' => '설명',
            'keyword' => '초량미용실', 'landing_url' => 'https://s.example/p1',
        ], $attrs));
    }

    private function user(): RewardUser
    {
        $media = RewardMedia::query()->create([
            'slug' => 'proof-media', 'name' => '매체', 'type' => RewardMedia::TYPE_VENDOR_API,
            'verify_mode' => 'server', 'is_active' => true,
        ]);

        return RewardUser::query()->create([
            'media_id' => $media->id, 'user_key_hash' => hash('sha256', 'proof-user'), 'status' => 'active',
        ]);
    }

    public function test_플레이스는_붙여넣은_URL_에_정답이_들어_있으면_통과한다(): void
    {
        // 참여자가 보내는 주소에는 쿼리스트링이 붙어 완전일치로는 잡히지 않는다
        $mission = $this->mission(['answer_type' => 'contains', 'answer' => '2004558772', 'tags' => []]);
        $user = $this->user();

        $ok = MissionGrader::grade($mission, $user, self::DAY,
            'https://m.place.naver.com/hairshop/2004558772/home?entry=pll');
        $this->assertTrue($ok['correct']);

        $no = MissionGrader::grade($mission, $user, self::DAY, 'https://m.place.naver.com/hairshop/1111111111/home');
        $this->assertFalse($no['correct']);

        // 정답이 비어 있으면 아무거나 통과시키지 않는다
        $empty = $this->mission(['order_item_id' => 930002, 'answer_type' => 'contains', 'answer' => '', 'tags' => []]);
        $this->assertFalse(MissionGrader::grade($empty, $user, self::DAY, '아무값')['correct']);
    }

    public function test_저장_찜은_표식_템플릿이_준비되어_있다(): void
    {
        $verifier = app(ImageProofVerifier::class);

        // 저장 1장, 찜 2장(화면 상태에 따라 아이콘이 다르다)
        $this->assertCount(1, $verifier->templatesFor('save'));
        $this->assertCount(2, $verifier->templatesFor('zzim'));
        foreach (['save', 'zzim'] as $kind) {
            foreach ($verifier->templatesFor($kind) as $path) {
                $this->assertFileExists($path);
            }
        }

        $this->assertTrue($verifier->supports('save'));
        $this->assertTrue($verifier->supports('zzim'));
        // 유입 계열은 텍스트 정답이라 이미지 증빙을 쓰지 않는다
        $this->assertFalse($verifier->supports('shopping'));
        $this->assertFalse($verifier->supports('place'));
    }

    public function test_정답_소스_목록에_새_방식이_노출된다(): void
    {
        $sources = (array) config('reward.answer_sources');

        $this->assertArrayHasKey('contains', $sources);
        $this->assertArrayHasKey('image', $sources);
    }
}
