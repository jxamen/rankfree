<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 키워드 콘텐츠 허브 — 허브 문서는 새 모델 없이 KeywordSearch 를 재사용한다(22 결정사항).
 * origin=hub 문서는 시스템 소유(user_id NULL). 유일성은 코드에서 (origin=hub, keyword) 로 보장.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_searches', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('user_id')
                ->constrained('keyword_categories')->nullOnDelete();
            $table->string('origin', 10)->default('user')->after('category_id'); // user|hub
            $table->timestamp('refreshed_at')->nullable();  // hub:refresh 갱신 커서

            $table->index(['origin', 'refreshed_at']);
        });

        Schema::table('keyword_searches', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('keyword_searches', function (Blueprint $table) {
            $table->dropIndex(['origin', 'refreshed_at']);
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn(['origin', 'refreshed_at']);
        });
    }
};
