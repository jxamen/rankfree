<?php

namespace App\Models\Concerns;

/**
 * SEO 공유 슬러그 — 분석 공유 URL 을 한글/영문 슬러그로 제공한다.
 *   /{prefix}/{slug}  예) /keyword/여름브라 · /place/강남맛집
 * 각 모델은 shareSlugBasis()(소재 텍스트)와 shareSlugPrefix()(URL 접두)를 구현한다.
 * 생성 시 자동으로 slug 를 부여하고(중복이면 -2, -3 …), 기존 share_token 은 하위호환용으로 남긴다.
 */
trait HasShareSlug
{
    /** 슬러그 소재 텍스트(키워드·업체명·상품명 등). */
    abstract public function shareSlugBasis(): string;

    /** 공유 URL 접두(keyword|market|product|seller|store|place|shopping). */
    abstract public function shareSlugPrefix(): string;

    /**
     * 같은 소재(키워드)의 새 문서가 기본 슬러그를 **인수**할지 여부.
     * true(키워드-전역 데이터, 예: 시장분석): -2/-3 없이 항상 기본 슬러그 1개 — 최신 문서가 슬러그를 가져가고
     *   이전 문서는 슬러그를 반납한다(같은 키워드 = 같은 데이터라 최신 하나만 공개하면 된다 — 2026-07-22 결정).
     * false(사용자 소유 데이터, 예: 순위추적 슬롯): 남의 공유 링크를 빼앗으면 안 되므로 기존 -2 방식 유지.
     */
    protected function shareSlugTakesOver(): bool
    {
        return false;
    }

    protected static function bootHasShareSlug(): void
    {
        static::creating(function ($model) {
            if (empty($model->slug)) {
                $model->slug = $model->buildUniqueShareSlug();
            }
        });
    }

    /** 공유 슬러그 — 없으면(구 데이터) 생성·저장 후 반환. */
    public function shareSlug(): string
    {
        if (empty($this->slug)) {
            $this->forceFill(['slug' => $this->buildUniqueShareSlug()])->save();
        }

        return (string) $this->slug;
    }

    /** 공개 공유 URL(SEO 슬러그). 한글은 브라우저·HTTP 에서 자동 인코딩된다. */
    public function shareUrl(): string
    {
        return url('/'.$this->shareSlugPrefix().'/'.$this->shareSlug());
    }

    /** 중복을 피한 유일 슬러그 생성(저장은 호출측). 인수형 모델은 기본 슬러그를 빼앗아 온다(부수효과: 이전 보유자 슬러그 반납). */
    public function buildUniqueShareSlug(): string
    {
        $base = static::slugify($this->shareSlugBasis());
        if ($base === '') {
            $base = $this->shareSlugPrefix();
        }

        if ($this->shareSlugTakesOver()) {
            // 최신 문서가 기본 슬러그 1개를 인수 — 이전 문서(base·base-2·base-3 …)는 슬러그 반납.
            // base-추천 처럼 '다른 키워드' 슬러그를 건드리지 않게 -숫자 꼬리만 PHP 로 정밀 판별(sqlite 호환).
            $victims = static::where(fn ($q) => $q->where('slug', $base)->orWhere('slug', 'like', $base.'-%'))
                ->when($this->getKey(), fn ($q) => $q->where('id', '!=', $this->getKey()))
                ->get(['id', 'slug'])
                ->filter(fn ($m) => $m->slug === $base || preg_match('/^'.preg_quote($base, '/').'-\d+$/u', (string) $m->slug));
            if ($victims->isNotEmpty()) {
                static::whereIn('id', $victims->pluck('id'))->update(['slug' => null]);
            }

            return $base;
        }

        $slug = $base;
        $i = 2;
        while (static::where('slug', $slug)->when($this->getKey(), fn ($q) => $q->where('id', '!=', $this->getKey()))->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }

    /**
     * 한글·영문·숫자를 살린 슬러그화. 그 외 문자는 대시로.
     *   '여름 브라' → '여름-브라' · 'Nike 에어포스1' → 'nike-에어포스1'
     */
    public static function slugify(string $text): string
    {
        $text = trim(mb_strtolower($text, 'UTF-8'));
        $text = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text);   // 문자/숫자 외 → 대시
        $text = preg_replace('/-+/', '-', (string) $text);       // 연속 대시 축약
        $text = trim((string) $text, '-');

        return mb_substr($text, 0, 120, 'UTF-8');
    }

    /** slug 또는 (구) share_token 으로 조회 — 구 링크 하위호환. */
    public static function findByShareKey(string $key): ?static
    {
        $found = static::where('slug', $key)->first()
            ?? static::where('share_token', $key)->first();
        if ($found) {
            return $found;
        }

        // 인수형 모델의 구 '-2' 링크 하위호환 — 기본 슬러그 문서(최신 데이터)로 폴백.
        // 페이지 canonical 은 shareUrl()(기본 슬러그)이라 검색엔진도 기본 URL 로 정규화된다.
        if (preg_match('/^(.+)-\d+$/u', $key, $m)) {
            $base = static::where('slug', $m[1])->first();
            if ($base && $base->shareSlugTakesOver()) {
                return $base;
            }
        }

        return null;
    }
}
