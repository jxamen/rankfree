<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 메뉴 SEO — meta_keywords 컬럼 추가 + 공개 페이지(메인/게시판/무료조회/API문서/로그인/가입)를
 * SEO 전용 'site' area 메뉴로 등록 + 기존 콘솔 메뉴에 기본 SEO 백필(비어 있을 때만).
 * 모두 멱등(재실행/기존 편집과 충돌 없음).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('menus', 'meta_keywords')) {
            Schema::table('menus', function (Blueprint $table) {
                $table->string('meta_keywords', 255)->nullable()->after('meta_description');
            });
        }

        // ── 공개 페이지 SEO (area=site) — 관리자가 메뉴관리에서 편집 가능한 SEO 전용 레코드 ──
        $now = now();
        foreach ($this->siteSeo() as $route => $seo) {
            $exists = DB::table('menus')->where('area', 'site')->where('route', $route)->exists();
            if ($exists) {
                continue; // 이미 있으면 관리자 편집을 존중(덮어쓰지 않음)
            }
            DB::table('menus')->insert([
                'parent_id' => null, 'area' => 'site', 'is_group' => false,
                'name' => $seo['name'], 'route' => $route, 'url' => null, 'target' => '', 'icon' => null,
                'sort_order' => $seo['sort'], 'is_active' => true,
                'meta_title' => $seo['title'], 'meta_description' => $seo['desc'], 'meta_keywords' => $seo['keywords'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // ── 기존 콘솔 메뉴 SEO 백필 — meta 가 비어 있는 행에만 채움(관리자 편집 보존) ──
        foreach ($this->consoleSeo() as $route => $seo) {
            DB::table('menus')->where('area', 'console')->where('route', $route)
                ->whereNull('meta_title')->whereNull('meta_description')
                ->update(['meta_title' => $seo['title'], 'meta_description' => $seo['desc'], 'meta_keywords' => $seo['keywords'] ?? null]);
        }
    }

    public function down(): void
    {
        DB::table('menus')->where('area', 'site')->delete();
        if (Schema::hasColumn('menus', 'meta_keywords')) {
            Schema::table('menus', function (Blueprint $table) {
                $table->dropColumn('meta_keywords');
            });
        }
    }

    /** 공개(색인 대상) 페이지 SEO. */
    private function siteSeo(): array
    {
        return [
            'home' => [
                'name' => '메인페이지', 'sort' => 0,
                'title' => 'rankfree — 네이버 플레이스·쇼핑·블로그 순위 무료 분석',
                'desc' => '키워드만 입력하면 네이버 플레이스·쇼핑 순위, 경쟁사 비교, 블로그 지수, 시장 규모까지 무료로 분석합니다. 가입 없이 30초 만에 시작하세요.',
                'keywords' => '네이버 순위, 플레이스 순위, 스마트스토어 순위, 쇼핑 순위, 블로그 지수, 키워드 분석, 순위 추적, 경쟁사 분석, 랭크프리',
            ],
            'community' => [
                'name' => '커뮤니티(게시판)', 'sort' => 1,
                'title' => '커뮤니티 — 네이버 마케팅 노하우·순위 정보 게시판 · rankfree',
                'desc' => '플레이스·스마트스토어·블로그 상위노출 노하우와 순위 변화 정보를 나누는 rankfree 마케팅 커뮤니티 게시판입니다.',
                'keywords' => '네이버 마케팅 커뮤니티, 플레이스 상위노출, 스마트스토어 상위노출, 블로그 상위노출, 순위 정보 게시판',
            ],
            'rank.check' => [
                'name' => '무료 순위 조회', 'sort' => 2,
                'title' => '무료 플레이스 순위 조회 — 네이버 지도 순위 확인 · rankfree',
                'desc' => '내 가게가 네이버 플레이스에서 몇 위인지 키워드로 무료 조회하세요. 가입 없이 즉시 순위와 상위 경쟁 업체를 확인합니다.',
                'keywords' => '플레이스 순위 조회, 네이버 지도 순위, 플레이스 순위 확인, 무료 순위 조회, 매장 순위',
            ],
            'developers' => [
                'name' => 'API 문서', 'sort' => 3,
                'title' => 'API 문서 — 순위·경쟁·키워드 분석 REST API · rankfree',
                'desc' => 'rankfree 순위추적·경쟁분석·키워드분석 데이터를 REST API로 제공합니다. 인증·엔드포인트·요청 예시를 확인하세요.',
                'keywords' => '순위 API, 네이버 순위 API, 키워드 분석 API, rankfree API, 순위추적 API',
            ],
            'login' => [
                'name' => '로그인', 'sort' => 4,
                'title' => '로그인 · rankfree',
                'desc' => 'rankfree 콘솔에 로그인해 순위 추적·경쟁 분석·키워드 데이터를 확인하세요.',
                'keywords' => 'rankfree 로그인, 순위 분석 로그인',
            ],
            'register' => [
                'name' => '회원가입', 'sort' => 5,
                'title' => '무료 회원가입 · rankfree',
                'desc' => '무료로 가입하고 네이버 플레이스·쇼핑·블로그 순위를 추적·분석하세요. 순위체크 100개 무료 제공.',
                'keywords' => 'rankfree 가입, 무료 순위 추적, 네이버 순위 분석 가입',
            ],
        ];
    }

    /** 콘솔(로그인 후) 페이지 기본 SEO — noindex 이지만 브라우저 탭/공유 링크용 설명. */
    private function consoleSeo(): array
    {
        return [
            'console.rank' => ['title' => '플레이스 순위 추적', 'desc' => '키워드별 네이버 플레이스 순위를 매일 자동 기록하고 추이·리뷰 변화를 추적합니다.'],
            'console.compete' => ['title' => '플레이스 경쟁 분석', 'desc' => '상위 경쟁 업체와 내 플레이스의 리뷰·저장·SEO 신호를 비교 분석합니다.'],
            'console.shop-rank' => ['title' => '쇼핑 순위 추적', 'desc' => '네이버 쇼핑 검색에서 상품·업체의 키워드별 순위를 매일 추적합니다.'],
            'console.keyword' => ['title' => '키워드 분석', 'desc' => '월간 검색량·성별/연령·계절성 트렌드·연관 키워드를 한 번에 분석합니다.'],
            'console.smartplace' => ['title' => '스마트플레이스 리포트', 'desc' => '방문·유입·리뷰·예약 통계를 스마트플레이스에서 수집해 리포트로 제공합니다.'],
            'console.blog' => ['title' => '블로그 분석', 'desc' => '검색 노출 블로그를 수집하고 방문·이웃·전문성 지수를 분석합니다.'],
            'console.market' => ['title' => '쇼핑 시장 분석', 'desc' => '키워드 시장 규모·매출·경쟁 구도와 진입 전략을 분석합니다.'],
            'console.product' => ['title' => '상품 리뷰 분석', 'desc' => '스마트스토어 상품 리뷰의 감정·옵션·약점을 분석합니다.'],
            'console.seller-power' => ['title' => '셀러력 진단', 'desc' => '쇼핑 상품의 5축 SEO·지수를 경쟁 상품과 비교 진단합니다.'],
            'console.api-keys' => ['title' => 'API 키', 'desc' => '순위·경쟁·키워드 분석 REST API 키를 발급·관리합니다.'],
        ];
    }
};
