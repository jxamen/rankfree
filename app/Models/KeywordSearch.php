<?php

namespace App\Models;

use App\Models\Concerns\HasShareSlug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * 키워드 분석 검색 내역(origin=user, 사용자별 갱신) + 허브 발행 문서(origin=hub, user_id NULL·시스템 소유).
 * 허브 문서 유일성은 (origin=hub, keyword) 로 코드에서 보장한다(22_KEYWORD_CONTENT_HUB).
 */
class KeywordSearch extends Model
{
    use HasShareSlug;

    /**
     * 폐기(retired) 문서는 모든 기본 조회에서 숨긴다 — 공개 목록·카운트·추천·사이트맵.
     * 단 공유 URL 조회(findByShareKey)만 예외로 찾아 301 리다이렉트한다.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('notRetired', function (\Illuminate\Database\Eloquent\Builder $b) {
            $b->whereNull('keyword_searches.retired_at');
        });
    }

    /** slug/share_token 조회 — 폐기 문서도 찾는다(공유 페이지에서 301 처리하기 위함). */
    public static function findByShareKey(string $key): ?static
    {
        return static::withoutGlobalScope('notRetired')->where('slug', $key)->first()
            ?? static::withoutGlobalScope('notRetired')->where('share_token', $key)->first();
    }

    protected $fillable = [
        'slug', 'user_id', 'category_id', 'region', 'region_type', 'origin', 'keyword', 'monthly_total', 'monthly_pc', 'monthly_mobile',
        'comp_idx', 'grade', 'share_token', 'snapshot', 'refreshed_at', 'retired_at',
    ];

    public function shareSlugBasis(): string
    {
        return (string) $this->keyword;
    }

    public function shareSlugPrefix(): string
    {
        return 'keyword';
    }

    protected $casts = [
        'monthly_total' => 'integer',
        'monthly_pc' => 'integer',
        'monthly_mobile' => 'integer',
        'snapshot' => 'array',
        'refreshed_at' => 'datetime',
        'retired_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(KeywordCategory::class, 'category_id');
    }

    /** 공개 공유 토큰 — 없으면 생성해 저장. */
    public function shareToken(): string
    {
        if (! $this->share_token) {
            $this->forceFill(['share_token' => Str::random(32)])->save();
        }

        return $this->share_token;
    }

    /**
     * 허브 목록에서 여는 공개 문서 URL — 쇼핑 키워드는 같은 키워드의 **시장분석(/market)** 을 우선 연다
     * (없으면 키워드 문서 폴백). 조회는 6h 캐시 — 백필 커맨드가 발행 시 키를 지워 즉시 반영된다.
     */
    public function publicUrl(): string
    {
        if ($this->category?->type === 'shopping') {
            $slug = \Illuminate\Support\Facades\Cache::remember(
                self::marketSlugCacheKey($this->keyword),
                now()->addHours(6),
                // 첫 문서 슬러그 = 키워드 정식 URL(다른 슬러그는 301로 여기로 모인다)
                fn () => (string) MarketAnalysis::where('keyword', $this->keyword)->orderBy('id')->value('slug'),
            );
            if ($slug !== '') {
                return route('market.shared', $slug);
            }
        }

        return $this->shareUrl();
    }

    public static function marketSlugCacheKey(string $keyword): string
    {
        return 'hub:market-slug:'.md5($keyword);
    }

    /** 허브 목록 카드 라벨 — publicUrl() 이 시장분석으로 연결되면 '시장 분석'. */
    public function publicLabel(): string
    {
        return str_contains($this->publicUrl(), '/market/') ? '시장 분석' : '키워드 분석';
    }
}
