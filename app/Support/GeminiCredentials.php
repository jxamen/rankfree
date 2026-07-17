<?php

namespace App\Support;

class GeminiCredentials
{
    public static function apiKey(): string
    {
        return trim((string) config('services.gemini.key', ''));
    }

    public static function model(): string
    {
        $model = trim((string) config('services.gemini.model', ''));

        return $model !== '' ? $model : 'gemini-2.5-flash';
    }

    public static function configured(): bool
    {
        return self::apiKey() !== '';
    }

    /**
     * @return array{key:string,model:string}|null
     */
    public static function credentials(): ?array
    {
        if (! self::configured()) {
            return null;
        }

        return [
            'key' => self::apiKey(),
            'model' => self::model(),
        ];
    }
}
