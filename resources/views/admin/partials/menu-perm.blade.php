{{-- 권한 매트릭스 — 등급/역할 × 접근/입력/수정/삭제 (menu-group·menu-item 공용) --}}
<form method="POST" action="{{ route('admin.menus.permissions', $menu) }}" class="px-4 py-3" style="background:var(--color-surface-soft);">
    @csrf
    <table class="w-full" style="font-size:var(--fs-xs);">
        <thead>
            <tr class="text-muted" style="font-size:var(--fs-xs);">
                <th class="text-left py-1" style="width:50%;">주체</th>
                <th class="py-1" style="width:100px;">접근</th>
                @unless ($menu->is_group)<th class="py-1" style="width:120px;">월 이용횟수</th>@endunless
            </tr>
        </thead>
        <tbody>
            @php
                // 슈퍼(is_super) 역할은 항상 전권 → 매트릭스에서 제외
                $permRows = [['grade', $grades, '등급'], ['role', $roles->where('is_super', false), '직원']];
            @endphp
            @foreach ($permRows as [$type, $rows, $tlabel])
                @foreach ($rows as $row)
                    @php
                        $mp = $menu->permissions->first(fn ($p) => $p->subject_type === $type && (int) $p->subject_id === (int) $row->id);
                        // 미설정(행 없음)은 기본 허용
                        $allowed = $mp === null ? true : (bool) $mp->can_access;
                    @endphp
                    <tr style="border-top:1px solid var(--color-hairline);">
                        <td class="py-1.5 text-ink">{{ $row->name }} <span class="text-muted-soft" style="font-size:var(--fs-xs);">{{ $tlabel }}</span></td>
                        <td class="text-center py-1.5">
                            <span class="flex items-center justify-center gap-2" style="font-size:var(--fs-xs);">
                                <label class="rf-switch"><input type="checkbox" name="perm[{{ $type }}:{{ $row->id }}][access]" value="1" @checked($allowed)><span class="rf-track"></span></label>
                                <span class="text-muted-soft">허용</span>
                            </span>
                        </td>
                        @unless ($menu->is_group)
                            <td class="text-center py-1.5">
                                <input type="number" name="perm[{{ $type }}:{{ $row->id }}][limit]" value="{{ $mp?->monthly_limit ?? -1 }}" min="-1" class="input" style="width:96px;height:30px;padding:0 8px;font-size:var(--fs-xs);text-align:right;">
                            </td>
                        @endunless
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
    <div class="flex items-center justify-between mt-2 flex-wrap gap-2">
        <span class="text-muted-soft" style="font-size:var(--fs-xs);">※ 슈퍼관리자는 항상 전권(설정 불필요) · 접근을 끄면 해당 메뉴 진입 차단 · 월 이용횟수: <b>-1</b> 무제한 · <b>0</b> 미제공 · <b>N</b> 월 N회(페이지 접속 기준)</span>
        <button type="submit" class="btn btn-primary btn-sm">권한 저장</button>
    </div>
</form>
