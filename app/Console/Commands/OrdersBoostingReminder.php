<?php

namespace App\Console\Commands;

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

    public function handle(): int
    {
        $orders = MarketingOrder::with('product')
            ->whereIn('status', ['pending', 'processing'])
            ->whereDoesntHave('dispatches', fn ($q) => $q->where('status', 'sent'))
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
            'type' => $o->boostingService() === 'shopping' ? '쇼핑' : '플레이스',
            'stage' => $this->stage($o),
        ]);
        $byType = $rows->countBy('type')->map(fn ($n, $t) => "{$t} {$n}건")->implode(' · ');

        $esc = fn ($v) => strtr((string) $v, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
        $text = '*부스팅샵 미주문 '.$orders->count().'건* ('.$byType.') — '.now('Asia/Seoul')->format('m/d H:i').' 기준';
        foreach ($rows->take(self::MAX_LINES) as $r) {
            $o = $r['order'];
            $text .= "\n• <".$this->orderUrl($o).'|'.$esc($o->order_no).'>  '.$r['type'].' · '.$esc($o->product?->title ?? '(삭제된 상품)')
                .' · 주문 '.$o->created_at?->timezone('Asia/Seoul')->format('m/d H:i').' · '.$r['stage'];
        }
        if ($orders->count() > self::MAX_LINES) {
            $text .= "\n…외 ".($orders->count() - self::MAX_LINES).'건 — <'.$this->adminBase().'/admin/orders|주문 목록>';
        }

        if ($this->option('dry-run')) {
            $this->line($text);

            return self::SUCCESS;
        }

        $url = trim((string) config('services.slack.jcurve_group_webhook'));
        if (! str_starts_with($url, 'https://hooks.slack.com/')) {
            $this->warn('「제이커브-단체」 웹훅(SLACK_JCURVE_GROUP_WEBHOOK)이 없어 보내지 않음');

            return self::SUCCESS;
        }
        $res = Http::timeout(10)->post($url, ['text' => $text]);
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

    private function adminBase(): string
    {
        $ah = trim((string) config('rankfree.admin_host'));

        return $ah !== '' ? 'https://'.$ah : 'https://rankfree.kr';
    }

    private function orderUrl(MarketingOrder $o): string
    {
        return $this->adminBase().'/admin/orders/'.$o->id;
    }
}
