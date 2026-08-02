<?php

namespace App\Console\Commands;

use App\Models\BlockedIp;
use Illuminate\Console\Command;

/**
 * 차단 IP 조회·해제·정리.
 * 자동 차단이라 오차단 복구 수단이 반드시 있어야 한다 — 그게 --unblock 이다.
 */
class BlockedIps extends Command
{
    protected $signature = 'security:blocked-ips
        {--unblock= : 차단을 해제할 IP}
        {--prune : 만료된 차단 기록을 삭제}';

    protected $description = '취약점 탐침으로 차단된 IP 를 보고, 해제하거나 만료분을 정리한다';

    public function handle(): int
    {
        if ($ip = (string) $this->option('unblock')) {
            if (BlockedIp::unblock($ip)) {
                $this->info("해제: {$ip}");
            } else {
                $this->warn("차단 목록에 없음: {$ip}");
            }

            return self::SUCCESS;
        }

        if ($this->option('prune')) {
            $this->info('만료 기록 삭제: '.BlockedIp::prune().'건');

            return self::SUCCESS;
        }

        $rows = BlockedIp::query()->orderByDesc('updated_at')->limit(50)->get();
        if ($rows->isEmpty()) {
            $this->info('차단된 IP 가 없습니다.');

            return self::SUCCESS;
        }

        $this->table(
            ['IP', '사유', '유발 경로', '시도', '해제 시각'],
            $rows->map(fn ($r) => [
                $r->ip,
                $r->reason,
                $r->hit_path,
                $r->hits,
                $r->blocked_until?->format('Y-m-d H:i') ?? '무기한',
            ])->all()
        );

        return self::SUCCESS;
    }
}
