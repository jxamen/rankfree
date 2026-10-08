<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\MarketingOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * 부스팅샵 미주문 알림(2026-10-07 대표님 지시) — 매일 09·13·17시에 아직 부스팅샵으로 주문이 안 들어간
 * 랭크프리 주문을 세어 1건 이상이면 슬랙(SLACK_JCURVE_GROUP_WEBHOOK)으로 알린다.
 *
 * 미처리 = 접수·진행중 + 부스팅샵 대상(플레이스·저장 = 플레이스 주소, 쇼핑 = 상품 주소·키워드 — boostingService())
 *        + 전송된 발주가 하나도 없음(부스팅샵 · 그 밖의 업체 모두). 부스팅샵 연동 전에 구글시트 업체로
 *          보낸 옛 주문이 매번 반복해 잡히지 않게 한다(운영 실측 11건 중 9건이 MDL 시트로 이미 전송, 2026-10-07).
 * 쇼핑은 유입키워드 분석 → Short URL → 부스팅샵 주문 순서라 어느 단계에 멈췄는지 함께 적는다.
 * 고객 이름·연락처·키워드는 알림에 넣지 않는다.
 */
class OrdersBoostingReminder extends Command
{
    protected $signature = 'orders:boosting-reminder {--dry-run : 슬랙으로 보내지 않고 목록만 출력}';

    protected $description = '부스팅샵으로 아직 주문하지 않은 랭크프리 주문을 슬랙으로 알린다';

    private const MAX_LINES = 20;

    /** 밀린 주문으로 세기까지 기다리는 시간 — 새 주문 알림보다 먼저 가지 않게 */
    private const MIN_AGE_HOURS = 3;

    public function handle(): int
    {
        $orders = MarketingOrder::with('product')
            ->whereIn('status', ['pending', 'processing'])
            ->whereDoesntHave('dispatches', fn ($q) => $q->where('status', 'sent'))
            // 들어온 지 3시간 이상 지난 것만 「밀린 주문」(2026-10-08 대표님 「밀린 주문은 최소 3시간 이상 지난것만 체크해줘」)
            ->where('created_at', '<=', now()->subHours(self::MIN_AGE_HOURS))
            ->orderBy('created_at')
            ->get()
            ->filter(fn (MarketingOrder $o) => $o->boostingService() !== null)
            ->values();

        if ($orders->isEmpty()) {
            $this->info('미처리 0건 — 알림 없음');

            return self::SUCCESS;
        }

        $rows = $orders->map(fn (MarketingOrder $o) => [
            'order' => $o,
            // 플레이스 저장은 따로 센다 — 부스팅샵도 유입/저장을 상품으로만 나눠, 상품명의 '저장'으로 구분한다
            'type' => $o->boostingService() === 'shopping' ? '쇼핑'
                : (str_contains((string) $o->product?->title, '저장') ? '저장' : '플레이스'),
            'stage' => $this->stage($o),
        ]);
        // 대표님 형식(2026-10-07 16:23): 「밀린 주문 — 랭크프리 쇼핑 N건 · 플레이스 N건 · 저장 N건」, 0건 종류는 뺀다
        $counts = $rows->countBy('type');
        $byType = collect(['쇼핑', '플레이스', '저장'])->filter(fn ($t) => ($counts[$t] ?? 0) > 0)
            ->map(fn ($t) => "{$t} {$counts[$t]}건")->implode(' · ');

        $esc = fn ($v) => strtr((string) $v, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
        // 담당자 태그(김채연2 — 2026-10-07 대표님 요청). 쉼표로 여러 명
        $mentions = collect(explode(',', (string) config('services.slack.order_reminder_mentions')))
            ->map(fn ($id) => trim($id))->filter(fn ($id) => preg_match('/^[UW][A-Z0-9]+$/', $id))
            ->map(fn ($id) => '<@'.$id.'> ')->implode('');
        $text = $mentions.'*밀린 주문 — 랭크프리 '.$byType.'*';
        foreach ($rows->take(self::MAX_LINES) as $r) {
            $o = $r['order'];
            $text .= "\n• <".$this->orderUrl($o).'|'.$esc($o->order_no).'>  '.$r['type'].' · '.$esc($o->product?->title ?? '(삭제된 상품)')
                .' · 주문 '.$o->created_at?->timezone('Asia/Seoul')->format('m/d H:i').' · '.$r['stage'];
        }
        if ($orders->count() > self::MAX_LINES) {
            $text .= "\n…외 ".($orders->count() - self::MAX_LINES).'건 — <'.$this->adminUrl('/admin/orders', route('admin.orders')).'|주문 목록>';
        }

        if ($this->option('dry-run')) {
            $this->line($text);

            return self::SUCCESS;
        }

        // .env(SLACK_JCURVE_GROUP_WEBHOOK)가 비면 관리자 「놓친 주문 알림 슬랙 웹훅」(slack.missed_order_webhook_url) — 제이커브-단체.
        // 「주문 알림 웹훅」(신규 주문 채널)은 읽지 않는다 — 비밀 값은 대표님이 어드민 칸에 넣는다
        $url = trim((string) config('services.slack.jcurve_group_webhook'))
            ?: trim((string) AppSetting::read('slack.missed_order_webhook_url'));
        if (! str_starts_with($url, 'https://hooks.slack.com/')) {
            $this->warn('「제이커브-단체」 웹훅(SLACK_JCURVE_GROUP_WEBHOOK)·관리자 「놓친 주문 알림 슬랙 웹훅」이 모두 비어 보내지 않음');

            return self::SUCCESS;
        }
        // 링크 미리보기 끄기(대표님 16:42 「링크 미리보기 불편」) — 주문 상세 링크가 여러 개라 미리보기가 글을 덮는다
        $res = Http::timeout(10)->post($url, ['text' => $text, 'unfurl_links' => false, 'unfurl_media' => false]);
        if (! $res->successful()) {
            $this->error('슬랙 전송 실패 HTTP '.$res->status());

            return self::FAILURE;
        }
        $this->info('미처리 '.$orders->count().'건 알림 전송');

        return self::SUCCESS;
    }

    /** 다음에 할 일 — 쇼핑은 분석 → Short URL → 부스팅샵 주문, 플레이스·저장은 바로 부스팅샵 주문. */
    private function stage(MarketingOrder $o): string
    {
        if ($o->boostingService() !== 'shopping') {
            return '부스팅샵 주문 필요';
        }
        $analysis = $o->shopKeywordAnalyses()->latest('id')->first();
        if (! $analysis) {
            return '유입키워드 분석 전';
        }
        if (! $analysis->shortLinks()->exists()) {
            return '유입키워드 분석 중 · Short URL 없음';
        }

        return 'Short URL 준비됨 · 부스팅샵 주문 필요';
    }

    /** 관리자 링크 — 어드민 비밀 호스트(ADMIN_HOST) 우선, 비면 라우트 URL. */
    private function adminUrl(string $path, string $fallback): string
    {
        $ah = trim((string) config('rankfree.admin_host'));

        return $ah !== '' ? 'https://'.$ah.$path : $fallback;
    }

    private function orderUrl(MarketingOrder $o): string
    {
        return $this->adminUrl('/admin/orders/'.$o->id, route('admin.orders.show', $o->id));
    }
}
