    <div class="card p-4 mb-2 flex items-center gap-3" data-analysis-id="{{ $a->id }}">
        <a href="{{ route('admin.shop-keyword.show', $a) }}" style="flex:1;min-width:0;text-decoration:none;">
            <div class="text-ink font-semibold" style="font-size:var(--fs-sm);">{{ $a->core_keyword }}
                <span class="text-muted-soft font-normal" style="font-size:var(--fs-xs);">· {{ $a->product_id ? '상품 '.$a->product_id : ($a->mall_name ?: '대상 미상') }}</span>
            </div>
            <div class="text-muted" style="font-size:var(--fs-xs);">조합 {{ $a->combo_count }} · 확인 {{ $a->checked_count }} · 단축 URL {{ $a->short_links_count }} · <b class="text-ink">상위 {{ $a->threshold }}위 노출 {{ $a->exposed_count }}</b>
                @if ($a->status === 'blocked')<span class="text-error">· 일부 미확인(열면 이어서 확인)</span>
                @elseif ($a->status === 'paused')<span class="text-muted">· 중단됨("이어서 확인"으로 재개)</span>@endif
            </div>
            @include('admin.shop-keyword.collection', ['a' => $a])
        </a>
        <span class="font-mono text-muted-soft" style="font-size:var(--fs-xs);">{{ $a->created_at->timezone('Asia/Seoul')->format('m-d H:i') }}</span>
        <div class="flex flex-wrap gap-2">
            <button type="button" class="btn btn-secondary btn-sm" data-short-action="regenerate" data-url="{{ route('admin.shop-keyword.short-links.store', $a) }}" data-count="{{ $a->short_links_count }}" @disabled(!$a->short_links_count)>URL 재생성</button>
            <button type="button" class="btn btn-secondary btn-sm" data-short-action="reassign" data-url="{{ route('admin.shop-keyword.short-links.reassign', $a) }}" @disabled(!$a->short_links_count)>키워드 재배정</button>
            <span data-short-result role="status" style="font-size:var(--fs-xs)"></span>
        </div>
        <form method="POST" action="{{ route('admin.shop-keyword.destroy', $a) }}"
              data-confirm="'{{ $a->core_keyword }}' 분석을 삭제할까요?" data-confirm-text="조합·확인 결과·단축 URL이 함께 삭제되며 되돌릴 수 없습니다.">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-ghost btn-sm text-error">삭제</button>
        </form>
    </div>
