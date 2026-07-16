<?php

namespace App\Console\Commands;

use App\Models\KeywordSearch;
use App\Models\MarketAnalysis;
use App\Models\PlaceRankSlot;
use App\Models\PlaceStoreAnalysis;
use App\Models\ProductAnalysis;
use App\Models\SellerPowerAnalysis;
use App\Models\ShopRankSlot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * 사이트맵 갱신 — 분석 모델의 SEO 공유 슬러그를 백필하고(구 데이터·토큰만 있던 레코드),
 * 사이트맵 캐시 버전을 올려 다음 요청부터 최신 URL 이 나가게 한다. 매일 스케줄 실행.
 */
class SitemapRefresh extends Command
{
    protected $signature = 'sitemap:refresh {--no-bump : 캐시 버전을 올리지 않음(백필만)}';

    protected $description = '분석 공유 슬러그 백필 + 사이트맵 캐시 무효화';

    /** 사이트맵 캐시 버전 키 — 값이 바뀌면 모든 섹션 캐시가 자연 만료된다. */
    public const VERSION_KEY = 'sitemap:ver';

    /**
     * 공유 슬러그를 쓰는 전체 모델 — 사이트맵 공개 여부와 무관하게 슬러그를 백필한다.
     * (추적 슬롯 place/shop 은 사이트맵엔 빠지지만, 사용자가 공유 버튼으로 쓰므로 슬러그는 필요.)
     */
    private const SLUG_MODELS = [
        KeywordSearch::class,
        MarketAnalysis::class,
        ProductAnalysis::class,
        SellerPowerAnalysis::class,
        PlaceStoreAnalysis::class,
        PlaceRankSlot::class,
        ShopRankSlot::class,
    ];

    public function handle(): int
    {
        $total = 0;
        foreach (self::SLUG_MODELS as $model) {
            $n = 0;
            $model::whereNull('slug')->orderBy('id')->chunkById(200, function ($rows) use (&$n) {
                foreach ($rows as $row) {
                    $row->forceFill(['slug' => $row->buildUniqueShareSlug()])->save();
                    $n++;
                }
            });
            if ($n > 0) {
                $this->line('  '.class_basename($model).': 슬러그 '.$n.'건 발급');
            }
            $total += $n;
        }

        if (! $this->option('no-bump')) {
            Cache::increment(self::VERSION_KEY) ?: Cache::forever(self::VERSION_KEY, 1);
        }

        $this->info("사이트맵 갱신 완료 — 슬러그 신규 {$total}건.");

        return self::SUCCESS;
    }

    /** 현재 캐시 버전(사이트맵 캐시 키 접두). */
    public static function version(): int
    {
        return (int) (Cache::get(self::VERSION_KEY) ?: 1);
    }
}
