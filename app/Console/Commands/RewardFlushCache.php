<?php

namespace App\Console\Commands;

use App\Domain\Reward\RewardCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * 캐시 복구 = 비우고 다시 채우기(§7-7) — 모든 리워드 캐시 값은 DB 에서 재생성 가능하다.
 * l1 은 서버별 실행 필요(APCu 는 서버 로컬), file 은 그 서버의 파일 폴백 삭제.
 */
class RewardFlushCache extends Command
{
    protected $signature = 'reward:flush-cache {--layer=all : all|l1|l2|file}';

    protected $description = '리워드 캐시를 계층별로 비운다(DB 원장은 건드리지 않는다)';

    public function handle(): int
    {
        $layer = (string) $this->option('layer');

        if (in_array($layer, ['all', 'l1'], true)) {
            RewardCache::flushLocal();
            if (function_exists('apcu_delete') && class_exists(\APCUIterator::class)) {
                apcu_delete(new \APCUIterator('/^reward:/'));
            }
            $this->info('L1 비움');
        }

        if (in_array($layer, ['all', 'l2'], true) && ($store = config('reward.cache.l2_store'))) {
            try {
                Cache::store($store)->flush();   // 전용 스토어(reward 전용 연결)라 flush 가 안전하다
                $this->info('L2 비움');
            } catch (\Throwable $e) {
                $this->warn('L2 비우기 실패: '.$e->getMessage());
            }
        }

        if (in_array($layer, ['all', 'file'], true)) {
            foreach (glob(storage_path('app/reward/missions-*.json')) ?: [] as $f) {
                @unlink($f);
            }
            $this->info('파일 폴백 비움');
        }

        RewardCache::bumpVersion();   // v{ver} 키가 통째로 갈리므로 남은 사본도 무력화된다

        return self::SUCCESS;
    }
}
