<?php

namespace App\Domain\Keyword;

use App\Models\BulkKeyword;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 키워드 대량 분석 결과 → 엑셀(xlsx). Sheet1=결과, fail=실패 목록.
 * 컬럼: 네이버 측(검색량·발행량·포화·연관·트렌드·요일·성별연령·이슈/상업·섹션배치).
 * (구글월별·일별30·월별36·연별9 는 후속 — 구글 토큰/데이터랩 스케일링 연결 시 추가)
 */
class BulkKeywordExporter
{
    private const WD = ['월', '화', '수', '목', '금', '토', '일'];

    public function download(BulkKeyword $bulk): StreamedResponse
    {
        $done = $bulk->items->where('status', 'done');
        $failed = $bulk->items->where('status', 'failed');

        // 트렌드 월 라벨(union) — 헤더 동적 생성
        $months = [];
        foreach ($done as $it) {
            foreach ((array) ($it->data['trend'] ?? []) as $t) {
                $months[(string) ($t['label'] ?? '')] = true;
            }
        }
        unset($months['']);
        $months = array_keys($months);
        sort($months);

        $ss = new Spreadsheet;
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Sheet1');

        $header = array_merge(
            ['No', '키워드', '수집일', '월간 총 검색량', '월간 데스크톱', '월간 모바일', '일평균 검색량',
                '키워드등급', '경쟁강도', '성인키워드여부',
                '블로그전체발행량', '카페전체발행량', '블로그포화지수', '카페포화지수', '전체포화지수',
                '어제까지검색량', '월말까지검색량', '연관키워드'],
            array_map(fn ($m) => '월별검색량-'.$m, $months),
            array_map(fn ($i) => '월별검색비율-'.$i.'월', range(1, 12)),
            array_map(fn ($w) => '요일비율-'.$w, self::WD),
            ['연령별-10대', '연령별-20대', '연령별-30대', '연령별-40대', '연령별-50대이상', '성별-남성', '성별-여성',
                '이슈성', '정보성', '상업성',
                'PC섹션1', 'PC섹션1_컨텐츠수', 'PC섹션2', 'PC섹션2_컨텐츠수', 'PC섹션3', 'PC섹션3_컨텐츠수',
                'Mobile섹션1', 'Mobile섹션1_컨텐츠수', 'Mobile섹션2', 'Mobile섹션2_컨텐츠수', 'Mobile섹션3', 'Mobile섹션3_컨텐츠수',
                'PC섹션순서', 'Mobile섹션순서'],
        );
        $sheet->fromArray($header, null, 'A1');

        $r = 2;
        $no = 1;
        foreach ($done as $it) {
            $d = (array) $it->data;
            $mr = (array) ($d['month_ratio'] ?? []);
            $wd = (array) ($d['weekday'] ?? []);
            $age = (array) ($d['age5'] ?? []);
            $g = (array) ($d['gender'] ?? []);
            $trend = [];
            foreach ((array) ($d['trend'] ?? []) as $t) {
                $trend[(string) ($t['label'] ?? '')] = $t['total'] ?? null;
            }
            $pc = (array) ($d['serp_pc'] ?? []);
            $mo = (array) ($d['serp_mobile'] ?? []);

            $row = array_merge(
                [$no++, $d['keyword'] ?? $it->keyword, $d['collected_at'] ?? '', $d['total'] ?? '', $d['pc'] ?? '', $d['mobile'] ?? '', $d['daily_avg'] ?? '',
                    $d['grade'] ?? '', $d['comp_idx'] ?? '', $d['adult'] ?? '',
                    $d['blog_total'] ?? '', $d['cafe_total'] ?? '', $d['blog_sat'] ?? '', $d['cafe_sat'] ?? '', $d['total_sat'] ?? '',
                    $d['yesterday_est'] ?? '', $d['monthend_est'] ?? '', $d['related'] ?? ''],
                array_map(fn ($m) => $trend[$m] ?? '', $months),
                array_map(fn ($i) => $mr[$i] ?? '', range(1, 12)),
                array_map(fn ($w) => $wd[$w] ?? '', self::WD),
                [$age['10대'] ?? '', $age['20대'] ?? '', $age['30대'] ?? '', $age['40대'] ?? '', $age['50대+'] ?? '',
                    $g['male'] ?? '', $g['female'] ?? '',
                    $d['issue_pct'] ?? '', $d['info_pct'] ?? '', $d['commercial_pct'] ?? '',
                    $this->secName($pc, 0), $this->secCount($pc, 0), $this->secName($pc, 1), $this->secCount($pc, 1), $this->secName($pc, 2), $this->secCount($pc, 2),
                    $this->secName($mo, 0), $this->secCount($mo, 0), $this->secName($mo, 1), $this->secCount($mo, 1), $this->secName($mo, 2), $this->secCount($mo, 2),
                    $this->secOrder($pc), $this->secOrder($mo)],
            );
            $sheet->fromArray($row, null, 'A'.$r++);
        }

        // fail 시트
        $fail = $ss->createSheet();
        $fail->setTitle('fail');
        $fail->fromArray(['No', '키워드', '실패사유'], null, 'A1');
        $fr = 2;
        $fno = 1;
        foreach ($failed as $it) {
            $fail->fromArray([$fno++, $it->keyword, $it->fail_reason ?? ''], null, 'A'.$fr++);
        }

        $ss->setActiveSheetIndex(0);
        $filename = 'bulk_keyword_'.$bulk->id.'_'.now()->format('Ymd_His').'.xlsx';

        return response()->streamDownload(function () use ($ss) {
            (new Xlsx($ss))->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function secName(array $secs, int $i): string
    {
        return isset($secs[$i]) ? (string) ($secs[$i]['name'] ?? '') : '';
    }

    private function secCount(array $secs, int $i): string
    {
        $c = isset($secs[$i]) ? (int) ($secs[$i]['count'] ?? 0) : 0;

        return $c > 0 ? (string) $c : '';
    }

    private function secOrder(array $secs): string
    {
        return implode(' > ', array_map(fn ($s) => (string) ($s['name'] ?? ''), $secs));
    }
}
