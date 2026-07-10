{{-- 권한 매트릭스 — 등급/역할 × 접근/입력/수정/삭제 (menu-group·menu-item 공용) --}}
<form method="POST" action="{{ route('admin.menus.permissions', $menu) }}" class="px-4 py-3" style="background:var(--color-surface-soft);">
    @csrf
    <table class="w-full" style="font-size:13px;">
        <thead>
            <tr class="text-muted" style="font-size:11px;">
                <th class="text-left py-1" style="width:44%;">주체</th>
                <th class="py-1">접근</th>
                <th class="py-1">입력</th>
                <th class="py-1">수정</th>
                <th class="py-1">삭제</th>
            </tr>
        </thead>
        <tbody>
            @foreach ([['grade', $grades, '등급'], ['role', $roles, '역할']] as [$type, $rows, $tlabel])
                @foreach ($rows as $row)
                    @php $mp = $menu->permissions->first(fn ($p) => $p->subject_type === $type && (int) $p->subject_id === (int) $row->id); @endphp
                    <tr style="border-top:1px solid var(--color-hairline);">
                        <td class="py-1.5 text-ink">{{ $row->name }} <span class="text-muted-soft" style="font-size:11px;">{{ $tlabel }}</span></td>
                        @foreach (['access', 'create', 'update', 'delete'] as $act)
                            <td class="text-center py-1.5">
                                <input type="checkbox" name="perm[{{ $type }}:{{ $row->id }}][{{ $act }}]" value="1" @checked($mp?->{'can_'.$act})>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
    <div class="flex items-center justify-between mt-2">
        <span class="text-muted-soft" style="font-size:11px;">※ 슈퍼관리자는 항상 전권</span>
        <button type="submit" class="btn btn-primary btn-sm">권한 저장</button>
    </div>
</form>
