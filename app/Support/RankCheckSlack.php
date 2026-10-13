<?php

namespace App\Support;

use App\Models\AppSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;

/**
 * 순위체크(플레이스·쇼핑) 한 회차가 끝났다는 슬랙 알림(2026-10-13 담당 직원 요청 「11시/16시 완료 즉시 채널로」).
 * 웹훅 주소는 관리자 환경설정 「순위체크 완료 알림 슬랙 웹훅」(slack.rank_done_webhook_url) — 비어 있으면 아무것도 안 보낸다.
 * 알림이 실패해도 순위체크 자체는 영향받지 않는다(예외를 삼킨다).
 */
class RankCheckSlack
{
    public static function done(string $kind, int $total, int $recorded, int $found, CarbonInterface $startedAt, bool $blocked = false): void
    {
        try {
            $url = trim((string) AppSetting::read('slack.rank_done_webhook_url'));
            if (! str_starts_with($url, 'https://hooks.slack.com/')) {
                return;
            }
            $kst = $startedAt->copy()->timezone('Asia/Seoul');
            $minutes = max(1, (int) round($startedAt->diffInSeconds(now()) / 60));
            $text = '*'.$kind.' 순위체크 완료* — '.$kst->format('m/d H:i').' 시작 · '
                .$recorded.'/'.$total.'건 기록 · 순위권 안 '.$found.' · 밖 '.max(0, $recorded - $found).' · 소요 '.$minutes.'분';
            if ($blocked || $recorded < $total) {
                $text .= "\n⚠️ 일부만 기록됨(".($total - $recorded).'건 남음) — '.($blocked ? '차단 감지로 중단' : '중간에 멈춤');
            }
            Http::timeout(10)->post($url, ['text' => $text, 'unfurl_links' => false, 'unfurl_media' => false]);
        } catch (\Throwable) {
            // 알림 실패는 무시
        }
    }
}
