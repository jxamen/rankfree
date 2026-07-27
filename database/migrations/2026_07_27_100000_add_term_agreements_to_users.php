<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 가입 시 약관 동의 이력(2026-07-27) — {키: {title, agreed_at, ip}}.
        // 마케팅·제3자 제공 동의는 "언제 무엇에 동의했는지" 근거를 남겨야 해서 제목 스냅샷까지 보관한다.
        Schema::table('users', function (Blueprint $table) {
            $table->text('term_agreements')->nullable()->after('api_scopes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('term_agreements');
        });
    }
};
