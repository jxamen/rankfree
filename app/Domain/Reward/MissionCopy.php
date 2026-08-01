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
     * 참여 방법(텍스트만) — 구버전 클라이언트 호환.
     *
     * @param  array<string, mixed>  $vars  치환 변수 (tagIndex·shop_name 등)
     * @return array<int, string>
     */
    public static function guide(string $kind, array $vars): array
    {
        return array_column(self::steps($kind, $vars), 'text');
    }

    /**
     * 참여 방법(단계별) — 글만으로는 "어디를 눌러야 하는지"가 전달되지 않아 단계마다 예시 이미지를 붙인다.
     * 설정에서는 한 줄을 `설명 | 이미지URL` 로 적는다(이미지는 선택).
     *
     * @return array<int, array{step:int, text:string, image:?string}>
     */
    public static function steps(string $kind, array $vars): array
    {
        $out = [];
        foreach ((array) self::setting($kind, 'guide', []) as $line) {
            [$text, $image] = self::splitLine((string) $line);
            $text = self::render($text, $vars);
            if ($text === '') {
                continue;
            }
            $out[] = ['step' => count($out) + 1, 'text' => $text, 'image' => $image ?: null];
        }

        return $out;
    }

    /** `설명 | https://…` → [설명, 이미지]. 파이프가 없으면 이미지 없음 */
    private static function splitLine(string $line): array
    {
        $pos = mb_strrpos($line, '|');
        if ($pos === false) {
            return [trim($line), null];
        }

        $tail = trim(mb_substr($line, $pos + 1));

        // 파이프 뒤가 URL 일 때만 이미지로 본다 — 본문에 쓰인 파이프를 잘라먹지 않게
        return str_starts_with($tail, 'http')
            ? [trim(mb_substr($line, 0, $pos)), $tail]
            : [trim($line), null];
    }

    public static function line(string $kind, string $key, array $vars): string
    {
        return self::render((string) self::setting($kind, $key, ''), $vars);
    }

    /**
     * {변수} 치환. 값이 없으면 그 자리를 지우는데, 「」·[]·() 같은 감싸는 기호와 조사가 남으면
     * "「」 상품을 찾아" 처럼 깨진 문장이 된다 — 빈 껍데기까지 함께 정리한다.
     */
    public static function render(string $template, array $vars): string
    {
        $s = $template;
        $replace = [];

        foreach ($vars as $k => $v) {
            $value = (string) ($v ?? '');
            if ($value === '') {
                // 빈 변수는 감싸는 기호(「」·[]·())와 뒤따르는 조사까지 통째로 지운다.
                // 그러지 않으면 "{shop_name}의 「{product_title}」 상품" → "의 「」 상품" 처럼 깨진다
                $s = preg_replace(
                    '/[「\[(【]?\s*\{'.preg_quote($k, '/').'\}\s*[」\])】]?\s*(?:의|은|는|이|가|을|를|와|과|에서|에)?/u',
                    '', $s,
                ) ?? $s;

                continue;
            }
            $replace['{'.$k.'}'] = $value;
        }

        $s = strtr($s, $replace);
        $s = preg_replace('/\s{2,}/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+([,.])/u', '$1', $s) ?? $s;

        return trim($s);
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
