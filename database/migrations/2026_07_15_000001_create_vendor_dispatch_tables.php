<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 외부 발주 업체 — 채널(API 호출 | 구글시트 행 추가)별 접속 정보
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('channel', 10)->default('api');        // api | gsheet
            $table->string('api_url', 500)->nullable();
            $table->string('api_method', 10)->default('POST');    // POST | GET | PUT
            $table->text('api_headers')->nullable();               // JSON {헤더명: 값} — 인증키 등
            $table->string('api_format', 10)->default('json');    // json | form
            $table->string('gsheet_id', 120)->nullable();          // 스프레드시트 ID
            $table->string('gsheet_tab', 120)->nullable();         // 시트(탭) 이름
            $table->string('memo', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 상품 × 업체 배분 — 비율(%)/고정 수량 + 업체별 전송 페이로드 매핑
        Schema::create('product_vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('marketing_products')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('alloc_type', 10)->default('ratio');   // ratio(%) | fixed(고정 수량)
            $table->integer('alloc_value')->default(0);
            $table->text('field_map')->nullable();                 // JSON [{key, src, value}] — 보낼키 ← 값 소스
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 주문 → 업체 전송 기록 (승인 시 생성·전송, 실패 재전송 가능)
        Schema::create('order_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('marketing_orders')->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('vendor_name', 120);                    // 업체 삭제 후에도 이력 보존
            $table->string('channel', 10);
            $table->integer('quantity')->default(0);
            $table->text('payload')->nullable();                    // 전송한 페이로드 JSON
            $table->string('status', 10)->default('pending');      // pending | sent | failed
            $table->text('response')->nullable();                   // 응답/오류 메시지
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_dispatches');
        Schema::dropIfExists('product_vendors');
        Schema::dropIfExists('vendors');
    }
};
