<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 쿠폰(26) — 관리자 발행(정액/정률) + 회원 다운로드 + 마케팅 상품 주문 할인.
 *  - coupons: 쿠폰 정의(할인·조건·기간·발급 방식)
 *  - user_coupons: 회원별 발급분(1인 1매, 만료·사용 이력)
 *  - marketing_orders: 사용 쿠폰·할인액 기록
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();                    // 관리 코드(자동 발급, CS 추적용)
            $table->string('name', 100);
            $table->string('discount_type', 10)->default('amount');  // amount(정액) | percent(정률)
            $table->decimal('discount_value', 12, 2)->default(0);    // 원 또는 %
            $table->decimal('max_discount', 12, 2)->nullable();      // 정률 최대 할인액(원)
            $table->decimal('min_order_amount', 12, 2)->default(0);  // 최소 주문 금액
            $table->date('starts_at')->nullable();                   // 사용 가능 시작일
            $table->date('ends_at')->nullable();                     // 사용 가능 종료일
            $table->unsignedInteger('valid_days')->nullable();       // 발급일로부터 N일 만료(종료일과 중 이른 쪽)
            $table->boolean('is_downloadable')->default(false);      // 회원이 쿠폰함에서 직접 다운로드 가능
            $table->unsignedInteger('max_issuance')->nullable();     // 총 발급 수량 제한(직접+다운로드, null=무제한)
            $table->json('product_ids')->nullable();                 // 적용 상품 제한(null=전체)
            $table->string('memo')->nullable();                      // 관리 메모
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('user_coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('source', 10)->default('admin');          // admin(관리자 발행) | download(직접 다운로드)
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('expires_at')->nullable();              // 발급 시 확정(쿠폰 종료일·valid_days 중 이른 쪽)
            $table->dateTime('used_at')->nullable();
            $table->foreignId('order_id')->nullable()->constrained('marketing_orders')->nullOnDelete();
            $table->timestamps();
            $table->unique(['coupon_id', 'user_id']);                // 1인 1매
            $table->index(['user_id', 'used_at']);
        });

        Schema::table('marketing_orders', function (Blueprint $table) {
            $table->foreignId('user_coupon_id')->nullable()->constrained('user_coupons')->nullOnDelete();
            $table->decimal('discount_amount', 12, 2)->default(0);   // total_price 는 할인 반영 최종가
        });
    }

    public function down(): void
    {
        Schema::table('marketing_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_coupon_id');
            $table->dropColumn('discount_amount');
        });
        Schema::dropIfExists('user_coupons');
        Schema::dropIfExists('coupons');
    }
};
