<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Community\CommunityActivitySimulator;
use App\Http\Controllers\Controller;
use App\Models\CommunityCategory;
use App\Models\Persona;
use Database\Seeders\PersonaSeeder;
use Illuminate\Http\Request;

/** 페르소나 관리 (운영자) — 커뮤니티 자동 활동자 설정 + 활동 시뮬레이션 실행. */
class PersonaController extends Controller
{
    public function index()
    {
        return view('admin.personas.index', [
            'personas' => Persona::orderByDesc('is_active')->orderBy('nickname')->paginate(30),
            'total' => Persona::count(),
            'activeCount' => Persona::where('is_active', true)->where('auto_active', true)->count(),
            'apiEnabled' => ! empty(config('services.anthropic.key')),
        ]);
    }

    public function create()
    {
        return view('admin.personas.form', [
            'persona' => new Persona([
                'tone' => 'friendly', 'emoji_level' => 1, 'post_length' => 'mid',
                'activity_level' => 'normal', 'post_weight' => 3, 'comment_weight' => 6, 'like_weight' => 8,
                'is_active' => true, 'auto_active' => true, 'interests' => [], 'active_hours' => [], 'preferred_categories' => [],
            ]),
            'categories' => CommunityCategory::orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['joined_at'] = now()->subDays(mt_rand(10, 300));
        Persona::create($data);

        return redirect()->route('admin.personas')->with('status', '페르소나를 추가했습니다.');
    }

    public function edit(Persona $persona)
    {
        return view('admin.personas.form', [
            'persona' => $persona,
            'categories' => CommunityCategory::orderBy('sort_order')->get(),
        ]);
    }

    public function update(Request $request, Persona $persona)
    {
        $persona->update($this->validated($request));

        return redirect()->route('admin.personas')->with('status', '페르소나를 수정했습니다.');
    }

    public function destroy(Persona $persona)
    {
        $persona->delete();

        return back()->with('status', '페르소나를 삭제했습니다.');
    }

    public function toggle(Persona $persona)
    {
        $persona->update(['auto_active' => ! $persona->auto_active]);

        return back()->with('status', '자동활동 상태를 변경했습니다.');
    }

    /** 랜덤 50개 일괄 생성(부족분 채움). */
    public function generate()
    {
        (new PersonaSeeder())->run();

        return back()->with('status', '페르소나를 일괄 생성했습니다. (총 '.Persona::count().'명)');
    }

    /** 지금 즉시 활동 생성(수동 시뮬레이션). */
    public function simulate(Request $request, CommunityActivitySimulator $simulator)
    {
        $count = max(1, min(50, (int) $request->input('count', 10)));
        @set_time_limit(300);
        $r = $simulator->run($count);

        return back()->with('status', "활동 생성 완료 — 글 {$r['posts']} · 댓글 {$r['comments']} · 좋아요 {$r['likes']}");
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'nickname' => 'required|string|max:40',
            'avatar_color' => 'nullable|string|max:9',
            'bio' => 'nullable|string|max:200',
            'age' => 'nullable|integer|min:10|max:99',
            'gender' => 'nullable|in:male,female,none',
            'region' => 'nullable|string|max:40',
            'interests' => 'nullable|string|max:300',      // 콤마 구분 입력
            'tone' => 'required|in:'.implode(',', array_keys(Persona::TONES)),
            'emoji_level' => 'required|integer|min:0|max:2',
            'post_length' => 'required|in:short,mid,long',
            'activity_level' => 'required|in:active,normal,rare',
            'post_weight' => 'required|integer|min:0|max:10',
            'comment_weight' => 'required|integer|min:0|max:10',
            'like_weight' => 'required|integer|min:0|max:10',
            'active_hours' => 'nullable|array',
            'active_hours.*' => 'in:morning,noon,evening,night',
            'preferred_categories' => 'nullable|array',
            'preferred_categories.*' => 'integer',
            'is_active' => 'nullable|boolean',
            'auto_active' => 'nullable|boolean',
        ]);

        // 콤마 구분 관심사 → 배열
        $data['interests'] = collect(explode(',', (string) ($data['interests'] ?? '')))
            ->map(fn ($s) => trim($s))->filter()->values()->all();
        $data['active_hours'] = array_values($data['active_hours'] ?? []);
        $data['preferred_categories'] = array_map('intval', $data['preferred_categories'] ?? []);
        $data['is_active'] = $request->boolean('is_active');
        $data['auto_active'] = $request->boolean('auto_active');

        return $data;
    }
}
