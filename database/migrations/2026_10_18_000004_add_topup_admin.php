<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::table('topups', function (Blueprint $t) {
            $t->string('company', 160)->default('');
            $t->string('bank_depositor', 60)->default('');
            $t->boolean('bank_received')->default(false);
            $t->boolean('vat_included')->nullable();
            $t->boolean('invoice_issued')->default(false);
            $t->unsignedInteger('approval_cycle')->default(0);
            $t->timestamp('deleted_at')->nullable();
        });
        DB::table('topups')->where('status', 'confirmed')->update(['bank_received' => true]);
        Schema::create('topup_audits', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('topup_id')->index();
            $t->string('actor', 100);
            $t->string('action', 24);
            $t->text('before_state');
            $t->text('after_state');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {

        Schema::dropIfExists('topup_audits');
        Schema::table('topups', fn (Blueprint $t) => $t->dropColumn(['company', 'bank_depositor', 'bank_received', 'vat_included', 'invoice_issued', 'approval_cycle', 'deleted_at']));
    }
};
