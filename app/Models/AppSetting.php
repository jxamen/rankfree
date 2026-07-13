<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 앱 환경 설정 (key→value). value 는 암호화 저장 — API 자격증명 등 시크릿 보관. */
class AppSetting extends Model
{
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
}
