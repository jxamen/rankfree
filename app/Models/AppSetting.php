<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** 앱 환경 설정 (key→value). value 는 암호화 저장 — API 자격증명 등 시크릿 보관. */
class AppSetting extends Model
{
    /** 커스텀 head 코드 캐시 키(설정 저장 시 무효화). */
    public const CUSTOM_HEAD_CACHE = 'app:custom_head';

    /** 커스텀 body 시작 코드 캐시 키 — GTM noscript 처럼 <body> 바로 뒤에 와야 하는 코드(2026-07-27). */
    public const CUSTOM_BODY_CACHE = 'app:custom_body';

    protected $fillable = ['key', 'value'];

    // 값 암호화(저장 시 암호화, 조회 시 복호화). 네이버 자격증명 등 시크릿 보호.
    protected $casts = ['value' => 'encrypted'];

    /** 단일 값(복호화) 조회. */
    public static function read(string $key, ?string $default = null): ?string
    {
        $row = static::query()->where('key', $key)->first();

        return $row ? (string) $row->value : $default;
    }

    /** 저장(암호화). 빈 문자열도 저장(명시적 비우기). */
    public static function write(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    /** 전체 key→value(복호화) 맵. 캐스팅 적용 위해 모델 인스턴스로 조회. */
    public static function map(): array
    {
        return static::all()->mapWithKeys(fn ($s) => [$s->key => (string) $s->value])->all();
    }

    /** JSON 값 → 배열. */
    public static function readJson(string $key): array
    {
        $raw = static::read($key);
        $arr = $raw ? json_decode($raw, true) : [];

        return is_array($arr) ? $arr : [];
    }

    /**
     * 어드민 환경설정의 커스텀 head 코드(CSS+HTML/스크립트) 조합 — 페이지 <head> 주입용.
     * 매 요청 DB 조회·복호화를 피해 캐시(설정 저장 시 무효화). 관리자 신뢰 입력이라 원문 그대로 출력.
     */
    public static function customHead(): string
    {
        try {
            return Cache::rememberForever(self::CUSTOM_HEAD_CACHE, function () {
                $css = trim((string) static::read('custom.head_css'));
                $html = trim((string) static::read('custom.head_html'));
                $out = '';
                if ($css !== '') {
                    $out .= "<style>\n".$css."\n</style>\n";
                }
                if ($html !== '') {
                    $out .= $html."\n";
                }

                return $out;
            });
        } catch (\Throwable $e) {
            return '';   // DB/테이블 미준비 등 — head 주입 생략(페이지는 정상 렌더)
        }
    }

    /**
     * 어드민 환경설정의 커스텀 body 시작 코드 — <body> 바로 뒤 주입용(2026-07-27).
     * GTM noscript(iframe) 처럼 head 가 아니라 body 첫머리에 있어야 동작하는 코드를 넣는다.
     * head 와 같은 이유로 캐시하고, 설정 저장 시 무효화한다.
     */
    public static function customBody(): string
    {
        try {
            return Cache::rememberForever(self::CUSTOM_BODY_CACHE, function () {
                $html = trim((string) static::read('custom.body_html'));

                return $html !== '' ? $html."\n" : '';
            });
        } catch (\Throwable $e) {
            return '';   // DB 미준비 등 — 주입 생략(페이지는 정상 렌더)
        }
    }
}
