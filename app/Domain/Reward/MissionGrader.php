<?php

namespace App\Domain\Reward;

use App\Models\RewardMission;
use App\Models\RewardUser;

/**
 * 미션 채점 — 정답은 서버에만 있다(server-api-spec §2).
 * 해시태그형(tags 보유): 사용자별 tagIndex(결정적)의 태그와 정규화 비교.
 * 고정 정답형: number(숫자만 비교 + 오차 허용) / text(정규화 비교) / contains(제출값에 정답이 들어 있으면 통과).
 * 스크린샷 증빙(저장·찜)은 텍스트로 물을 수 없어 [ImageProofVerifier](ImageProofVerifier.php) 가 따로 판정한다.
 */
final class MissionGrader
{
    /** 정규화 — 앞뒤 공백 → 맨 앞 # 전부 → 모든 공백 제거 → 소문자 (클라 normalizeTag 와 동일 규칙) */
    public static function normalize(string $s): string
    {
        $s = trim($s);
        $s = ltrim($s, '#');
        $s = (string) preg_replace('/\s+/u', '', $s);

        return mb_strtolower($s);
    }

    /**
     * 정답을 무엇으로 낼지는 설정이다(어드민 '정답 소스'). 미션별 answer_type 이 최우선이고,
     * 비어 있으면 config('reward.answer_source') 를 따른다.
     *
     * @return array{correct: bool, norm: string} norm 은 로그 저장용(64자 절단)
     */
    public static function grade(RewardMission $mission, RewardUser $user, string $day, string $answer): array
    {
        $tags = array_values(array_filter((array) $mission->tags, fn ($t) => is_string($t) && trim($t) !== ''));
        $source = $mission->answer_type ?: (string) config('reward.answer_source', 'tag');

        // 해시태그형 — 참여자마다 다른 N번째 태그. 태그가 없으면 고정 정답으로 폴백한다
        if (($source === 'tag' || $source === '') && $tags !== []) {
            $idx = TagIndex::for($user->user_key_hash, $mission->id, $day, count($tags));
            $norm = self::normalize($answer);

            return [
                'correct' => $norm !== '' && $norm === self::normalize($tags[$idx - 1]),
                'norm' => mb_substr($norm, 0, 64),
            ];
        }

        // 판매가형 — 상품 가격을 맞춘다(오차 허용은 tolerance_percent)
        if ($source === 'price') {
            return self::gradeNumber($answer, (string) ($mission->product_price ?? ''), $mission->tolerance_percent);
        }

        if ($mission->answer === null || $mission->answer === '') {
            // 설정이 고정 정답인데 값이 없다 — 태그가 있으면 그걸로라도 채점한다(미션이 죽지 않게)
            if ($tags !== []) {
                $idx = TagIndex::for($user->user_key_hash, $mission->id, $day, count($tags));
                $norm = self::normalize($answer);

                return ['correct' => $norm !== '' && $norm === self::normalize($tags[$idx - 1]),
                    'norm' => mb_substr($norm, 0, 64)];
            }

            return ['correct' => false, 'norm' => mb_substr(self::normalize($answer), 0, 64)];
        }

        if ($source === 'number') {
            return self::gradeNumber($answer, (string) $mission->answer, $mission->tolerance_percent);
        }

        // 포함형 — 제출값 안에 정답이 들어 있으면 통과(2026-08-28).
        // 플레이스 유입처럼 '그 업체 화면에서 온 URL' 을 확인할 때 쓴다: 참여자가 붙여넣는 주소에는
        // 정답(고유번호·주소 조각) 외에 쿼리스트링이 붙어 있어 완전일치로는 잡을 수 없다.
        if ($source === 'contains') {
            $norm = self::normalize($answer);
            $expect = self::normalize((string) $mission->answer);

            return [
                'correct' => $norm !== '' && $expect !== '' && str_contains($norm, $expect),
                'norm' => mb_substr($norm, 0, 64),
            ];
        }

        $norm = self::normalize($answer);

        return ['correct' => $norm !== '' && $norm === self::normalize((string) $mission->answer), 'norm' => mb_substr($norm, 0, 64)];
    }

    /** 숫자 비교 — 숫자만 남겨 비교하고, tolerance_percent 가 있으면 그만큼 오차를 허용한다 */
    private static function gradeNumber(string $answer, string $expected, ?int $tolerancePercent): array
    {
        $given = (string) preg_replace('/\D+/', '', $answer);
        $expect = (string) preg_replace('/\D+/', '', $expected);
        if ($given === '' || $expect === '') {
            return ['correct' => false, 'norm' => mb_substr($given, 0, 64)];
        }

        $correct = $tolerancePercent
            ? abs((float) $given - (float) $expect) <= (float) $expect * $tolerancePercent / 100
            : $given === $expect;

        return ['correct' => $correct, 'norm' => mb_substr($given, 0, 64)];
    }
}
