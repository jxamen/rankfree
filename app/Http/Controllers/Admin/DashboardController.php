<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommunityPost;
use App\Models\GaStat;
use App\Models\KeywordCandidate;
use App\Models\KeywordSearch;
use App\Models\MarketingOrder;
use App\Models\PlaceRankSlot;
use App\Models\Qna;
use App\Models\ShopRankSlot;
use App\Models\User;
use Illuminate\Support\Carbon;

/** 관리자 대시보드 — 방문·가입·순위추적·문의·커뮤니티·키워드 발행 등 핵심 지표 한눈에. */
class DashboardController extends Controller
{
    private const TZ = 'Asia/Seoul';

    public function index()
    {
        $todayStart = Carbon::now(self::TZ)->startOfDay()->utc();   // KST 오늘 0시(UTC 저장분 비교용)
        $d7 = Carbon::now()->subDays(7);
        $d30 = Carbon::now()->subDays(30);

        // ── KPI ─────────────────────────────────────────
        $paidUsers = User::whereHas('grade', fn ($q) => $q->where('is_paid', true))
            ->where(fn ($q) => $q->whereNull('subscription_expires_at')->orWhere('subscription_expires_at', '>', now()))
            ->count();

        $kpi = [
            'users' => User::count(),
            'usersToday' => User::where('created_at', '>=', $todayStart)->count(),
            'users7' => User::where('created_at', '>=', $d7)->count(),
            'paid' => $paidUsers,
            'placeSlots' => PlaceRankSlot::count(),
            'placeActive' => PlaceRankSlot::where('is_active', true)->count(),
            'shopSlots' => ShopRankSlot::count(),
            'shopActive' => ShopRankSlot::where('is_active', true)->count(),
            'posts' => CommunityPost::count(),
            'postsUser' => CommunityPost::where('author_type', 'user')->count(),
            'posts7' => CommunityPost::where('created_at', '>=', $d7)->count(),
            'hubDocs' => KeywordSearch::where('origin', 'hub')->count(),
            'hubToday' => KeywordSearch::where('origin', 'hub')->where('created_at', '>=', $todayStart)->count(),
            'hub7' => KeywordSearch::where('origin', 'hub')->where('created_at', '>=', $d7)->count(),
            'qnaOpen' => Qna::where('status', '!=', 'answered')->count(),
            'qnaTotal' => Qna::count(),
            'orders' => MarketingOrder::count(),
            'ordersPending' => MarketingOrder::where('status', 'pending')->count(),
            'candApproved' => KeywordCandidate::where('status', 'approved')->count(),
            'candPending' => KeywordCandidate::where('status', 'pending')->count(),
        ];

        // ── 가입 추이(30일) ───────────────────────────────
        $signupTrend = $this->dailyTrend(User::query(), 30);

        // ── 방문(GA4, 최근 7일 · GaStat 수집분) ───────────
        $gaRows = GaStat::where('dimension', 'date')
            ->where('date', '>=', Carbon::now(self::TZ)->subDays(7)->toDateString())->get();
        $visits = [
            'has' => $gaRows->isNotEmpty(),
            'users' => (int) $gaRows->sum('users'),
            'sessions' => (int) $gaRows->sum('sessions'),
            'pageviews' => (int) $gaRows->sum('pageviews'),
            'lastAt' => GaStat::max('updated_at'),
        ];

        // ── 최근 목록 ─────────────────────────────────────
        $recentUsers = User::with('grade:id,name')->latest('id')->take(8)
            ->get(['id', 'name', 'email', 'grade_id', 'created_at']);

        $recentPlace = PlaceRankSlot::with('user:id,name')->latest('id')->take(6)
            ->get(['id', 'user_id', 'keyword', 'place_name', 'created_at']);
        $recentShop = ShopRankSlot::with('user:id,name')->latest('id')->take(6)
            ->get(['id', 'user_id', 'keyword', 'product_title', 'created_at']);
        $recentTracking = $recentPlace
            ->map(fn ($s) => ['type' => '플레이스', 'keyword' => $s->keyword, 'target' => $s->place_name, 'user' => $s->user?->name, 'at' => $s->created_at])
            ->concat($recentShop->map(fn ($s) => ['type' => '쇼핑', 'keyword' => $s->keyword, 'target' => $s->product_title, 'user' => $s->user?->name, 'at' => $s->created_at]))
            ->sortByDesc('at')->take(8)->values();

        $recentQna = Qna::with('user:id,name')->latest('id')->take(6)->get();
        $recentPosts = CommunityPost::with(['user:id,name', 'persona:id,nickname'])->latest('id')->take(6)->get();

        return view('admin.dashboard.index', compact(
            'kpi', 'signupTrend', 'visits', 'recentUsers', 'recentTracking', 'recentQna', 'recentPosts',
        ));
    }

    /** 최근 N일 일별 생성 건수 — [['date'=>Y-m-d,'count'=>n], …] (빈 날짜 0). */
    private function dailyTrend($query, int $days): array
    {
        $rows = (clone $query)->where('created_at', '>=', Carbon::now()->subDays($days))
            ->selectRaw('DATE(created_at) as d, count(*) as c')->groupBy('d')->pluck('c', 'd');

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->toDateString();
            $out[] = ['date' => $date, 'count' => (int) ($rows[$date] ?? 0)];
        }

        return $out;
    }
}
