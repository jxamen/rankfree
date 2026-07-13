<?php

namespace Tests\Feature;

use Tests\TestCase;

/** 블로그 키워드 결과 행(_kw_row) — 이웃수 제거·게시물수 표기·행 전체 진입점 검증. */
class BlogKwRowTest extends TestCase
{
    private function blogger(): array
    {
        return [
            'blog_id' => 'testblog', 'grade' => 'A', 'score' => 80, 'search_rank' => 1,
            'profile' => [
                'blog_name' => '테스트 블로그', 'subscriber_cnt' => 17159, 'post_total' => 2976,
                'day_visitor_avg' => 500, 'power_blog' => false,
                'visitor5' => [['date' => '20260711', 'count' => 244], ['date' => '20260710', 'count' => 530]],
            ],
            'quality' => ['avg_photos' => 5, 'avg_length' => 1000, 'top_words' => []],
        ];
    }

    public function test_row_shows_post_total_not_subscriber(): void
    {
        $html = view('console.blog._kw_row', ['b' => $this->blogger()])->render();

        // 게시물수(post_total) 표기 + 정렬 속성
        $this->assertStringContainsString('data-posts="2976"', $html);
        $this->assertStringContainsString('2,976', $html);
        // 이웃수 컬럼/정렬 속성 제거
        $this->assertStringNotContainsString('data-sub=', $html);
        $this->assertStringNotContainsString('17,159', $html);
    }

    public function test_row_has_analyze_entry_point(): void
    {
        $html = view('console.blog._kw_row', ['b' => $this->blogger()])->render();

        // 행 전체 hover/클릭 진입점(지수분석 링크·툴팁)
        $this->assertStringContainsString('bi-row', $html);
        $this->assertStringContainsString('bi-analyze-link', $html);
        $this->assertStringContainsString('bi-analyze-tip', $html);
    }
}
