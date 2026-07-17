<?php

namespace App\Console\Commands;

use App\Domain\Keyword\KeywordMasterSync;
use Illuminate\Console\Command;

/**
 * 키워드 마스터 재구축 — 시딩·대량 상태변경처럼 모델 이벤트를 우회하는 경로 뒤에 돌린다.
 * 마스터는 candidates 에서 파생되는 목록 전용 테이블이라 언제 돌려도 안전하다.
 */
class KeywordsSync extends Command
{
    protected $signature = 'keywords:sync {--type= : place|shopping (생략 시 전체)}';

    protected $description = '키워드 마스터(keywords)를 후보(keyword_candidates)에서 재구축한다';

    public function handle(KeywordMasterSync $sync): int
    {
        $type = $this->option('type');
        if ($type && ! in_array($type, ['place', 'shopping'], true)) {
            $this->error('--type 은 place 또는 shopping 이어야 합니다.');

            return self::FAILURE;
        }

        $t0 = microtime(true);
        $n = $sync->rebuild($type);
        $this->info(sprintf('키워드 마스터 %s개 동기화 완료 (%.1f초)', number_format($n), microtime(true) - $t0));

        return self::SUCCESS;
    }
}
