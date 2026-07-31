<?php

namespace App\Domain\Reward;

/**
 * 해시태그 출제 번호 — 사용자×미션×참여일로 결정적(HANDOFF §6-3, server-api-spec §2-2).
 * 매번 새로 뽑으면 새로고침 재추첨 어뷰징이 생기고 화면과 채점이 어긋난다.
 * 단순 해시는 역산될 수 있어 서버 비밀키(HMAC-SHA256)를 쓴다.
 */
final class TagIndex
{
    public static function for(string $userKeyHash, int $missionId, string $day, int $tagCount): int
    {
        if ($tagCount < 1) {
            return 1;
        }

        $h = hash_hmac('sha256', $userKeyHash.'|'.$missionId.'|'.$day, (string) config('app.key'));

        return 1 + (hexdec(substr($h, 0, 8)) % $tagCount);
    }
}
