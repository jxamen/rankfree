<?php

namespace App\Domain\NewBiz;

use App\Models\NewBusiness;
use Illuminate\Support\Carbon;

/**
 * 신규 개업(인허가) 수집 — 인허가일자별로 받아 new_businesses 에 upsert(24_NEW_BUSINESS).
 * 원천 고유키는 관리번호(MGTNO). 재수집해도 중복되지 않고, 영업상태 변경(폐업 등)은 갱신된다.
 * ⚠️ 열람 전용 데이터 — 광고 발송에 쓰지 않는다(정보통신망법 제50조).
 */
class NewBusinessCollector
{
    public function __construct(private SeoulLocalDataClient $client) {}

    /**
     * 하루치 수집. ids 에 이번 실행에서 담은 레코드 id 가 담긴다 — 호출측이 곧바로 플레이스 매칭에 넘긴다
     * (수집과 매칭은 한 흐름이다. 따로 돌리지 않는다).
     *
     * @return array{created:int,updated:int,total:int,error:?string,ids:list<int>}
     */
    public function collectDate(string $svc, string $svcLabel, Carbon $date): array
    {
        $out = ['created' => 0, 'updated' => 0, 'total' => 0, 'error' => null, 'ids' => []];
        $size = $this->client->pageSize();
        $start = 1;
        $ymd = $date->toDateString();

        do {
            $res = $this->client->fetch($svc, $ymd, $start, $start + $size - 1);
            if ($res['error']) {
                // 1페이지 실패만 오류로 본다 — 이후 페이지 실패(예: sample 키 범위 초과)는 받은 만큼 살린다
                if ($start === 1) {
                    $out['error'] = $res['error'];
                }
                break;
            }
            $out['total'] = $res['total'];

            // ⚠️ 방어: 인허가일자 필터는 **업종(서비스)마다 동작이 다르다**(실측 — 일반음식점은 먹고
            //    휴게음식점은 무시돼 전체 14.6만건이 그대로 온다). 요청 날짜와 다른 행은 절대 적재하지 않는다.
            $matched = array_values(array_filter(
                $res['rows'],
                fn ($r) => str_starts_with(trim((string) ($r['APVPERMYMD'] ?? '')), $ymd),
            ));
            if ($res['rows'] && ! $matched) {
                $out['error'] = "인허가일자 필터 미적용(원천 {$res['total']}건 전체 응답) — 이 업종은 날짜 조회를 지원하지 않습니다";
                break;
            }
            foreach ($matched as $row) {
                $this->upsert($svc, $svcLabel, $row, $out);
            }
            $start += $size;
        } while (! $this->client->isSampleKey()   // sample 키는 1~5 범위 밖을 거부 — 1페이지로 끝낸다
            && count($res['rows']) === $size && $start <= $res['total']);

        return $out;
    }

    private function upsert(string $svc, string $svcLabel, array $row, array &$out): void
    {
        $v = fn (string $k) => trim((string) ($row[$k] ?? ''));
        $mgtNo = $v('MGTNO');
        if ($mgtNo === '' || $v('BPLCNM') === '') {
            return;
        }
        [$sido, $sgg, $emd] = $this->splitAddress($v('SITEWHLADDR') ?: $v('RDNWHLADDR'));

        $data = [
            'svc' => $svc,
            'svc_label' => $svcLabel,
            'bplc_nm' => $v('BPLCNM'),
            'uptae_nm' => $v('UPTAENM') ?: null,
            'apv_perm_ymd' => ($d = $v('APVPERMYMD')) !== '' ? substr($d, 0, 10) : null,
            'trd_state_nm' => $v('TRDSTATENM') ?: null,
            'site_tel' => $v('SITETEL') ?: null,
            'site_addr' => $v('SITEWHLADDR') ?: null,
            'road_addr' => $v('RDNWHLADDR') ?: null,
            'sido' => $sido, 'sgg' => $sgg, 'emd' => $emd,
            'update_gbn' => $v('UPDATEGBN') ?: null,
            'src_updated_at' => ($u = $v('UPDATEDT')) !== '' ? $u : null,
            'collected_at' => now(),
        ];

        $rec = NewBusiness::where('source', 'seoul')->where('mgt_no', $mgtNo)->first();
        if ($rec) {
            $rec->fill($data)->save();
            $out['updated']++;
            $out['ids'][] = $rec->id;

            return;
        }
        $rec = NewBusiness::create($data + ['source' => 'seoul', 'mgt_no' => $mgtNo, 'place_status' => 'pending']);
        $out['created']++;
        $out['ids'][] = $rec->id;
    }

    /**
     * 지번주소 → [시/도 축약, 시/군/구, 읍면동]. "서울특별시 용산구 용산동2가 45-9" → [서울, 용산구, 용산동2가]
     * 공개 /keywords/place 의 지역 표기(시/도 축약 17종)와 맞춘다.
     */
    public function splitAddress(string $addr): array
    {
        $sidoMap = [
            '서울특별시' => '서울', '부산광역시' => '부산', '대구광역시' => '대구', '인천광역시' => '인천',
            '광주광역시' => '광주', '대전광역시' => '대전', '울산광역시' => '울산', '세종특별자치시' => '세종',
            '경기도' => '경기', '강원도' => '강원', '강원특별자치도' => '강원', '충청북도' => '충북', '충청남도' => '충남',
            '전라북도' => '전북', '전북특별자치도' => '전북', '전라남도' => '전남',
            '경상북도' => '경북', '경상남도' => '경남', '제주특별자치도' => '제주',
        ];
        $parts = preg_split('/\s+/u', trim($addr)) ?: [];
        if (count($parts) < 2) {
            return [null, null, null];
        }
        $sido = $sidoMap[$parts[0]] ?? null;
        $sgg = $parts[1] ?? null;
        // 세종처럼 시군구가 없는 경우 두 번째 토큰이 곧 읍면동
        $emd = null;
        foreach (array_slice($parts, 2) as $t) {
            if (preg_match('/(동|가|읍|면|리)$/u', $t)) {
                $emd = $t;
                break;
            }
        }

        return [$sido, $sgg, $emd];
    }
}
