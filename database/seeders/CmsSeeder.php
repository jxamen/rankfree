<?php

namespace Database\Seeders;

use App\Models\Banner;
use App\Models\Faq;
use App\Models\MemberGrade;
use App\Models\Menu;
use App\Models\MenuPermission;
use App\Models\Notice;
use App\Models\OperatorRole;
use App\Models\Popup;
use App\Models\Qna;
use App\Models\User;
use Illuminate\Database\Seeder;

/** 콘텐츠(공지·FAQ·QnA·배너·팝업) 메뉴·권한·더미 데이터. 재실행 안전(firstOrCreate). */
class CmsSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedMenus();
        $this->seedNotices();
        $this->seedFaqs();
        $this->seedBanners();
        $this->seedPopups();
        $this->seedQnas();
    }

    private function seedMenus(): void
    {
        // 관리자 — 콘텐츠 관리 그룹
        $adminGroup = Menu::firstOrCreate(
            ['area' => 'admin', 'name' => '콘텐츠 관리', 'is_group' => true, 'parent_id' => null],
            ['icon' => '📝', 'sort_order' => 50, 'is_active' => true],
        );
        $adminItems = [
            ['name' => '공지사항', 'route' => 'admin.notices', 'icon' => '📢'],
            ['name' => 'FAQ', 'route' => 'admin.faqs', 'icon' => '❓'],
            ['name' => '1:1 문의', 'route' => 'admin.qnas', 'icon' => '💬'],
            ['name' => '배너 관리', 'route' => 'admin.banners', 'icon' => '🖼️'],
            ['name' => '팝업 관리', 'route' => 'admin.popups', 'icon' => '🪧'],
        ];
        $adminIds = [];
        foreach ($adminItems as $i => $m) {
            $menu = Menu::firstOrNew(['route' => $m['route']]);
            if (! $menu->exists) {
                $menu->fill(['area' => 'admin', 'name' => $m['name'], 'icon' => $m['icon'], 'parent_id' => $adminGroup->id, 'sort_order' => $i, 'is_active' => true])->save();
            }
            $adminIds[] = $menu->id;
        }

        // 콘솔 — 고객센터 그룹
        $consoleGroup = Menu::firstOrCreate(
            ['area' => 'console', 'name' => '고객센터', 'is_group' => true, 'parent_id' => null],
            ['icon' => '🎧', 'sort_order' => 90, 'is_active' => true],
        );
        $consoleItems = [
            ['name' => '공지사항', 'route' => 'console.notices', 'icon' => '📢'],
            ['name' => '자주 묻는 질문', 'route' => 'console.faq', 'icon' => '❓'],
            ['name' => '1:1 문의', 'route' => 'console.qna', 'icon' => '💬'],
        ];
        $consoleIds = [];
        foreach ($consoleItems as $i => $m) {
            $menu = Menu::firstOrNew(['route' => $m['route']]);
            if (! $menu->exists) {
                $menu->fill(['area' => 'console', 'name' => $m['name'], 'icon' => $m['icon'], 'parent_id' => $consoleGroup->id, 'sort_order' => $i, 'is_active' => true])->save();
            }
            $consoleIds[] = $menu->id;
        }

        // 권한 — 콘솔은 등급 전체 접근, 관리자는 직원 접근(슈퍼는 항상 전권)
        foreach ($consoleIds as $mid) {
            foreach (MemberGrade::pluck('id') as $gid) {
                MenuPermission::firstOrCreate(
                    ['menu_id' => $mid, 'subject_type' => 'grade', 'subject_id' => $gid],
                    ['can_access' => true, 'can_create' => true, 'can_update' => true, 'can_delete' => true],
                );
            }
        }
        if ($opId = OperatorRole::where('slug', 'operator')->value('id')) {
            foreach ($adminIds as $mid) {
                MenuPermission::firstOrCreate(['menu_id' => $mid, 'subject_type' => 'role', 'subject_id' => $opId], ['can_access' => true]);
            }
        }
    }

    private function seedNotices(): void
    {
        $notices = [
            ['업데이트', 'rankfree 콘솔 대시보드 개편 안내', '<p>안녕하세요, rankfree입니다.</p><p>구독 현황·남은 이용량·공지·홍보 소식을 한눈에 볼 수 있도록 <b>대시보드를 개편</b>했습니다. 좌측 <b>고객센터</b> 메뉴에서 공지사항·FAQ·1:1 문의도 이용하실 수 있습니다.</p>', true],
            ['이벤트', '신규 가입 시 순위 추적 슬롯 100개 무료', '<p>가입만 해도 <b>플레이스+쇼핑 순위 추적 슬롯 100개</b>를 무료로 드립니다. 추천인 등록 시 최대 200개까지 확장됩니다.</p>', true],
            ['업데이트', '쇼핑 시장 분석 · 상품 리뷰 분석 기능 추가', '<p>크롬 확장 프로그램으로 <b>쇼핑 시장 규모</b>와 <b>상품 리뷰(감정·옵션·약점) 분석</b>을 수집하고, 콘솔에서 내역을 확인·공유할 수 있습니다.</p>', false],
            ['점검', '정기 서버 점검 안내 (매주 화 03:00~04:00)', '<p>안정적인 서비스를 위해 매주 화요일 새벽 <b>03:00~04:00</b>에 정기 점검이 진행됩니다. 점검 시간에는 순위 수집이 일시 지연될 수 있습니다.</p>', false],
            ['일반', '네이버 정책 변경에 따른 순위 수집 안내', '<p>네이버 pcmap 순위 조회는 nCaptcha 토큰이 필요하며, 네이버 정책 변경 시 일시적으로 수집이 지연될 수 있습니다. 좌표는 서울 기준으로 고정 수집됩니다.</p>', false],
            ['이벤트', 'API·확장프로그램 베타 오픈', '<p>키워드 분석 API와 쇼핑 시장 분석 확장 프로그램이 베타 오픈되었습니다. <b>콘솔 › API 키 관리</b>에서 키를 발급받아 이용하세요.</p>', false],
        ];
        foreach ($notices as $i => [$cat, $title, $body, $pinned]) {
            Notice::firstOrCreate(
                ['title' => $title],
                ['category' => $cat, 'body' => $body, 'is_pinned' => $pinned, 'is_published' => true, 'published_at' => now()->subDays(count($notices) - $i)],
            );
        }
    }

    private function seedFaqs(): void
    {
        // [카테고리, 질문, 답변(HTML)]
        $faqs = [
            ['시작하기', 'rankfree는 어떤 서비스인가요?', '<p>rankfree는 네이버 <b>플레이스·쇼핑 순위</b>와 경쟁사, 키워드, 블로그 지수를 무료로 분석하는 마케팅 분석 SaaS입니다. 가입 없이 순위 조회부터 시작할 수 있고, 회원가입하면 추적·알림·리포트를 이용할 수 있습니다.</p>'],
            ['시작하기', '회원가입은 무료인가요?', '<p>네, 가입과 기본 분석은 무료입니다. 가입 시 순위 추적 슬롯 100개가 기본 제공되며, 더 많은 자동 추적·대량 분석이 필요하면 유료 요금제로 전환할 수 있습니다.</p>'],
            ['시작하기', '어떤 순서로 사용하면 되나요?', '<p>① 홈에서 <b>무료 순위 조회</b> → ② 회원가입 → ③ 콘솔에서 <b>순위 추적 등록</b> → ④ 키워드·경쟁·블로그 분석 활용 순으로 이용하시길 권장합니다.</p>'],

            ['순위 추적', '순위 추적 슬롯이 무엇인가요?', '<p>추적하려는 <b>키워드 × 플레이스</b> 조합 하나가 슬롯 1개입니다. 등록해두면 순위 변동을 누적 기록합니다. 무료 등급은 플레이스+쇼핑 합산 <b>100개</b>까지 이용할 수 있습니다.</p>'],
            ['순위 추적', '플레이스와 쇼핑 순위추적 슬롯은 따로 계산되나요?', '<p>아니요. 두 순위추적은 <b>하나의 공유 풀</b>을 사용합니다. 예를 들어 한도가 100개면 플레이스 60개 + 쇼핑 40개처럼 합산 100개 내에서 자유롭게 배분됩니다.</p>'],
            ['순위 추적', '순위가 "300위 밖"으로 나옵니다.', '<p>네이버 플레이스 순위는 상위 300위까지만 조회됩니다. 300위 밖이면 노출이 어려운 상태이므로 리뷰·저장·키워드 최적화를 먼저 개선하시길 권장합니다.</p>'],
            ['순위 추적', '순위 좌표(지역)를 바꿀 수 있나요?', '<p>순위 수집 좌표는 <b>서울 기준으로 고정</b>되어 있습니다. 이는 지역 좌표를 주입하면 실제 모바일 순위와 어긋나는 문제를 피하기 위한 설계입니다.</p>'],

            ['쇼핑 순위추적', '쇼핑 순위추적은 무엇을 추적하나요?', '<p>네이버쇼핑 검색 결과에서 특정 <b>상품 또는 업체</b>가 키워드별로 몇 위인지 추적합니다. 상품 URL 또는 몰명으로 등록할 수 있습니다.</p>'],
            ['쇼핑 순위추적', '상품이 검색결과에서 안 잡혀요.', '<p>가격비교(카탈로그)로 묶여 있거나 판매 중지 상태면 조회되지 않을 수 있습니다. 상품 원본 URL과 정확한 키워드로 다시 등록해 보세요.</p>'],

            ['경쟁 분석', '경쟁 분석은 어떤 정보를 주나요?', '<p>같은 키워드 상위 플레이스들의 <b>SEO 점수(N1/N2/N3)·리뷰·저장·블로그 지수</b>를 비교해, 내 플레이스가 어디서 밀리는지 축별로 보여줍니다.</p>'],
            ['경쟁 분석', 'N1·N2·N3 점수는 네이버 공식 점수인가요?', '<p>아니요. N1~N3(및 D1~D10) 점수는 관측 신호를 바탕으로 한 <b>rankfree 자체 추정치</b>이며, 네이버 공식 점수가 아닙니다. 상대 비교 용도로 참고하세요.</p>'],

            ['스마트플레이스', '스마트플레이스 리포트는 무엇인가요?', '<p>내 스마트플레이스의 <b>통계·리뷰·스마트콜·예약</b> 데이터를 수집해 성과를 정리한 리포트입니다. 네이버 계정 연동이 필요합니다.</p>'],
            ['스마트플레이스', '네이버 계정 정보는 안전하게 보관되나요?', '<p>네이버 자격증명·쿠키 등 민감정보는 <b>암호화 저장</b>되며, 로그·응답에 노출되지 않습니다. 최고 수준의 보안 기준으로 취급합니다.</p>'],

            ['키워드 분석', '키워드 분석에서 무엇을 볼 수 있나요?', '<p>월간 <b>검색량(PC/모바일)·성별·연령·12개월 트렌드·요일별·연관 키워드·콘텐츠 포화 지수</b>를 제공합니다. 상단 검색창에 키워드를 입력하면 바로 분석됩니다.</p>'],
            ['키워드 분석', '"상품수/검색량" 지표는 어떻게 해석하나요?', '<p>값이 <b>낮을수록</b> 검색 수요 대비 경쟁 상품이 적어 진입 기회가 큽니다. 반대로 높으면 이미 상품이 포화된 키워드입니다.</p>'],
            ['키워드 분석', '검색량·등급·상업성은 정확한가요?', '<p>검색량은 네이버 검색광고 keywordstool 기준이며, <b>등급·상업성·포화 지수·이슈성</b>은 관측 신호 기반 자체 추정치입니다.</p>'],

            ['블로그 분석', '블로그 지수 분석은 무엇을 계산하나요?', '<p>블로그의 <b>활동성·반응·방문자·이웃·게시물 품질(사진/본문/영상)·전문성(주제 집중도)</b>을 종합해 지수화하고 S~F 등급을 매깁니다.</p>'],
            ['블로그 분석', '조회수는 왜 표시되지 않나요?', '<p>네이버가 블로그 조회수를 비공개(0)로 제공하는 경우가 많아 지수에 반영하지 않습니다. 대신 <b>일별 방문자수</b> 추이를 제공합니다.</p>'],
            ['블로그 분석', '키워드로 블로거 여러 명을 한 번에 볼 수 있나요?', '<p>네. 키워드를 입력하면 해당 검색에 노출된 블로거 전부의 지수·방문·게시물 품질을 표로 비교할 수 있습니다. 각 행을 클릭하면 단건 지수 분석으로 이동합니다.</p>'],

            ['시장·상품 분석', '쇼핑 시장 분석은 어떻게 하나요?', '<p>크롬 확장 프로그램을 설치하고 네이버쇼핑 검색 페이지에서 수집하면, <b>6개월 시장 규모·판매량·상위 상품·경쟁 강도</b>를 추정해 콘솔에 저장합니다.</p>'],
            ['시장·상품 분석', '상품 리뷰 분석은 무엇을 보여주나요?', '<p>스마트스토어 상품의 <b>최근 리뷰 추이(7일/1개월/3개월)·재구매 비율·인기 옵션·감정 분석·약점 키워드</b>와 상품 문의(QnA) 형태소 분석을 제공합니다.</p>'],
            ['시장·상품 분석', '분석 결과를 공유할 수 있나요?', '<p>네. 시장·상품·키워드 분석 상세에서 <b>공유</b> 버튼을 누르면 비로그인으로 열람 가능한 공개 리포트 링크가 복사됩니다.</p>'],

            ['API·확장프로그램', 'API 키는 어디서 발급하나요?', '<p><b>콘솔 › API 키 관리</b>에서 발급할 수 있습니다. 발급된 키는 암호화 저장되어 다시 복사할 수 있고, 필요 시 재발급(회전)할 수 있습니다.</p>'],
            ['API·확장프로그램', '확장 프로그램은 로그인이 필요한가요?', '<p>네. 크롬 확장 프로그램은 rankfree 계정 로그인 후 발급된 토큰으로 인증하며, 수집 결과는 본인 계정에 저장됩니다.</p>'],
            ['API·확장프로그램', '데이터 수집이 자꾸 실패해요.', '<p>네이버 순위 조회는 nCaptcha 토큰이 필요하며 만료 시 405/429가 발생할 수 있습니다. 확장 프로그램을 최신 버전으로 업데이트하고 다시 시도해 주세요.</p>'],

            ['구독·결제', '요금제는 어떻게 구성되나요?', '<p><b>무료 / 프로 / 대행</b> 등급으로 구성됩니다. 무료는 순위 100개·기본 분석, 프로는 순위 무제한·자동추적, 대행은 마케팅 대행까지 포함합니다.</p>'],
            ['구독·결제', '이용량 한도는 어떻게 확인하나요?', '<p>대시보드 상단의 <b>이번 달 기능별 이용량</b>에서 키워드·시장·상품·경쟁 분석의 사용량과 잔여 횟수를 확인할 수 있습니다. 한도는 요금제(등급)별로 다릅니다.</p>'],
            ['구독·결제', '구독을 중간에 변경하면 어떻게 되나요?', '<p>상위 요금제로 전환하면 즉시 확장된 한도가 적용됩니다. 자세한 변경·환불은 <b>1:1 문의</b>로 요청해 주세요.</p>'],

            ['계정', '비밀번호를 잊어버렸어요.', '<p>로그인 화면에서 비밀번호 재설정을 이용하거나, 접속이 어려우면 가입 이메일로 <b>1:1 문의</b>를 남겨주시면 도와드리겠습니다.</p>'],
            ['계정', '탈퇴하면 데이터는 어떻게 되나요?', '<p>탈퇴 시 회원 정보와 저장된 분석·추적 데이터가 삭제됩니다. 삭제 전 필요한 리포트는 공유 링크나 엑셀로 백업해 두시길 권장합니다.</p>'],
        ];
        foreach ($faqs as $i => [$cat, $q, $a]) {
            Faq::firstOrCreate(
                ['question' => $q],
                ['category' => $cat, 'answer' => $a, 'sort_order' => $i, 'is_published' => true],
            );
        }
    }

    private function seedBanners(): void
    {
        $banners = [
            ['product', '신규: 쇼핑 시장 분석 확장', '설치 즉시 시장 규모·상위 상품·경쟁강도 수집', 'https://chrome.google.com/webstore', '설치하기', '#111111', '#ffffff', 0],
            ['company', '마케팅 대행이 필요하신가요?', '플레이스·블로그·쇼핑 통합 대행 상담', null, '상담 신청', '#1f6feb', '#ffffff', 1],
            ['promo', '프로 요금제 30일 무료 체험', '순위 무제한·자동추적을 지금 경험하세요', null, '체험 시작', '#0b6b3a', '#ffffff', 2],
        ];
        foreach ($banners as [$type, $title, $sub, $link, $label, $bg, $fg, $sort]) {
            Banner::firstOrCreate(
                ['title' => $title],
                ['type' => $type, 'subtitle' => $sub, 'link_url' => $link, 'link_label' => $label, 'bg_color' => $bg, 'text_color' => $fg, 'sort_order' => $sort, 'is_active' => true],
            );
        }
    }

    private function seedPopups(): void
    {
        Popup::firstOrCreate(
            ['title' => '대시보드가 새로워졌어요 🎉'],
            [
                'body' => '<p>구독 현황과 <b>남은 이용량</b>, 공지·홍보 소식을 한 화면에서 확인하세요.</p><p>좌측 <b>고객센터</b>에서 FAQ 검색과 1:1 문의도 이용할 수 있습니다.</p>',
                'position' => 'center', 'width' => 440, 'is_active' => true, 'dismissible' => true, 'sort_order' => 0,
            ],
        );
    }

    private function seedQnas(): void
    {
        $user = User::orderBy('id')->first();
        if (! $user) {
            return;
        }
        $samples = [
            ['서비스 이용', '순위 추적 슬롯을 더 늘릴 수 있나요?', '무료 100개를 다 써서요. 추가 방법이 궁금합니다.', false, 'answered', '<p>추천인 등록 시 최대 200개까지 무료 확장되며, 그 이상은 프로 요금제로 전환하시면 무제한 이용이 가능합니다.</p>'],
            ['오류 신고', '쇼핑 시장 분석 수집이 중간에 멈춰요.', '가격비교 판매처 확인 중에서 오래 걸리다가 실패합니다.', false, 'answered', '<p>가격비교 상품이 많은 키워드는 판매처 병렬 조회에 시간이 걸릴 수 있습니다. 확장 프로그램을 최신 버전으로 업데이트한 뒤 다시 시도해 주세요. 반복되면 키워드와 시각을 알려주시면 확인하겠습니다.</p>'],
            ['결제·환불', '프로 요금제 결제 수단이 궁금합니다.', '카드 외에 계좌이체도 되나요?', false, 'pending', null],
            ['제휴·대행', '플레이스 마케팅 대행 상담 요청', '병원 플레이스 상위노출 대행이 가능한지 문의드립니다.', true, 'pending', null],
        ];
        foreach ($samples as $i => [$cat, $title, $body, $secret, $status, $answer]) {
            Qna::firstOrCreate(
                ['user_id' => $user->id, 'title' => $title],
                [
                    'category' => $cat, 'body' => $body, 'is_secret' => $secret, 'status' => $status,
                    'answer' => $answer,
                    'answered_at' => $status === 'answered' ? now()->subDays(1) : null,
                    'answered_by' => $status === 'answered' ? $user->id : null,
                    'created_at' => now()->subDays(count($samples) - $i + 1),
                ],
            );
        }
    }
}
