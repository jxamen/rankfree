<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 회원 등급(무료/유료) / 운영자 레벨. role(user|operator|super)은 이미 존재.
            $table->foreignId('grade_id')->nullable()->after('role')->constrained('member_grades')->nullOnDelete();
            $table->foreignId('operator_role_id')->nullable()->after('grade_id')->constrained('operator_roles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('grade_id');
            $table->dropConstrainedForeignId('operator_role_id');
        });
    }
};
