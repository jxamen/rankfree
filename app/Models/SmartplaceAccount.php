<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 스마트플레이스 리포트 수집 계정 (crm ads/smartplace 이식).
 * 광고주 네이버 아이디/비밀번호로 자동 로그인(Playwright) → 세션 쿠키를 발급·유지.
 * naver_pw·cookie(네이버 세션)·last_result(예약고객 개인정보 포함)는 암호화 저장 — 원본 crm 평문 개선.
 */
class SmartplaceAccount extends Model
{
    protected $fillable = [
        'user_id', 'label', 'place_seq', 'business_id', 'place_id', 'place_url', 'site_id',
        'naver_id', 'naver_pw', 'sp_name', 'category', 'cookie',
        'last_result', 'last_status', 'last_collected_at', 'logged_in_at',
    ];

    protected $hidden = ['naver_pw', 'cookie'];

    protected $casts = [
        'naver_pw' => 'encrypted',
        'cookie' => 'encrypted',
        'last_result' => 'encrypted:array',
        'last_collected_at' => 'datetime',
        'logged_in_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** 수집 결과 반영 — 수집으로 확인된 ID·업체명·상태 갱신. */
    public function applyResult(array $result): void
    {
        $ids = $result['ids'] ?? [];
        $this->fill([
            'place_id' => ($ids['placeId'] ?? '') !== '' ? $ids['placeId'] : $this->place_id,
            'site_id' => ($ids['siteId'] ?? '') !== '' ? $ids['siteId'] : $this->site_id,
            'sp_name' => ($result['name'] ?? '') !== '' ? $result['name'] : $this->sp_name,
            'last_result' => $result,
            'last_status' => ! empty($result['loggedIn']) ? 'OK' : 'FAIL',
            'last_collected_at' => now(),
        ]);
        if (($this->business_id ?? '') === '' && ($ids['businessId'] ?? '') !== '') {
            $this->business_id = $ids['businessId'];
        }
        if (($this->category ?? '') === '' && ($result['category'] ?? '') !== '') {
            $this->category = $result['category']; // 자동 판별된 업종 저장
        }
        $this->save();
    }
}
