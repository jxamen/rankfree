<?php

namespace App\Domain\Reward;

/**
 * 미션 안내 문구 — 클라이언트가 하드코딩하지 않도록 API 응답에 실어 보낸다.
 * 우선순위: ① 미션별 값(reward_missions.guide/question/placeholder) → ② 어드민 설정 → ③ config 기본값.
 * 문구를 바꾸는 데 배포가 필요 없어야 한다는 게 이 클래스의 존재 이유다.
 */
final class MissionCopy
{
    public const KIND_SHOPPING_TAG = 'shopping_tag';

    public const KIND_FALLBACK = 'fallback';

    /** 미션이 어떤 문구 세트를 쓰는지 — 태그로 채점하면 쇼핑 태그형 */
    public static function kindFor(int $tagCount): string
    {
        return $tagCount > 0 ? self::KIND_SHOPPING_TAG : self::KIND_FALLBACK;
    }

    /**
     * 상품 사진 — 없으면 설정된 대체 이미지를 준다.
     * 참여자는 사진으로 같은 상품인지 확인하므로, 자리가 비면 안내가 제 역할을 못 한다.
     */
    public static function productImage(?string $url): ?string
    {
        $url = trim((string) $url);

        return $url !== '' ? $url : (trim((string) config('reward.default_product_image')) ?: null);
    }

    /**
     * @param  array<string, mixed>  $vars  치환 변수 (tagIndex·shop_name 등)
     * @return array<int, string>
     */
    public static function guide(string $kind, array $vars): array
    {
        $lines = (array) self::setting($kind, 'guide', []);

        return array_values(array_filter(
            array_map(fn ($l) => self::render((string) $l, $vars), $lines),
            fn ($l) => $l !== '',
        ));
    }

    public static function line(string $kind, string $key, array $vars): string
    {
        return self::render((string) self::setting($kind, $key, ''), $vars);
    }

    /** {변수} 치환 — 값이 없는 변수는 빈 문자열로 지워 어색한 자리를 남기지 않는다 */
    public static function render(string $template, array $vars): string
    {
        $replace = [];
        foreach ($vars as $k => $v) {
            $replace['{'.$k.'}'] = (string) ($v ?? '');
        }

        return trim(preg_replace('/\s{2,}/u', ' ', strtr($template, $replace)) ?? '');
    }

    /**
     * 어드민 설정('reward.copy.{kind}.{key}')이 있으면 그 값, 없으면 config 기본값.
     * 설정은 SettingsServiceProvider 가 부팅 시 config 로 주입한다.
     */
    private static function setting(string $kind, string $key, mixed $default): mixed
    {
        return config("reward.copy.{$kind}.{$key}", $default)
            ?: config('reward.copy.'.self::KIND_FALLBACK.'.'.$key, $default);
    }

    /**
     * 미션 행(스냅샷 배열 또는 모델 배열)에서 치환 변수를 뽑는다.
     *
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    public static function vars(array $m, ?int $tagIndex, int $tagCount): array
    {
        return [
            'tagIndex' => $tagIndex,
            'tagCount' => $tagCount,
            'shop_name' => $m['shop_name'] ?? '',
            'product_title' => $m['product_title'] ?? '',
            'keyword' => $m['keyword'] ?? '',
            'price' => isset($m['product_price']) ? number_format((int) $m['product_price']) : '',
            'reward_item' => $m['reward_item'] ?? '',
            'reward_count' => $m['reward_count'] ?? '',
        ];
    }
}
