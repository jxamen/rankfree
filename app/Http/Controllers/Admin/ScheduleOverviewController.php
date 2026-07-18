<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * 자동 수집 현황 — 스케줄러(routes/console.php)에 등록된 자동 작업과 데이터별 최근 수집 시각(열람 전용).
 * 별도 관리 목록을 두지 않고 스케줄 정의를 그대로 읽는다 — 스케줄을 고치면 이 화면도 따라온다.
 */
class ScheduleOverviewController extends Controller
{
    /** 커맨드별 표시 정보 — 무엇을 수집하는지(desc), 수집이 아닌 작업은 note 로 구분 */
    private const META = [
        'searchadweb:login' => ['desc' => '검색광고 웹 세션 갱신 — 키워드 성별·연령·트렌드 조회용 로그인 세션(만료 시에만 재로그인)'],
        'community:simulate' => ['desc' => '커뮤니티 페르소나 자동 활동 — 글·댓글·좋아요 소량 생성'],
        'place:track-run' => ['desc' => '플레이스 순위추적 — 활성 슬롯의 키워드 순위 조회·기록'],
        'shop:track-run' => ['desc' => '쇼핑 순위추적 — 활성 슬롯의 상품 순위 조회·기록'],
        'smartplace:collect' => ['desc' => '스마트플레이스 리포트 — 연동 계정의 통계·리뷰·스마트콜·예약'],
        'gsc:collect' => ['desc' => '구글 서치 콘솔 — 검색 유입 쿼리·페이지 성과(원천이 2~3일 지연)'],
        'ga:collect' => ['desc' => 'GA4 방문 통계 — 유입·랜딩·기기·이벤트'],
        'cafe:crawl' => ['desc' => '카페 글감 — 인기글·본문·댓글 수집 후 커뮤니티 글밥 전환'],
        'sitemap:refresh' => ['desc' => '분석 공유 슬러그 백필 + 사이트맵 캐시 갱신', 'note' => '수집 없음(유지보수)'],
        'newbiz:collect' => ['desc' => '신규 개업 — 인허가 공공데이터 수집 → 플레이스 등록 확인·전화 수집(한 흐름)'],
        'newbiz:place-match' => ['desc' => '신규 개업 — 플레이스 재확인 주기 도래분 캐치업'],
        'hub:publish' => ['desc' => '키워드 허브 — 승인 후보 발행(공개 문서 생성)'],
        'hub:auto-publish' => ['desc' => '키워드 허브 — 자동 발행 토글 켜짐 시 유형별 배치 발행(꺼져 있으면 즉시 종료)'],
        'hub:partition-rotate' => ['desc' => '허브 순위 매핑 월 파티션 선생성·보존기간 지난 월 파기', 'note' => '수집 없음(유지보수)'],
        'hub:collect' => ['desc' => '키워드 허브 — 카테고리 시드·지역조합 후보 수집'],
        'hub:discover' => ['desc' => '키워드 허브 — GSC 유입 쿼리에서 후보 발굴'],
        'hub:refresh' => ['desc' => '키워드 허브 — 발행 문서 검색량·데이터 갱신'],
        'hub:shopping-collect' => ['desc' => '키워드 허브 — 데이터랩 쇼핑인사이트 인기검색어 후보'],
    ];

    public function index()
    {
        // HTTP 컨텍스트에선 routes/console.php(스케줄 정의)가 로드되지 않는다 → 콘솔 커널 부트스트랩으로 로드.
        // (commandsLoaded 가드가 있어 중복 로드는 없다)
        app(ConsoleKernel::class)->bootstrap();

        $jobs = [];
        foreach (app(Schedule::class)->events() as $event) {
            // "'php' 'artisan' newbiz:collect" → "newbiz:collect" (옵션 포함). 따옴표는 OS 별로 다르다(리눅스 '…', 윈도 "…")
            $cmd = preg_match('/["\']artisan["\']\s+(.+)$/', (string) $event->command, $m)
                ? trim($m[1])
                : $event->getSummaryForDisplay();
            $next = rescue(fn () => Carbon::instance($event->nextRunDate())->setTimezone('Asia/Seoul'), null, false);

            if (isset($jobs[$cmd])) {
                // 같은 커맨드가 여러 시각에 걸린 경우(place:track-run 11:30·16:30) 한 줄로 합친다
                $jobs[$cmd]['freq'] .= ' · '.$this->freqKo($event->expression);
                if ($next && (! $jobs[$cmd]['next'] || $next->lt($jobs[$cmd]['next']))) {
                    $jobs[$cmd]['next'] = $next;
                }

                continue;
            }

            $base = Str::before($cmd, ' ');
            $meta = self::META[$base] ?? [];
            $jobs[$cmd] = [
                'command' => $cmd,
                'desc' => $meta['desc'] ?? '',
                'freq' => $this->freqKo($event->expression),
                'next' => $next,
                'last' => $this->lastAt($base),
                'last_note' => $meta['note'] ?? null,
            ];
        }

        $gates = [
            ['label' => '커뮤니티 자동 활동', 'covers' => 'community:simulate', 'env' => 'COMMUNITY_SCHEDULE_ENABLED', 'on' => (bool) config('rankfree.community.schedule_enabled', true)],
            ['label' => '카페 글감 수집', 'covers' => 'cafe:crawl', 'env' => 'CAFE_CRAWL_SCHEDULE_ENABLED', 'on' => (bool) config('rankfree.cafe_crawl.schedule_enabled', true)],
            ['label' => '신규 개업 수집', 'covers' => 'newbiz:collect · place-match', 'env' => 'NEWBIZ_SCHEDULE_ENABLED', 'on' => (bool) config('rankfree.newbiz.schedule_enabled', false)],
            ['label' => '허브 승인 발행', 'covers' => 'hub:publish', 'env' => 'HUB_PUBLISH_ENABLED', 'on' => (bool) config('rankfree.hub.publish_enabled', true)],
            ['label' => '허브 발굴·갱신', 'covers' => 'hub:collect · discover · refresh · shopping-collect', 'env' => 'HUB_SCHEDULE_ENABLED', 'on' => (bool) config('rankfree.hub.schedule_enabled', false)],
        ];

        return view('admin.schedule', ['jobs' => array_values($jobs), 'gates' => $gates]);
    }

    /**
     * 커맨드별 최근 수집 시각 — 각 수집기가 실제로 쓰는 타임스탬프 컬럼에서 뽑는다.
     * ⚠️ 커뮤니티 글의 created_at 은 자연스러움을 위해 최대 4시간 백데이트되므로 personas.last_acted_at 을 쓴다.
     * ⚠️ gsc/ga 의 date 는 데이터 귀속 날짜(원천 2~3일 지연)라 수집 시각이 아니다 → updated_at.
     */
    private function lastAt(string $base): ?Carbon
    {
        $ts = rescue(fn () => match ($base) {
            'searchadweb:login' => \App\Models\NaverAdSession::query()->max('checked_at'),
            'community:simulate' => \App\Models\Persona::query()->max('last_acted_at'),
            'place:track-run' => \App\Models\PlaceRankRecord::query()->max('created_at'),
            'shop:track-run' => \App\Models\ShopRankRecord::query()->max('created_at'),
            'smartplace:collect' => \App\Models\SmartplaceAccount::query()->max('last_collected_at'),
            'gsc:collect' => \App\Models\GscStat::query()->max('updated_at'),
            'ga:collect' => \App\Models\GaStat::query()->max('updated_at'),
            'cafe:crawl' => \App\Models\CafeCrawlArticle::query()->max('crawled_at'),
            'newbiz:collect' => \App\Models\NewBusiness::query()->max('collected_at'),
            'newbiz:place-match' => \App\Models\NewBusiness::query()->max('place_checked_at'),
            'hub:publish', 'hub:auto-publish', 'hub:refresh' => \App\Models\KeywordSearch::query()->where('origin', 'hub')->max('refreshed_at'),
            'hub:collect' => \App\Models\KeywordCategory::query()->max('collected_at'),
            'hub:discover' => \App\Models\KeywordCandidate::query()->where('source', 'gsc')->max('created_at'),
            'hub:shopping-collect' => \App\Models\KeywordCandidate::query()->where('source', 'datalab')->max('created_at'),
            default => null,
        }, null, false);

        return $ts ? Carbon::parse($ts)->setTimezone('Asia/Seoul') : null;
    }

    /** cron 표현식 → 한글 주기(이 프로젝트가 쓰는 패턴만 — 그 외엔 원문 표기) */
    private function freqKo(string $expr): string
    {
        return match (true) {
            $expr === '* * * * *' => '매분',
            (bool) preg_match('#^\*/(\d+) \* \* \* \*$#', $expr, $m) => "{$m[1]}분마다",
            (bool) preg_match('#^0 \* \* \* \*$#', $expr) => '매시간',
            (bool) preg_match('#^(\d+) \* \* \* \*$#', $expr, $m) => "매시 {$m[1]}분",
            (bool) preg_match('#^(\d+) (\d+) \* \* \*$#', $expr, $m) => sprintf('매일 %02d:%02d', $m[2], $m[1]),
            (bool) preg_match('#^(\d+) (\d+) \* \* (\d+)$#', $expr, $m) => sprintf('매주 %s요일 %02d:%02d', ['일', '월', '화', '수', '목', '금', '토'][(int) $m[3] % 7], $m[2], $m[1]),
            default => $expr,
        };
    }
}
