<?php

namespace Tests\Feature;

use Tests\TestCase;

/** 블로그 단건 상세의 "최근 방문자 추이" 라인 차트(117 스타일) 렌더 검증. */
class BlogVisitorChartTest extends TestCase
{
    private function blog(array $visitor5): array
    {
        return [
            'blog_id' => 'testblog', 'blog_name' => '테스트 블로그', 'score' => 80, 'grade' => 'A',
            'profile' => [
                'blog_name' => '테스트 블로그', 'subscriber_cnt' => 100, 'day_visitor_avg' => 500,
                'total_visitor' => 10000, 'post_per_week' => 3, 'avg_comment' => 5, 'post_total' => 200,
                'power_blog' => false, 'influencer' => false, 'directory' => '맛집',
                'top_focus' => 40, 'last_post' => '2026-07-10', 'visitor5' => $visitor5,
            ],
            'quality' => ['analyzed' => 10, 'avg_photos' => 5, 'avg_length' => 1000, 'video_ratio' => 10, 'top_words' => []],
            'breakdown' => [
                'profile' => 70, 'content' => 80, 'focus' => 60, 'activity' => 50, 'comment' => 40,
                'visitor' => 60, 'subscriber' => 50, 'age' => 70, 'photo' => 60, 'text' => 70,
            ],
            'posts' => [],
        ];
    }

    public function test_visitor_chart_renders_with_axis_and_hover(): void
    {
        $v5 = [
            ['date' => '20260707', 'count' => 542], ['date' => '20260708', 'count' => 423],
            ['date' => '20260709', 'count' => 506], ['date' => '20260710', 'count' => 530],
            ['date' => '20260711', 'count' => 244],
        ];
        $html = view('console.blog._single', ['b' => $this->blog($v5), 'exportable' => null])->render();

        $this->assertStringContainsString('최근 방문자 추이', $html);
        // 117 스타일 구성요소: 호버 영역·세로 안내선·툴팁·피크 캡션
        $this->assertStringContainsString('vis-hover', $html);
        $this->assertStringContainsString('vis-vline', $html);
        $this->assertStringContainsString('최고 방문일은', $html);
        // y축 라벨(nice-step) — 542·244 → gridMax 600 (1000 미만이라 그대로 표기)
        $this->assertStringContainsString('>600<', $html);
        // 방문자수 툴팁 데이터
        $this->assertStringContainsString('542', $html);
    }

    public function test_visitor_chart_absent_when_no_data(): void
    {
        $html = view('console.blog._single', ['b' => $this->blog([]), 'exportable' => null])->render();
        $this->assertStringNotContainsString('최근 방문자 추이', $html);
    }
}
