<?php

namespace App\Jobs;

use App\Models\AppSetting;
use App\Models\MarketingOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 주문 접수 → 잔디(JANDI) 웹훅 알림 (2026-07-23).
 * 웹훅 URL 은 환경설정(광고·데이터 API 탭, jandi.order_webhook_url)에서 관리 — 비어 있으면 발송 안 함.
 * 슬랙 웹훅 주소(https://hooks.slack.com/…)를 넣으면 같은 내용을 슬랙 형식으로 보낸다(2026-10-07 슬랙 채널로 전환).
 * 주문 생성(OrderPlacer)과 분리된 큐 잡 — 알림 실패가 주문 접수에 영향을 주지 않는다.
 */
class SendJandiOrderNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    /** 새 주문 슬랙 알림 맨 앞 담당자 태그(2026-10-07 대표님 지시) */
    private const SLACK_MENTION = '<@U0C4ZMY6RPV>';

    public function __construct(public MarketingOrder $order) {}

    public function handle(): void
    {
        $url = trim((string) AppSetting::read('jandi.order_webhook_url'));
        if ($url === '') {
            return;
        }

        $o = $this->order->loadMissing('product');
        $fv = (array) $o->field_values;

        // 수량 — 일수량 과금(days 有)이면 quantity = 일수량, 전체 = 일수량 × 일수. 전체 과금이면 quantity 그대로.
        $qty = $o->days
            ? '전체 '.number_format($o->quantity * $o->days).' · 일 '.number_format($o->quantity)
            : '전체 '.number_format($o->quantity);
        $period = $o->days ? $o->days.'일' : null;
        $start = trim((string) ($fv['start_date'] ?? ''));
        $end = trim((string) ($fv['end_date'] ?? ''));
        if ($start !== '' || $end !== '') {
            $period = ($period ? $period.' · ' : '').($start ?: '?').' ~ '.($end ?: '?');
        }

        // 회원 누적 — 총 주문횟수·결제액(취소 제외)
        $sum = MarketingOrder::where('user_id', $o->user_id)->where('status', '!=', 'canceled');
        $memberStat = '주문 '.number_format((clone $sum)->count()).'회 · 결제 '.number_format((float) (clone $sum)->sum('total_price')).'원';

        $info = [
            ['title' => '상품', 'description' => (string) ($o->product?->title ?? '(삭제된 상품)')],
            ['title' => '수량', 'description' => $qty],
        ];
        if ($period) {
            $info[] = ['title' => '기간', 'description' => $period];
        }
        $info[] = ['title' => '금액', 'description' => number_format((float) $o->total_price).'원'];
        $info[] = ['title' => '주문자', 'description' => trim($o->orderer_name.' ('.$o->orderer_contact.')').' — '.$memberStat];
        if ($kw = $o->keywordFromFields()) {
            $info[] = ['title' => '키워드', 'description' => $kw];
        }

        // 주문 상세 링크 — 어드민 비밀 호스트(ADMIN_HOST) 우선, 없으면 앱 URL(로컬 테스트는 실도메인 보정).
        if (($ah = trim((string) config('rankfree.admin_host'))) !== '') {
            $orderUrl = 'https://'.$ah.'/admin/orders/'.$o->id;
        } else {
            $orderUrl = route('admin.orders.show', $o->id);
            if (str_contains($orderUrl, 'localhost') || str_contains($orderUrl, '127.0.0.1')) {
                $orderUrl = 'https://rankfree.kr/admin/orders/'.$o->id;
            }
        }

        if (str_starts_with($url, 'https://hooks.slack.com/')) {
            // 슬랙 — 대표님 형식(2026-10-07): 1줄 담당자 태그 · 주문 시간 / 2줄 [랭크프리] 상품 · 수량 · 금액 · 주문자 / 3줄 상세 링크
            $esc = fn ($v) => strtr((string) $v, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
            $summary = collect([
                ($o->product?->title ?? '(삭제된 상품)').(($kw = $o->keywordFromFields()) ? ' · '.$kw : ''),
                $qty,
                number_format((float) $o->total_price).'원',
                trim((string) $o->orderer_name),
            ])->filter(fn ($v) => $v !== '')->implode(' · ');
            $text = self::SLACK_MENTION.' '.($o->created_at ?? now())->format('m/d H:i')
                ."\n[랭크프리] ".$esc($summary)
                ."\n<".$orderUrl.'|주문 상세 보기 ›>';
            $res = Http::timeout(10)->post($url, ['text' => $text]);
        } else {
            $res = Http::timeout(10)->withHeaders(['Accept' => 'application/vnd.tosslab.jandi-v2+json'])
                ->post($url, [
                    // 주문번호 클릭 → 관리자 주문 상세
                    'body' => '[[새 주문 '.$o->order_no.']]('.$orderUrl.') 이(가) 접수되었습니다.',
                    'connectColor' => '#0052ff',
                    'connectInfo' => $info,
                ]);
        }

        if (! $res->successful()) {
            Log::warning('주문 알림 실패', ['order' => $o->order_no, 'status' => $res->status(), 'body' => mb_substr($res->body(), 0, 300)]);
            $res->throw();   // 재시도(tries=3) 유도
        }
    }
}
