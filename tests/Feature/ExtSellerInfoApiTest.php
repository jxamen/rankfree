<?php

namespace Tests\Feature;

use App\Models\ExtToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExtSellerInfoApiTest extends TestCase
{
    use RefreshDatabase;

    private function authed(): array
    {
        $user = User::create([
            'name' => 'Tester',
            'email' => 'seller-info@rankfree.kr',
            'password' => 'secret1234',
        ]);
        [, $plain] = ExtToken::issue($user);

        return [$user, ['Authorization' => 'Bearer '.$plain]];
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'store_id' => 'bennyong',
            'channel_uid' => '2sWDyHZmje7LqpCivmXk3',
            'channel_id' => '6MOF9amfKExgnFMfU2l6c0',
            'biz_name' => 'Bennyong Store',
            'representative' => 'Hong Gil Dong',
            'customer_phone' => '02-3436-8693',
            'biz_reg_no' => '5460701862',
            'mail_order_no' => '2021-Seoul-0185',
            'email' => 'bennyong_store@naver.com',
            'address' => '15 Example-ro, Seoul',
            'raw' => ['biz_name' => 'Bennyong Store', 'representative' => 'Hong Gil Dong'],
            'seller_info_url' => 'https://shopping.naver.com/popup/seller-info/2sWDyHZmje7LqpCivmXk3/profile',
        ], $override);
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/ext/seller-infos', $this->payload())->assertUnauthorized();
    }

    public function test_stores_seller_info_per_channel(): void
    {
        [$user, $headers] = $this->authed();

        $this->postJson('/api/ext/seller-infos', $this->payload(), $headers)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.biz_name', 'Bennyong Store')
            ->assertJsonPath('data.biz_reg_no', '5460701862')
            ->assertJsonPath('data.email', 'bennyong_store@naver.com');

        $this->assertDatabaseHas('shop_seller_infos', [
            'user_id' => $user->id,
            'channel_uid' => '2sWDyHZmje7LqpCivmXk3',
            'biz_name' => 'Bennyong Store',
            'representative' => 'Hong Gil Dong',
            'customer_phone' => '02-3436-8693',
            'mail_order_no' => '2021-Seoul-0185',
        ]);
    }

    public function test_upserts_same_channel(): void
    {
        [, $headers] = $this->authed();

        $this->postJson('/api/ext/seller-infos', $this->payload(), $headers)->assertOk();
        $this->postJson('/api/ext/seller-infos', $this->payload(['biz_name' => 'Bennyong Store 2']), $headers)->assertOk();

        $this->assertDatabaseCount('shop_seller_infos', 1);
        $this->assertDatabaseHas('shop_seller_infos', [
            'channel_uid' => '2sWDyHZmje7LqpCivmXk3',
            'biz_name' => 'Bennyong Store 2',
        ]);
    }

    public function test_channel_uid_required(): void
    {
        [, $headers] = $this->authed();

        $this->postJson('/api/ext/seller-infos', $this->payload(['channel_uid' => '']), $headers)
            ->assertStatus(422);
    }
}
