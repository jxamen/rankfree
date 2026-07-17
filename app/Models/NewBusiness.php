<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 신규 개업(인허가) — 공공데이터 수집분 + 네이버 플레이스 매칭(24_NEW_BUSINESS).
 * ⚠️ 이 데이터로 광고 문자·메일을 보내지 않는다(정보통신망법 제50조 — 사업자 수신자도 면제 없음).
 */
class NewBusiness extends Model
{
    public const PLACE_STATUSES = ['pending', 'found', 'not_found', 'blocked'];

    protected $fillable = [
        'source', 'svc', 'svc_label', 'mgt_no', 'bplc_nm', 'uptae_nm', 'apv_perm_ymd', 'trd_state_nm',
        'site_tel', 'site_addr', 'road_addr', 'sido', 'sgg', 'emd', 'update_gbn', 'src_updated_at', 'collected_at',
        'place_id', 'place_name', 'place_cat', 'place_phone', 'place_phone_type', 'place_status', 'place_checked_at',
    ];

    protected $casts = [
        'apv_perm_ymd' => 'date',
        'src_updated_at' => 'datetime',
        'collected_at' => 'datetime',
        'place_checked_at' => 'datetime',
        'site_tel' => 'encrypted',      // 개인정보 취급 — 평문 저장 금지
        'place_phone' => 'encrypted',   // 플레이스에서 가져온 번호도 동일 취급
    ];

    /** 화면에 보여줄 전화번호 — 인허가 원천(SITETEL)이 대부분 비어 있어 플레이스 번호로 보완한다. */
    public function displayTel(): ?string
    {
        return $this->site_tel ?: ($this->place_phone ?: null);
    }

    /** 번호 출처 — 'license'(인허가) | 'virtual'(플레이스 안심번호 0507) | 'normal'(플레이스 일반) | null */
    public function telSource(): ?string
    {
        if ($this->site_tel) {
            return 'license';
        }

        return $this->place_phone ? ($this->place_phone_type ?: 'normal') : null;
    }

    /**
     * 네이버 지도 검색 링크 — 클릭하면 해당 업체 플레이스로 이동한다.
     * ⚠️ 공식 지역검색 API 응답에는 플레이스 ID 가 없어 m.place 상세 URL 을 만들 수 없다.
     *    매칭된 상호(place_name)가 있으면 그 이름으로, 없으면 인허가 상호로 검색한다.
     */
    public function naverMapUrl(): string
    {
        $name = $this->place_name ?: $this->bplc_nm;

        return 'https://map.naver.com/p/search/'.rawurlencode(trim($name.' '.($this->emd ?: $this->sgg ?: '')));
    }

    /** 플레이스가 확인된 업소의 링크(미확인·미등록이면 null). */
    public function placeUrl(): ?string
    {
        return $this->place_status === 'found' ? $this->naverMapUrl() : null;
    }

    /** 플레이스 미등록 업소를 지도에서 직접 확인할 링크(관리자 수동 확인용). */
    public function mapSearchUrl(): string
    {
        return $this->naverMapUrl();
    }

    /** 열람 화면용 — 영업 중인 신규 업소만(폐업 제외). */
    public function scopeOpen($q)
    {
        return $q->where('trd_state_nm', 'like', '영업%');
    }

    /**
     * 플레이스를 확인해야 할 업소 — 미확인(pending) + **미등록이지만 재확인할 때가 된 업소**.
     * 개업 직후엔 플레이스가 없다가 며칠~몇 주 뒤 등록되는 게 정상이라, 한 번 '미등록'이 나왔다고 끝내지 않고
     * recheck_after_days 마다 다시 찾는다(단 인허가 후 recheck_max_age_days 까지만 — 그 뒤엔 안 낼 가게로 본다).
     */
    /**
     * 이번 실행에서 확인할 업소.
     * - $since 없음(평상시): 미확인 + 재확인 주기가 된 미등록 → scopeNeedsPlaceCheck
     * - $since 있음(관리자가 '전체 재확인'): **주기를 무시하고** 미확인·미등록 전부. 단 $since(실행 시작 시각)
     *   이후에 확인한 건은 제외 — 방금 처리한 건이 대상에 계속 남아 배치 루프가 끝나지 않는 걸 막는다.
     */
    public function scopePlaceCheckTarget($q, ?string $since = null)
    {
        if (! $since) {
            return $q->needsPlaceCheck();
        }

        return $q->whereIn('place_status', ['pending', 'not_found', 'blocked'])
            ->where(fn ($x) => $x->whereNull('place_checked_at')->orWhere('place_checked_at', '<', $since));
    }

    public function scopeNeedsPlaceCheck($q)
    {
        $after = (int) config('rankfree.newbiz.recheck_after_days', 3);
        $maxAge = (int) config('rankfree.newbiz.recheck_max_age_days', 90);

        return $q->where(fn ($x) => $x
            ->where('place_status', 'pending')
            ->orWhere(fn ($y) => $y
                ->whereIn('place_status', ['not_found', 'blocked'])
                ->where(fn ($z) => $z->whereNull('place_checked_at')
                    ->orWhere('place_checked_at', '<=', now()->subDays($after)))
                ->whereDate('apv_perm_ymd', '>=', now()->subDays($maxAge)->toDateString())));
    }
}
