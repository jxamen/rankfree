<?php

namespace App\Domain\Reward;

use App\Models\RewardMission;
use App\Models\RewardUser;

/**
 * 미션 채점 — 정답은 서버에만 있다(server-api-spec §2).
 * 해시태그형(tags 보유): 사용자별 tagIndex(결정적)의 태그와 정규화 비교.
 * 고정 정답형: number(숫자만 비교 + 오차 허용) / text(정규화 비교).
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

    /** @return array{correct: bool, norm: string} norm 은 로그 저장용(64자 절단) */
    public static function grade(RewardMission $mission, RewardUser $user, string $day, string $answer): array
    {
        $tags = array_values(array_filter((array) $mission->tags, fn ($t) => is_string($t) && trim($t) !== ''));

        if ($tags !== []) {
            $idx = TagIndex::for($user->user_key_hash, $mission->id, $day, count($tags));
            $norm = self::normalize($answer);

            return [
                'correct' => $norm !== '' && $norm === self::normalize($tags[$idx - 1]),
                'norm' => mb_substr($norm, 0, 64),
            ];
        }

        if ($mission->answer === null || $mission->answer === '') {
            return ['correct' => false, 'norm' => mb_substr(self::normalize($answer), 0, 64)];
        }

        if ($mission->answer_type === 'number') {
            $given = (string) preg_replace('/\D+/', '', $answer);
            $expect = (string) preg_replace('/\D+/', '', (string) $mission->answer);
            if ($given === '' || $expect === '') {
                return ['correct' => false, 'norm' => mb_substr($given, 0, 64)];
            }
            $correct = $mission->tolerance_percent
                ? abs((float) $given - (float) $expect) <= (float) $expect * $mission->tolerance_percent / 100
                : $given === $expect;

            return ['correct' => $correct, 'norm' => mb_substr($given, 0, 64)];
        }

        $norm = self::normalize($answer);

        return ['correct' => $norm !== '' && $norm === self::normalize((string) $mission->answer), 'norm' => mb_substr($norm, 0, 64)];
    }
}
