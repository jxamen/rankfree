<?php

namespace App\Domain\Keyword;

use Illuminate\Support\Facades\Cache;

class KeywordHubCollectionControl
{
    private const KEY = 'hub:collection-control';

    private const TTL_DAYS = 30;

    public static function state(): array
    {
        $state = Cache::get(self::KEY, []);
        $state = is_array($state) ? $state : [];

        return array_merge([
            'enabled' => true,
            'updated_at' => null,
            'updated_by' => null,
        ], $state);
    }

    public static function enabled(): bool
    {
        return (bool) self::state()['enabled'];
    }

    public static function set(bool $enabled, ?string $updatedBy = null): array
    {
        $state = [
            'enabled' => $enabled,
            'updated_at' => now()->timestamp,
            'updated_by' => $updatedBy,
        ];

        Cache::put(self::KEY, $state, now()->addDays(self::TTL_DAYS));

        return self::state();
    }
}
