<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MemberGrade;
use App\Models\Menu;
use App\Models\MenuPermission;
use App\Models\OperatorRole;
use Illuminate\Http\Request;

/**
 * 메뉴 관리 — crm config/menu-list 이식.
 * 최상위(parent=null)에 대분류(is_group)와 미분류 항목이 sort_order 로 섞여 배치된다.
 * 드래그 정렬(reorder)·노출 토글(toggle)·CRUD·소속 변경·등급/역할 권한 매트릭스.
 */
class MenuController extends Controller
{
    public function index(Request $request)
    {
        $area = in_array($request->query('area'), ['console', 'admin'], true) ? $request->query('area') : 'console';

        $all = Menu::where('area', $area)->with('permissions')->orderBy('sort_order')->get();
        $roots = $all->whereNull('parent_id')->values();
        $children = $all->groupBy('parent_id');
        $grades = MemberGrade::orderBy('tier')->get();
        $roles = OperatorRole::orderBy('level')->get();

        return view('admin.menus', compact('area', 'all', 'roots', 'children', 'grades', 'roles'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'area' => ['required', 'in:console,admin'],
            'kind' => ['required', 'in:group,item'],
            'parent_id' => ['nullable', 'exists:menus,id'],
            'name' => ['required', 'string', 'max:80'],
            'route' => ['nullable', 'string', 'max:120'],
            'url' => ['nullable', 'string', 'max:200'],
            'icon' => ['nullable', 'string', 'max:60'],
            'target' => ['nullable', 'in:,_blank'],
            'meta_title' => ['nullable', 'string', 'max:150'],
            'meta_description' => ['nullable', 'string', 'max:255'],
        ]);
        $data['is_group'] = $data['kind'] === 'group';
        unset($data['kind']);
        $data['sort_order'] = (int) Menu::where('area', $data['area'])
            ->where('parent_id', $data['parent_id'] ?? null)->max('sort_order') + 1;
        $data['is_active'] = true;
        Menu::create($data);

        return back()->with('status', '메뉴가 추가되었습니다.');
    }

    public function update(Request $request, Menu $menu)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'parent_id' => ['nullable', 'exists:menus,id'],
            'route' => ['nullable', 'string', 'max:120'],
            'url' => ['nullable', 'string', 'max:200'],
            'icon' => ['nullable', 'string', 'max:60'],
            'target' => ['nullable', 'in:,_blank'],
            'meta_title' => ['nullable', 'string', 'max:150'],
            'meta_description' => ['nullable', 'string', 'max:255'],
        ]);
        // 자기 자신을 부모로 두는 것 방지
        if ((int) ($data['parent_id'] ?? 0) === $menu->id) {
            $data['parent_id'] = null;
        }
        $data['is_active'] = $request->boolean('is_active');
        $menu->update($data);

        return back()->with('status', '메뉴가 수정되었습니다.');
    }

    public function destroy(Menu $menu)
    {
        if (Menu::where('parent_id', $menu->id)->exists()) {
            return back()->withErrors(['menu' => '하위 메뉴가 있어 삭제할 수 없습니다. 하위를 먼저 옮기거나 삭제하세요.']);
        }
        $menu->delete();

        return back()->with('status', '메뉴가 삭제되었습니다.');
    }

    /** 노출 토글 (ajax) */
    public function toggle(Request $request, Menu $menu)
    {
        $menu->update(['is_active' => $request->boolean('is_active')]);

        return response()->json(['success' => true, 'is_active' => $menu->is_active]);
    }

    /** 드래그 정렬/이동 일괄 저장 (ajax) — order: [{id, parent_id, sort_order}] */
    public function reorder(Request $request)
    {
        foreach ((array) $request->input('order', []) as $o) {
            $id = (int) ($o['id'] ?? 0);
            if (! $id) {
                continue;
            }
            Menu::where('id', $id)->update([
                'parent_id' => isset($o['parent_id']) && $o['parent_id'] !== '' && $o['parent_id'] !== null ? (int) $o['parent_id'] : null,
                'sort_order' => (int) ($o['sort_order'] ?? 0),
            ]);
        }

        return response()->json(['success' => true]);
    }

    /** 메뉴별 권한 매트릭스 저장 (등급/역할 × 접근/입력/수정/삭제). */
    public function savePermissions(Request $request, Menu $menu)
    {
        $perm = (array) $request->input('perm', []);

        $subjects = [];
        foreach (MemberGrade::pluck('id') as $id) {
            $subjects[] = ['grade', (int) $id];
        }
        foreach (OperatorRole::pluck('id') as $id) {
            $subjects[] = ['role', (int) $id];
        }
        foreach ($subjects as [$type, $id]) {
            $a = (array) ($perm["{$type}:{$id}"] ?? []);
            MenuPermission::updateOrCreate(
                ['menu_id' => $menu->id, 'subject_type' => $type, 'subject_id' => $id],
                [
                    'can_access' => isset($a['access']),
                    'can_create' => isset($a['create']),
                    'can_update' => isset($a['update']),
                    'can_delete' => isset($a['delete']),
                ],
            );
        }

        return back()->with('status', "'{$menu->name}' 권한이 저장되었습니다.");
    }
}
