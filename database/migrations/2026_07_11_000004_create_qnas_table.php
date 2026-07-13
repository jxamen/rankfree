<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 1:1 문의(QnA) — 회원 작성, 관리자 답변. 비밀글 지원. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qnas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('category', 40)->default('일반');
            $t->string('title');
            $t->longText('body');
            $t->boolean('is_secret')->default(false);
            $t->string('status', 20)->default('pending');  // pending/answered
            $t->longText('answer')->nullable();
            $t->timestamp('answered_at')->nullable();
            $t->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qnas');
    }
};
