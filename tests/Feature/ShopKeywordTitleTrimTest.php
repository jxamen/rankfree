<?php

namespace Tests\Feature;

use App\Domain\Shopping\ShopKeywordExposureAnalyzer;
use ReflectionClass;
use Tests\TestCase;

/**
 * 제목 토큰 trim 의 UTF-8 안전성(2026-07-22 프로드 500 회귀) —
 * 바이트 단위 trim 문자 목록에 멀티바이트(·)가 있으면 "캐비넷"(끝 바이트 0xB7)류 한글이
 * 반쪽으로 잘려 깨진 UTF-8 이 되고, preg_replace('/\s+/u')가 null 을 반환해
 * hasNegative(null) TypeError → 분석 생성이 500 으로 죽는다.
 */
class ShopKeywordTitleTrimTest extends TestCase
{
    private function analyzer(): ShopKeywordExposureAnalyzer
    {
        return app(ShopKeywordExposureAnalyzer::class);
    }

    public function test_byte_trim_would_break_utf8(): void
    {
        // 전제 재현 — 이 파일이 고치는 원인 자체가 유효한지 고정
        $broken = trim('캐비넷', " \t.,·()[]{}\"'");
        $this->assertFalse(mb_check_encoding($broken, 'UTF-8'));
    }

    public function test_title_phrases_survive_multibyte_edge_tokens(): void
    {
        $a = $this->analyzer();
        $m = (new ReflectionClass($a))->getMethod('titlePhrases');

        $phrases = $m->invoke($a, '철제 캐비넷 이동식 서랍장', 5);

        $this->assertNotEmpty($phrases);
        foreach ($phrases as $p) {
            $this->assertTrue(mb_check_encoding($p, 'UTF-8'), "깨진 UTF-8: ".bin2hex($p));
        }
        $this->assertContains('철제 캐비넷', $phrases);
    }

    public function test_title_words_survive_multibyte_edge_tokens(): void
    {
        $a = $this->analyzer();
        $m = (new ReflectionClass($a))->getMethod('titleWords');

        $words = $m->invoke($a, '사물함', '', '철제 캐비넷 이동식 (2단)');

        foreach ($words as $w) {
            $this->assertTrue(mb_check_encoding($w, 'UTF-8'), "깨진 UTF-8: ".bin2hex($w));
        }
        $this->assertContains('캐비넷', $words);
    }
}
