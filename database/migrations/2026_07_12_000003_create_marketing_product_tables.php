<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 마케팅 상품 시스템 — self_marketing 상품 폼 빌더/주문 구조를 Laravel로 이식.
 * 유형(product_types)·세부유형(product_sub_types)·상품(marketing_products)
 * + 동적 폼(product_field_groups·product_fields) + 주문(marketing_orders).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 상품 대분류 유형 (DB 관리)
        Schema::create('product_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 80);
            $table->string('description')->nullable();
            $table->boolean('has_fulfillment')->default(false);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 세부 유형 (유형별)
        Schema::create('product_sub_types', function (Blueprint $table) {
            $table->id();
            $table->string('product_type', 40);
            $table->string('code', 40);
            $table->string('name', 80);
            $table->string('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['product_type', 'code']);
        });

        // 상품
        Schema::create('marketing_products', function (Blueprint $table) {
            $table->id();
            $table->string('product_type', 40);
            $table->string('sub_type_code', 40)->nullable();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->decimal('base_cost', 12, 2)->default(0);       // 원가
            $table->decimal('min_price', 12, 2)->default(0);       // 최소 판매가(단가)
            $table->integer('min_quantity')->default(10);
            $table->integer('max_quantity')->default(10000);
            $table->integer('min_days')->default(1);
            $table->string('quantity_mode', 10)->default('daily'); // daily | total
            $table->integer('min_daily_quantity')->default(0);
            $table->string('field_render_mode', 10)->default('inline'); // inline | step
            $table->decimal('default_fulfillment', 6, 2)->default(100);
            $table->integer('daily_cutoff_hour')->nullable();       // 0~23, null=마감없음
            $table->boolean('process_weekends')->default(true);
            $table->boolean('process_holidays')->default(true);
            $table->integer('processing_lag_days')->default(0);
            $table->string('order_token', 40)->unique();            // 주문 페이지 URL 토큰
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
            $table->index(['product_type', 'is_active']);
        });

        // 동적 필드 그룹
        Schema::create('product_field_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('marketing_products')->cascadeOnDelete();
            $table->string('code', 60);                             // basic | schedule_quantity | custom_xxx
            $table->string('name', 80);
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'code']);
        });

        // 동적 필드
        Schema::create('product_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('marketing_products')->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('product_field_groups')->nullOnDelete();
            $table->string('field_key', 60);
            $table->string('field_type', 30);                       // TEXT · TEXTAREA · URL · NUMBER · SELECT · MULTI_SELECT · TOGGLE · DATE · FILE · IMAGE · ADDRESS · TAGS
            $table->string('label', 120);
            $table->string('placeholder')->nullable();
            $table->string('help_text')->nullable();
            $table->boolean('is_required')->default(true);
            $table->text('default_value')->nullable();
            $table->text('options_json')->nullable();               // SELECT/MULTI_SELECT 옵션
            $table->text('validation_json')->nullable();
            $table->text('condition_json')->nullable();             // 조건부 노출 규칙
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['product_id', 'field_key']);
        });

        // 주문
        Schema::create('marketing_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 30)->unique();
            $table->foreignId('product_id')->constrained('marketing_products')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('quantity')->default(0);
            $table->integer('days')->nullable();
            $table->json('field_values')->nullable();               // 동적 필드 입력값
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total_price', 12, 2)->default(0);
            $table->string('status', 20)->default('pending');       // pending | paid | processing | done | canceled
            $table->string('orderer_name', 80)->nullable();
            $table->string('orderer_contact', 80)->nullable();
            $table->timestamps();
            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_orders');
        Schema::dropIfExists('product_fields');
        Schema::dropIfExists('product_field_groups');
        Schema::dropIfExists('marketing_products');
        Schema::dropIfExists('product_sub_types');
        Schema::dropIfExists('product_types');
    }
};
