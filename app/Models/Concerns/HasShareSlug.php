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

    /** 중복을 피한 유일 슬러그 생성(저장은 호출측). */
    public function buildUniqueShareSlug(): string
    {
        $base = static::slugify($this->shareSlugBasis());
        if ($base === '') {
            $base = $this->shareSlugPrefix();
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
        return static::where('slug', $key)->first()
            ?? static::where('share_token', $key)->first();
    }
}
