<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillKeywordProductsScope extends Command
{
    protected $signature = 'api-keys:backfill-keyword-products';

    protected $description = 'Grant the new products scope once to existing keyword members and keys';

    public function handle(): int
    {
        $counts = DB::transaction(function () {
            $marker = AppSetting::firstOrCreate(['key' => 'keyword_products_scope_backfill_1027'], ['value' => 'pending']);
            $marker = AppSetting::whereKey($marker->id)->lockForUpdate()->firstOrFail();
            if ($marker->value === 'done') {
                return null;
            }
            $counts = [];
            foreach (['users' => 'api_scopes', 'api_keys' => 'scopes'] as $table => $column) {
                $counts[$table] = 0;
                DB::table($table)->select('id', $column)->orderBy('id')->lockForUpdate()->chunkById(200, function ($rows) use ($table, $column, &$counts) {
                    foreach ($rows as $row) {
                        $scopes = json_decode($row->{$column} ?? '[]', true) ?: [];
                        if (in_array('keyword', $scopes, true) && ! in_array('keyword_products', $scopes, true)) {
                            $scopes[] = 'keyword_products';
                            DB::table($table)->where('id', $row->id)->update([$column => json_encode($scopes)]);
                            $counts[$table]++;
                        }
                    }
                });
            }
            $marker->update(['value' => 'done']);

            return $counts;
        });
        $this->info($counts === null ? 'Already completed; revoked permissions remain revoked.' : json_encode($counts));

        return self::SUCCESS;
    }
}
