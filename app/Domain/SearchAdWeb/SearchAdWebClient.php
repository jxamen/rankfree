<?php

namespace App\Domain\SearchAdWeb;

use Illuminate\Support\Facades\Http;

/**
 * 네이버 검색광고 "웹 콘솔" 세션 클라이언트 — 공식 API에 없는 값 조회.
 * 성별×연령(userStat 14버킷) + 월별 트렌드(monthlyProgressList) → 정제 반환.
 * 인증: DB 저장 쿠키(NID_AUT/NID_SES 등). 401 이면 stale 표시 후 ['error'=>'unauthorized'].
 */
class SearchAdWebClient
{
    public function __construct(private WebSessionStore $store) {}

    public function configured(): bool
    {
        return $this->store->has();
    }

    private function base(): string
    {
        return rtrim((string) config('searchadweb.base'), '/');
    }

    private function headers(string $cookie): array
    {
        return [
            'Cookie' => $cookie,
            'x-ad-customer-id' => (string) config('searchadweb.customer_id'),
            'accept' => 'application/json, text/plain, */*',
            'accept-language' => 'ko-KR,ko;q=0.9',
            'user-agent' => (string) config('searchadweb.ua'),
            'referer' => $this->base().'/manage/ad-accounts/'.config('searchadweb.account_no').'/sa/tool/keyword-planner',
        ];
    }

    /** 세션 유효성 저비용 확인 (logged-in 프로브). */
    public function check(): bool
    {
        $cookie = $this->store->get();
        if (! $cookie) {
            return false;
        }
        $r = Http::withHeaders($this->headers($cookie))
            ->timeout((int) config('searchadweb.timeout', 15))
            ->get($this->base().'/apis/user/v1.0/users/logged-in');

        if ($r->status() === 401) {
            $this->store->markStale();

            return false;
        }
        if ($r->ok()) {
            $this->store->markChecked();

            return true;
        }

        return false;
    }

    /**
     * 키워드 성별/연령/월별 트렌드 상세.
     *
     * @return array{keyword:string,gender:array,age:array,buckets:array,monthly:array}|array{error:string}
     */
    public function keywordDetail(string $keyword): array
    {
        $cookie = $this->store->get();
        if (! $cookie) {
            return ['error' => 'no_session'];
        }
        $kw = trim($keyword);
        if ($kw === '') {
            return ['error' => 'empty_keyword'];
        }

        $r = Http::withHeaders($this->headers($cookie))
            ->timeout((int) config('searchadweb.timeout', 15))
            ->get($this->base().'/apis/sa/keywordstool', [
                'format' => 'json', 'includeHintKeywords' => 0, 'showDetail' => 1, 'keyword' => $kw,
            ]);

        if ($r->status() === 401) {
            $this->store->markStale();

            return ['error' => 'unauthorized'];
        }
        if ($r->failed()) {
            return ['error' => 'http_'.$r->status()];
        }

        $item = (array) ($r->json('keywordList.0') ?? []);
        if (! $item) {
            return ['error' => 'empty'];
        }
        $this->store->markChecked();

        return $this->parse($kw, $item);
    }

    /** userStat 14버킷(성별×연령) + monthlyProgressList(12개월) → 정제. */
    private function parse(string $kw, array $item): array
    {
        $us = (array) ($item['userStat'] ?? []);
        $mp = (array) ($item['monthlyProgressList'] ?? []);

        $pc = (array) ($us['monthlyPcQcCnt'] ?? []);
        $mo = (array) ($us['monthlyMobileQcCnt'] ?? []);
        $gt = (array) ($us['genderType'] ?? []);
        $ag = (array) ($us['ageGroup'] ?? []);

        $buckets = [];
        $genderTotal = ['f' => 0, 'm' => 0];
        $ageTotal = [];
        $n = min(count($gt), count($ag));
        for ($i = 0; $i < $n; $i++) {
            $g = (string) ($gt[$i] ?? '');
            $a = (string) ($ag[$i] ?? '');
            $p = (int) ($pc[$i] ?? 0);
            $m = (int) ($mo[$i] ?? 0);
            $t = $p + $m;
            $buckets[] = ['gender' => $g, 'age' => $a, 'pc' => $p, 'mobile' => $m, 'total' => $t];
            if (isset($genderTotal[$g])) {
                $genderTotal[$g] += $t;
            }
            $ageTotal[$a] = ($ageTotal[$a] ?? 0) + $t;
        }

        $gsum = array_sum($genderTotal) ?: 1;
        $gender = [
            'female' => $genderTotal['f'], 'male' => $genderTotal['m'],
            'female_pct' => round($genderTotal['f'] / $gsum * 100, 1),
            'male_pct' => round($genderTotal['m'] / $gsum * 100, 1),
        ];

        $asum = array_sum($ageTotal) ?: 1;
        $age = [];
        foreach ($ageTotal as $a => $v) {
            $age[] = ['age' => $a, 'total' => $v, 'pct' => round($v / $asum * 100, 1)];
        }

        $labels = (array) ($mp['monthlyLabel'] ?? []);
        $mpc = (array) ($mp['monthlyProgressPcQcCnt'] ?? []);
        $mmo = (array) ($mp['monthlyProgressMobileQcCnt'] ?? []);
        $monthly = [];
        for ($i = 0; $i < count($labels); $i++) {
            $p = (int) ($mpc[$i] ?? 0);
            $m = (int) ($mmo[$i] ?? 0);
            $monthly[] = ['label' => (string) $labels[$i], 'pc' => $p, 'mobile' => $m, 'total' => $p + $m];
        }

        return ['keyword' => $kw, 'gender' => $gender, 'age' => $age, 'buckets' => $buckets, 'monthly' => $monthly];
    }
}
