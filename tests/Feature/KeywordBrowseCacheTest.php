<?php

namespace Tests\Feature;

use App\Models\{Keyword, KeywordCandidate, KeywordCategory, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 키워드 탐색 목록은 총계·상태집계를 캐시한다 — 파일 캐시(운영 드라이버)에서 되살릴 때
 * Collection 을 넣어두면 __PHP_Incomplete_Class 가 되어 뷰가 죽는다(운영 500 실측). 2회 요청으로 못박는다.
 */
class KeywordBrowseCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_survives_a_cached_second_request_on_file_driver(): void
    {
        config(['cache.default' => 'file']);
        Cache::store('file')->flush();

        $u = User::create(['name' => 'a', 'email' => 'kbc@rf.kr', 'password' => 'x1234567', 'role' => 'super']);
        $cat = KeywordCategory::create(['type' => 'shopping', 'name' => '패션의류', 'slug' => 'f', 'sort' => 1, 'is_active' => true]);
        KeywordCandidate::create(['category_id' => $cat->id, 'keyword' => '린넨원피스', 'source' => 'datalab', 'status' => 'pending', 'monthly_total' => 5000]);
        // 마스터는 후보 생성 시 옵저버(KeywordCandidateObserver)가 자동으로 만든다

        // 1회차 = 캐시 채움, 2회차 = 캐시에서 복원(여기서 깨졌다)
        $this->actingAs($u)->get('/admin/keyword-browse?type=shopping')->assertOk();
        $this->actingAs($u)->get('/admin/keyword-browse?type=shopping')->assertOk()->assertSee('린넨원피스');

        Cache::store('file')->flush();
    }
}
