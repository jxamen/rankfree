<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 앱 타임존 UTC → Asia/Seoul 전환(config/app.php)에 따른 기존 데이터 보정.
 *
 * 지금까지 now() 기반으로 저장된 값은 UTC 벽시계라 +9h 보정해야 KST로 맞는다.
 * 아래는 보정하면 안 되는 컬럼(이미 KST 벽시계로 저장됨):
 *  - 관리자/사용자 입력값: api_keys.expires_at, banners/popups.starts_at·ends_at,
 *    users.subscription_expires_at (달력 날짜 endOfDay 경계)
 *  - 외부 원본 그대로 저장: new_businesses.src_updated_at (공공데이터 UPDATEDT)
 *  - notices.published_at 은 자동 채움(now)과 관리자 입력이 섞여 있어
 *    created_at과 10초 이내인 행(자동 채움)만 조건부 보정
 * cafe_crawl_*.wrote_at 은 외부(네이버) 시각이지만 kstToUtc()로 UTC 저장되므로 보정 대상.
 */
return new class extends Migration
{
    /** now() 기반 도메인 컬럼(프레임워크 created_at/updated_at 은 전 테이블 자동 대상) */
    private const NOW_COLUMNS = [
        'api_keys' => ['last_used_at'],
        'blog_profiles' => ['fetched_at'],
        'bulk_keywords' => ['finished_at'],
        'cafe_crawl_articles' => ['wrote_at', 'seeded_at', 'crawled_at'],
        'cafe_crawl_comments' => ['wrote_at', 'seeded_at'],
        'community_seeds' => ['last_used_at'],
        'ext_tokens' => ['last_used_at'],
        'failed_jobs' => ['failed_at'],
        'keyword_candidates' => ['volume_checked_at'],
        'keyword_categories' => ['collected_at'],
        'keyword_place_ranks' => ['collected_at'],
        'keyword_place_serps' => ['collected_at'],
        'keyword_searches' => ['refreshed_at'],
        'keyword_shop_ranks' => ['collected_at'],
        'keyword_shop_serps' => ['collected_at'],
        'keywords' => ['volume_checked_at', 'serp_collected_at'],
        'naver_ad_sessions' => ['logged_in_at', 'checked_at'],
        'new_businesses' => ['collected_at', 'place_checked_at'],
        'order_dispatches' => ['sent_at'],
        'personas' => ['joined_at', 'last_acted_at'],
        'place_businesses' => ['seen_at'],
        'place_rank_slots' => ['last_checked_at'],
        'qnas' => ['answered_at'],
        'seller_talk_contacts' => ['collected_at'],
        'shop_malls' => ['seen_at'],
        'shop_products' => ['seen_at'],
        'shop_rank_slots' => ['last_checked_at'],
        'shop_seller_captchas' => ['captured_at'],
        'shop_seller_infos' => ['captured_at'],
        'smartplace_accounts' => ['last_collected_at', 'logged_in_at'],
        'users' => ['phone_verified_at', 'email_verified_at'],
    ];

    public function up(): void
    {
        $this->shiftAll(+9);
    }

    public function down(): void
    {
        $this->shiftAll(-9);
    }

    private function shiftAll(int $hours): void
    {
        $this->shiftNoticesPublishedAt($hours);

        foreach (Schema::getTables() as $t) {
            $table = $t['name'];
            if (in_array($table, ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches'], true)) {
                continue;
            }
            $cols = self::NOW_COLUMNS[$table] ?? [];
            foreach (['created_at', 'updated_at'] as $tsCol) {
                if (Schema::hasColumn($table, $tsCol)) {
                    $cols[] = $tsCol;
                }
            }
            $cols = array_values(array_unique(array_filter($cols, fn ($c) => Schema::hasColumn($table, $c))));
            if ($cols) {
                $this->shift($table, $cols, $hours);
            }
        }
    }

    private function shift(string $table, array $cols, int $hours): void
    {
        $sets = implode(', ', array_map(
            fn ($c) => sprintf('%1$s = %2$s', $this->quote($c), $this->addExpr($this->quote($c), $hours)),
            $cols
        ));
        DB::statement("UPDATE {$this->quote($table)} SET {$sets}");
    }

    private function shiftNoticesPublishedAt(int $hours): void
    {
        if (! Schema::hasTable('notices') || ! Schema::hasColumn('notices', 'published_at')) {
            return;
        }
        $col = $this->quote('published_at');
        $expr = $this->addExpr($col, $hours);
        $where = $this->isSqlite()
            ? "abs(strftime('%s', published_at) - strftime('%s', created_at)) <= 10"
            : 'ABS(TIMESTAMPDIFF(SECOND, published_at, created_at)) <= 10';
        DB::statement("UPDATE {$this->quote('notices')} SET {$col} = {$expr} WHERE published_at IS NOT NULL AND {$where}");
    }

    private function addExpr(string $quotedCol, int $hours): string
    {
        return $this->isSqlite()
            ? "datetime({$quotedCol}, '" . ($hours >= 0 ? '+' : '') . "{$hours} hours')"
            : "DATE_ADD({$quotedCol}, INTERVAL {$hours} HOUR)";
    }

    private function quote(string $ident): string
    {
        return $this->isSqlite() ? '"' . $ident . '"' : '`' . $ident . '`';
    }

    private function isSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }
};
