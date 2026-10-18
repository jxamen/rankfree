<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_orders', function (Blueprint $table) {
            $table->timestamp('external_dispatched_at')->nullable();
        });
        // Canceled records may already have reached a vendor. Preserve that evidence.
        DB::table('marketing_orders')->whereIn('id', function ($query) {
            $query->select('order_id')->from('order_dispatches')->where('channel', '!=', 'reward')
                ->where(function ($query) {
                    $query->where('status', 'sent')
                        ->orWhere(fn ($q) => $q->where('status', 'canceled')->whereNotNull('sent_at'))
                        ->orWhere('response', 'like', '%결과 불명%');
                });
        })->update(['external_dispatched_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('marketing_orders', fn (Blueprint $table) => $table->dropColumn('external_dispatched_at'));
    }
};
