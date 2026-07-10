<?php

namespace App\Console\Commands;

use App\Domain\SearchAd\SearchAdClient;
use Illuminate\Console\Command;

/** 네이버 검색광고 범용 클라이언트 점검용 CLI — 여러 곳 재사용 전 동작 확인. */
class SearchAdKeywords extends Command
{
    protected $signature = 'searchad:keywords {keyword : 힌트 키워드} {--related=10 : 연관 키워드 표시 수}';

    protected $description = '네이버 검색광고 API로 키워드 월간 검색량·경쟁강도 조회';

    public function handle(SearchAdClient $client): int
    {
        if (! $client->configured()) {
            $this->error('NAVER_SEARCHAD_* (.env) 자격증명이 설정되지 않았습니다.');

            return self::FAILURE;
        }

        $kw = (string) $this->argument('keyword');
        $rows = $client->keywordTool([$kw], true);
        if (! $rows) {
            $this->warn('결과가 없습니다 (또는 호출 실패).');

            return self::FAILURE;
        }

        $norm = fn ($s) => mb_strtoupper(str_replace(' ', '', trim((string) $s)));
        $num = fn ($v) => is_numeric($v) ? (int) $v : 0;
        $main = null;
        $related = [];
        foreach ($rows as $r) {
            if (empty($r['relKeyword'])) {
                continue;
            }
            $item = [
                'kw' => (string) $r['relKeyword'],
                'pc' => $num($r['monthlyPcQcCnt'] ?? 0),
                'mo' => $num($r['monthlyMobileQcCnt'] ?? 0),
                'comp' => (string) ($r['compIdx'] ?? '-'),
            ];
            $item['total'] = $item['pc'] + $item['mo'];
            if ($main === null && $norm($item['kw']) === $norm($kw)) {
                $main = $item;
            } else {
                $related[] = $item;
            }
        }
        $main ??= array_shift($related);

        if ($main) {
            $this->info(sprintf(
                '%s — 월간검색 PC %s · 모바일 %s · 합계 %s · 경쟁 %s',
                $main['kw'], number_format($main['pc']), number_format($main['mo']), number_format($main['total']), $main['comp'],
            ));
        }

        usort($related, fn ($a, $b) => $b['total'] <=> $a['total']);
        $this->table(
            ['연관 키워드', 'PC', '모바일', '합계', '경쟁'],
            array_map(
                fn ($r) => [$r['kw'], number_format($r['pc']), number_format($r['mo']), number_format($r['total']), $r['comp']],
                array_slice($related, 0, (int) $this->option('related')),
            ),
        );

        return self::SUCCESS;
    }
}
