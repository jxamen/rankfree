<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Mixed direction index prevents a full-month filesort for the global API.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE keyword_shop_ranks ADD INDEX ksr_products_recent (collected_month, collected_at DESC, keyword, rnk, id), ALGORITHM=INPLACE, LOCK=NONE');
        } else {
            DB::statement('CREATE INDEX ksr_products_recent ON keyword_shop_ranks (collected_month, collected_at DESC, keyword, rnk, id)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE keyword_shop_ranks DROP INDEX ksr_products_recent, ALGORITHM=INPLACE, LOCK=NONE');
        } else {
            DB::statement('DROP INDEX ksr_products_recent');
        }
    }
};
