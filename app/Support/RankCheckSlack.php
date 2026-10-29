<?php

namespace App\Support;

use App\Models\AppSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 순위체크(플레이스·쇼핑) 한 회차가 끝났다는 슬랙 알림(2026-10-13 담당 직원 요청 「11시/16시 완료 즉시 채널로」).
 * 웹훅 주소: 관리자 환경설정 「순위체크 완료 알림 슬랙 웹훅」(slack.rank_done_webhook_url) → 비면 「제이커브-단체」 웹훅
 * (미주문 알림과 같은 SLACK_JCURVE_GROUP_WEBHOOK · slack.missed_order_webhook_url, 2026-10-13 대표님: 새 웹훅은 부탁하지 않고 기존 것 공유).
 * 다 비어 있으면 아무것도 안 보낸다. 담당자 태그는 미주문 알림과 같다(services.slack.order_reminder_mentions).
 * 알림이 실패해도 순위체크 자체는 영향받지 않는다(예외는 던지지 않고 로그 「순위체크 알림 실패 · 코드」 한 줄만 남긴다).
 */
class RankCheckSlack
{
    /** @return string|null 전송 결과 「HTTP 200 ok」 형태(주소가 없어 보내지 않았으면 null) */
    public static function done(string $kind, int $total, int $recorded, int $found, CarbonInterface $startedAt, bool $blocked = false): ?string
    {
        try {
            $url = trim((string) AppSetting::read('slack.rank_done_webhook_url'))
                ?: trim((string) config('services.slack.jcurve_group_webhook'))
                ?: trim((string) AppSetting::read('slack.missed_order_webhook_url'));
            if (! str_starts_with($url, 'https://hooks.slack.com/')) {
                return null;
            }
            $kst = $startedAt->copy()->timezone('Asia/Seoul');
            $minutes = max(1, (int) round($startedAt->diffInSeconds(now()) / 60));
            $mentions = collect(explode(',', (string) config('services.slack.order_reminder_mentions')))
                ->map(fn ($id) => trim($id))->filter(fn ($id) => preg_match('/^[UW][A-Z0-9]+$/', $id))
                ->map(fn ($id) => '<@'.$id.'> ')->implode('');
            $text = $mentions.'*'.$kind.' 순위체크 완료* — '.$kst->format('m/d H:i').' 시작 · '
                .$recorded.'/'.$total.'건 기록 · 순위권 안 '.$found.' · 밖 '.max(0, $recorded - $found).' · 소요 '.$minutes.'분';
            if ($blocked || $recorded < $total) {
                $text .= "\n⚠️ 일부만 기록됨(".($total - $recorded).'건 남음) — '.($blocked ? '차단 감지로 중단' : '중간에 멈춤');
            }
            $res = Http::timeout(10)->post($url, ['text' => $text, 'unfurl_links' => false, 'unfurl_media' => false]);
            if (! $res->successful() || trim($res->body()) !== 'ok') {
                Log::warning('순위체크 알림 실패 · 코드 '.$res->status().' '.mb_substr(trim($res->body()), 0, 60));
            }

            return 'HTTP '.$res->status().' '.mb_substr(trim($res->body()), 0, 60);
        } catch (\Throwable $e) {
            // 주소는 로그에 남기지 않는다 — 예외 메시지에 URL 이 들어갈 수 있어 클래스명만 적는다
            Log::warning('순위체크 알림 실패 · 코드 예외 '.class_basename($e));

            return '예외 '.class_basename($e);
        }
    }
}
