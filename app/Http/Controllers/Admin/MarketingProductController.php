<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MarketingProduct;
use App\Models\ProductField;
use App\Models\ProductSubType;
use App\Models\ProductType;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** 마케팅 상품 관리(admin) — 상품 CRUD + 동적 주문 필드(폼 빌더) 저장. */
class MarketingProductController extends Controller
{
    public function index()
    {
        return view('admin.products.index', [
            'products' => MarketingProduct::withCount('fields', 'orders')->latest()->paginate(20),
            'types' => ProductType::orderBy('sort_order')->get()->keyBy('code'),
        ]);
    }

    public function create()
    {
        return $this->form(new MarketingProduct(['product_type' => 'REWARD', 'is_active' => true]), 'create');
    }

    public function edit(MarketingProduct $product)
    {
        return $this->form($product->load('fields'), 'edit');
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $product = MarketingProduct::create($data + ['created_by' => $request->user()->id]);
        $this->syncFields($product, $request);

        return redirect()->route('admin.products.edit', $product)->with('status', '상품이 생성되었습니다. 주문 URL이 발급되었습니다.');
    }

    public function update(Request $request, MarketingProduct $product)
    {
        $product->update($this->validated($request));
        $this->syncFields($product, $request);

        return redirect()->route('admin.products.edit', $product)->with('status', '상품이 저장되었습니다.');
    }

    public function toggle(MarketingProduct $product)
    {
        $product->update(['is_active' => ! $product->is_active]);

        return back();
    }

    public function destroy(MarketingProduct $product)
    {
        $product->delete();

        return redirect()->route('admin.products')->with('status', '상품이 삭제되었습니다.');
    }

    // ── 내부 ──────────────────────────────────────────────

    private function form(MarketingProduct $product, string $mode)
    {
        $groupNames = collect();
        $fieldsJson = [];
        if ($product->exists) {
            $product->load('fields', 'fieldGroups');
            $groupOrder = $product->fieldGroups->pluck('sort_order', 'id');
            $groupNames = $product->fieldGroups->pluck('name', 'id');
            // 그룹(단계) 순 → 필드 순으로 정렬해 단계가 연속되게(빌더에서 단계 헤더 재구성용)
            $fieldsJson = $product->fields
                ->sortBy(fn ($f) => [$groupOrder->get($f->group_id, 999), $f->sort_order])
                ->map(fn ($f) => [
                    'field_key' => $f->field_key, 'field_type' => $f->field_type, 'label' => $f->label,
                    'placeholder' => $f->placeholder, 'help_text' => $f->help_text, 'is_required' => (bool) $f->is_required,
                    'options' => $f->options_json ?? [], 'group' => $groupNames->get($f->group_id, ''), 'sort_order' => $f->sort_order,
                ])->values()->toArray();
        }

        return view('admin.products.form', [
            'product' => $product,
            'mode' => $mode,
            'types' => ProductType::where('is_active', true)->orderBy('sort_order')->get(),
            'subTypes' => ProductSubType::where('is_active', true)->orderBy('sort_order')->get()->groupBy('product_type'),
            'fieldTypes' => ProductField::TYPES,
            'fieldsJson' => $fieldsJson,
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'product_type' => ['required', 'string', 'max:40'],
            'sub_type_code' => ['nullable', 'string', 'max:40'],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'base_cost' => ['required', 'numeric', 'min:0'],
            'min_price' => ['required', 'numeric', 'min:0'],
            'min_quantity' => ['required', 'integer', 'min:1'],
            'max_quantity' => ['required', 'integer', 'min:1'],
            'min_days' => ['required', 'integer', 'min:1'],
            'quantity_mode' => ['required', 'in:daily,total'],
            'min_daily_quantity' => ['nullable', 'integer', 'min:0'],
            'field_render_mode' => ['required', 'in:inline,step'],
            'default_fulfillment' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'daily_cutoff_hour' => ['nullable', 'integer', 'min:0', 'max:23'],
            'process_weekends' => ['nullable', 'boolean'],
            'process_holidays' => ['nullable', 'boolean'],
            'processing_lag_days' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]) + [
            'process_weekends' => $request->boolean('process_weekends'),
            'process_holidays' => $request->boolean('process_holidays'),
            'is_active' => $request->boolean('is_active'),
        ];
    }

    /** 폼 빌더 필드(JSON) → product_fields 동기화. */
    private function syncFields(MarketingProduct $product, Request $request): void
    {
        $fields = json_decode((string) $request->input('fields_json', '[]'), true);
        if (! is_array($fields)) {
            $fields = [];
        }

        // 단계(그룹) 먼저 upsert — 등장 순서대로. field_render_mode=step 일 때 주문 페이지 단계가 된다.
        $groupIds = [];
        $order = 0;
        foreach (array_values($fields) as $f) {
            $gname = trim((string) ($f['group'] ?? ''));
            if ($gname === '' || isset($groupIds[$gname])) {
                continue;
            }
            $grp = $product->fieldGroups()->updateOrCreate(
                ['name' => $gname],
                ['code' => 'step_'.($order + 1), 'is_system' => false, 'sort_order' => $order],
            );
            $groupIds[$gname] = $grp->id;
            $order++;
        }

        $keys = [];
        foreach (array_values($fields) as $i => $f) {
            $label = trim((string) ($f['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $type = in_array($f['field_type'] ?? '', array_keys(ProductField::TYPES), true) ? $f['field_type'] : 'TEXT';
            // 키는 내부 식별자 — 클라이언트가 자동 생성(field_N)하거나 기존 키를 보존해 보냄. 훼손 없이 그대로 사용.
            $key = trim((string) ($f['field_key'] ?? '')) ?: 'field_'.($i + 1);
            while (in_array($key, $keys, true)) {
                $key .= '_'.($i + 1);
            }
            $keys[] = $key;

            $opts = $f['options'] ?? [];   // [{value,label},…] 또는 문자열 배열
            if (is_string($opts)) {
                $opts = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $opts) ?: [])));
            }

            ProductField::updateOrCreate(
                ['product_id' => $product->id, 'field_key' => $key],
                [
                    'group_id' => $groupIds[trim((string) ($f['group'] ?? ''))] ?? null,
                    'field_type' => $type,
                    'label' => $label,
                    'placeholder' => $f['placeholder'] ?? null,
                    'help_text' => $f['help_text'] ?? null,
                    'is_required' => (bool) ($f['is_required'] ?? true),
                    'options_json' => in_array($type, ['SELECT', 'MULTI_SELECT'], true) ? $this->normOptions($opts) : null,
                    'sort_order' => $i,
                    'is_active' => true,
                ],
            );
        }

        // 폼에서 사라진 필드·그룹 제거
        $product->fields()->whereNotIn('field_key', $keys ?: ['__none__'])->delete();
        $product->fieldGroups()->whereNotIn('name', array_keys($groupIds) ?: ['__none__'])->delete();
    }

    /** 옵션 정규화 → [{value,label}]. */
    private function normOptions(array $opts): array
    {
        $out = [];
        foreach ($opts as $o) {
            if (is_array($o) && ($o['label'] ?? '') !== '') {
                $out[] = ['value' => (string) ($o['value'] ?? Str::slug($o['label'], '_')), 'label' => (string) $o['label']];
            } elseif (is_string($o) && trim($o) !== '') {
                $out[] = ['value' => Str::slug($o, '_') ?: $o, 'label' => trim($o)];
            }
        }

        return $out;
    }
}
