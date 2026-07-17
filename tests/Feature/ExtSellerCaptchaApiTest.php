<?php

namespace Tests\Feature;

use App\Models\ExtToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExtSellerCaptchaApiTest extends TestCase
{
    use RefreshDatabase;

    private function authed(): array
    {
        $user = User::create([
            'name' => 'Tester',
            'email' => 'seller-captcha@rankfree.kr',
            'password' => 'secret1234',
        ]);
        [, $plain] = ExtToken::issue($user);

        return [$user, ['Authorization' => 'Bearer '.$plain]];
    }

    public function test_store_saves_captcha_image_and_metadata(): void
    {
        Storage::fake('local');
        [$user, $headers] = $this->authed();

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        $response = $this->postJson('/api/ext/seller-captchas', [
            'store_id' => 'j-square',
            'channel_uid' => '2sWDyHZmje7LqpCivmXk3',
            'channel_id' => '6MOF9amfKExgnFMfU2l6c0',
            'captcha_key' => 'BXWkU2sLos7vJx',
            'seller_info_type' => 'profile',
            'question' => 'How many items are on the receipt?',
            'image_data' => 'data:image/png;base64,'.base64_encode($png),
            'seller_info_url' => 'https://shopping.naver.com/popup/seller-info/2sWDyHZmje7LqpCivmXk3/profile',
            'prev_url' => 'https://smartstore.naver.com/j-square/profile',
        ], $headers);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.store_id', 'j-square')
            ->assertJsonPath('data.channel_uid', '2sWDyHZmje7LqpCivmXk3')
            ->assertJsonPath('data.captcha_key', 'BXWkU2sLos7vJx')
            ->assertJsonPath('data.image_url', route('admin.shop-products.seller-captchas.image', 1));

        Storage::disk('local')->assertExists($response->json('data.path'));
        $this->assertDatabaseHas('shop_seller_captchas', [
            'user_id' => $user->id,
            'store_id' => 'j-square',
            'channel_uid' => '2sWDyHZmje7LqpCivmXk3',
            'captcha_key' => 'BXWkU2sLos7vJx',
            'image_mime' => 'image/png',
        ]);
    }

    public function test_store_requires_auth(): void
    {
        $this->postJson('/api/ext/seller-captchas', [])->assertStatus(401);
    }

    public function test_store_rejects_non_image_payload(): void
    {
        [, $headers] = $this->authed();

        $this->postJson('/api/ext/seller-captchas', [
            'channel_uid' => '2sWDyHZmje7LqpCivmXk3',
            'image_data' => base64_encode('not an image'),
        ], $headers)->assertStatus(422);
    }
}
