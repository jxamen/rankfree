<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 권한 인프라 — 회원 등급(무료/유료 단계) + 운영자 레벨 + 메뉴 트리 + 권한 매트릭스.
 * crm은 접근(메뉴 visibility)·액션(직급 perm_*)·무료유료(grade)가 분리돼 있었으나,
 * rankfree는 menu_permissions 로 (메뉴 × 주체 × 접근/입력/수정/삭제)를 통합한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 회원 등급 (무료/유료 모델별 단계)
        Schema::create('member_grades', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);                  // 무료 / 프로 / 대행
            $table->string('slug', 50)->unique();        // free / pro / agency
            $table->boolean('is_paid')->default(false);
            $table->unsignedInteger('tier')->default(0);  // 단계 순서(낮을수록 하위)
            $table->unsignedInteger('monthly_price')->nullable();
            $table->integer('rank_slot_limit')->default(100);   // 순위 추적 슬롯 한도(-1=무제한)
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // 운영자 레벨(역할)
        Schema::create('operator_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);                  // 슈퍼관리자 / 관리자 / 운영자
            $table->string('slug', 50)->unique();
            $table->unsignedInteger('level')->default(10); // 클수록 상위
            $table->boolean('is_super')->default(false);   // 전권(권한 검사 우회)
            $table->string('description', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // 메뉴 트리 (adjacency list)
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('menus')->nullOnDelete();
            $table->string('area', 20)->default('console'); // console | admin
            $table->string('name', 80);
            $table->string('route', 120)->nullable();        // 라우트명 (접근 매칭 키)
            $table->string('url', 200)->nullable();          // 라우트 없을 때 직접 URL
            $table->string('icon', 60)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('meta_title', 150)->nullable();
            $table->string('meta_description', 255)->nullable();
            $table->timestamps();

            $table->index(['area', 'parent_id', 'sort_order']);
            $table->index('route');
        });

        // 권한 매트릭스 — 메뉴 × 주체(grade|role) × 4액션
        Schema::create('menu_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->constrained('menus')->cascadeOnDelete();
            $table->string('subject_type', 10);   // grade | role
            $table->unsignedBigInteger('subject_id');
            $table->boolean('can_access')->default(false);
            $table->boolean('can_create')->default(false);
            $table->boolean('can_update')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->timestamps();

            $table->unique(['menu_id', 'subject_type', 'subject_id'], 'uniq_menu_subject');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_permissions');
        Schema::dropIfExists('menus');
        Schema::dropIfExists('operator_roles');
        Schema::dropIfExists('member_grades');
    }
};
