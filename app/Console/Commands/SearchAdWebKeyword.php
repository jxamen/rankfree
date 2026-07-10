<?php

namespace App\Console\Commands;

use App\Domain\SearchAdWeb\SearchAdWebClient;
use Illuminate\Console\Command;

/** 웹세션으로 키워드 성별/연령/월별 트렌드 조회 (점검용). */
class SearchAdWebKeyword extends Command
{
    protected $signature = 'searchadweb:keyword {keyword} {--relogin : 401 시 자동 재로그인 후 재시도}';

    protected $description = '네이버 검색광고 웹세션으로 성별·연령·월별 트렌드 조회';

    public function handle(SearchAdWebClient $client): int
    {
        $kw = (string) $this->argument('keyword');
        $r = $client->keywordDetail($kw);

        if (($r['error'] ?? null) === 'unauthorized' && $this->option('relogin')) {
            $this->warn('세션 만료 → 재로그인 후 재시도');
            $this->call('searchadweb:login');
            $r = $client->keywordDetail($kw);
        }

        if (isset($r['error'])) {
            $hint = match ($r['error']) {
                'no_session' => ' (searchadweb:login 먼저 실행)',
                'unauthorized' => ' (searchadweb:login 재실행 필요, 또는 --relogin)',
                default => '',
            };
            $this->error('오류: '.$r['error'].$hint);

            return self::FAILURE;
        }

        $this->info($r['keyword'].' — 성별  여 '.$r['gender']['female_pct'].'% / 남 '.$r['gender']['male_pct'].'%');
        $this->table(
            ['연령', '검색수', '비율'],
            array_map(fn ($a) => [$a['age'], number_format($a['total']), $a['pct'].'%'], $r['age']),
        );
        $this->line('월별 트렌드: '.collect($r['monthly'])->map(fn ($m) => $m['label'].' '.number_format($m['total']))->implode('  '));

        return self::SUCCESS;
    }
}
