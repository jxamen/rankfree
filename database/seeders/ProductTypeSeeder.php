<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * @deprecated self_marketing 원본 구조 기준으로 통합됨 → MarketingProductSeeder 로 위임.
 * 유형·세부유형·상품·필드는 모두 MarketingProductSeeder(JSON 기반)가 단일 소스로 관리한다.
 */
class ProductTypeSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(MarketingProductSeeder::class);
    }
}
