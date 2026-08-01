<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\Request;

/**
 * 리워드 설정(운영자) — 미션 안내 문구·정답 소스.
 * 환경 설정(/admin/settings)에서 분리한 이유: 미션 타입이 늘어나면 항목이 계속 늘어난다(.claude/reward).
 * 문구는 API 응답에 실려 나가므로 여기서 고치면 매체 클라이언트 배포 없이 즉시 반영된다.
 */
class RewardSettingsController extends Controller
{
    /** 문구 세트 — 미션 타입별로 다른 안내를 쓴다. 새 타입이 생기면 여기에 추가한다 */
    public const KINDS = [
        'shopping_tag' => ['label' => '쇼핑 · 해시태그형', 'desc' => '검색 유입 후 상품 상세의 태그를 확인해 입력'],
        'fallback' => ['label' => '기본형', 'desc' => '태그가 없는 미션에 쓰는 안내'],
    ];

    public function index()
    {
        return view('admin.reward.settings', [
            'kinds' => self::KINDS,
            'answerSource' => (string) (AppSetting::read('reward.answer_source') ?: config('reward.answer_source', 'tag')),
            'answerSources' => (array) config('reward.answer_sources', []),
            'defaultProductImage' => (string) AppSetting::read('reward.default_product_image'),
            'defaultProductImageLive' => (string) config('reward.default_product_image'),
            'copy' => collect(array_keys(self::KINDS))->mapWithKeys(fn ($kind) => [$kind => [
                'guide' => AppSetting::readJson("reward.copy.{$kind}.guide"),
                'question' => AppSetting::read("reward.copy.{$kind}.question"),
                'placeholder' => AppSetting::read("reward.copy.{$kind}.placeholder"),
                'notice' => AppSetting::read("reward.copy.{$kind}.notice"),
                'description' => AppSetting::read("reward.copy.{$kind}.description"),
            ]])->all(),
            'poolVendorId' => (int) config('reward.pool_vendor_id'),
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'answer_source' => ['nullable', 'string', 'max:20'],
            'default_product_image' => ['nullable', 'string', 'max:500', 'url'],
            'copy' => ['nullable', 'array'],
            'copy.*.guide' => ['nullable', 'string', 'max:2000'],
            'copy.*.question' => ['nullable', 'string', 'max:200'],
            'copy.*.placeholder' => ['nullable', 'string', 'max:100'],
            'copy.*.notice' => ['nullable', 'string', 'max:300'],
            'copy.*.description' => ['nullable', 'string', 'max:300'],
        ], [], ['copy.*.guide' => '참여 방법', 'copy.*.question' => '질문 문구']);

        $source = (string) $request->input('answer_source', '');
        AppSetting::write('reward.answer_source',
            array_key_exists($source, (array) config('reward.answer_sources', [])) ? $source : '');

        AppSetting::write('reward.default_product_image', trim((string) $request->input('default_product_image', '')));

        foreach (array_keys(self::KINDS) as $kind) {
            // 참여 방법은 줄바꿈 구분 입력 → 배열 JSON(빈 줄 제거, 순서 유지). 비우면 기본 문구로 되돌아간다
            $lines = array_values(array_filter(array_map(
                'trim', preg_split('/\R/u', (string) $request->input("copy.{$kind}.guide", '')) ?: [],
            ), fn ($l) => $l !== ''));
            AppSetting::write("reward.copy.{$kind}.guide", $lines ? json_encode($lines, JSON_UNESCAPED_UNICODE) : '');

            foreach (['question', 'placeholder', 'notice', 'description'] as $key) {
                AppSetting::write("reward.copy.{$kind}.{$key}",
                    trim((string) $request->input("copy.{$kind}.{$key}", '')));
            }
        }

        return redirect()->route('admin.reward.settings')->with('status', '리워드 설정을 저장했습니다.');
    }
}
