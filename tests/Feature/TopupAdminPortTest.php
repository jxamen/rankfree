<?php

namespace Tests\Feature;

use App\Domain\Order\PrepaidWallet;
use App\Models\Topup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TopupAdminPortTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $u = User::create(['name' => '홍길동', 'email' => 'member@test.invalid', 'password' => 'fixture-only', 'role' => 'user', 'phone' => '01012345678']);
        $a = User::create(['name' => '관리자', 'email' => 'admin@test.invalid', 'password' => 'fixture-only', 'role' => 'super']);
        $t = Topup::create(['user_id' => $u->id, 'amount' => 10000, 'code' => '1234', 'depositor' => '홍길동', 'status' => 'pending']);

        return [$u, $a, $t];
    }

    public function test_revoke_reconfirm_has_distinct_ledger_and_rejects_repeat(): void
    {
        [$u,$a,$t] = $this->fixture();
        $this->actingAs($a);
        $this->post('/admin/topups/'.$t->id.'/review', ['status' => 'confirmed'])->assertSessionHasNoErrors();
        $this->post('/admin/topups/'.$t->id.'/manage', ['action' => 'revoke'])->assertSessionHasNoErrors();
        $this->post('/admin/topups/'.$t->id.'/manage', ['action' => 'revoke'])->assertSessionHasErrors('topup');
        $this->assertSame(0, (int) $u->fresh()->prepaid_balance);
        $this->post('/admin/topups/'.$t->id.'/review', ['status' => 'confirmed'])->assertSessionHasNoErrors();
        $this->post('/admin/topups/'.$t->id.'/review', ['status' => 'confirmed'])->assertSessionHasErrors('topup');
        $this->assertSame(10000, (int) $u->fresh()->prepaid_balance);
        $this->assertDatabaseCount('wallet_entries', 3);
        $this->assertSame(3, DB::table('topup_audits')->where('action', '!=', 'confirmed_sms')->count());
    }

    public function test_insufficient_revocation_and_metadata_validation_leave_money_unchanged(): void
    {
        [$u,$a,$t] = $this->fixture();
        $this->actingAs($a);
        $this->post('/admin/topups/'.$t->id.'/review', ['status' => 'confirmed']);
        app(PrepaidWallet::class)->change($u->id, -10000, 'order:test', 'test');
        $this->post('/admin/topups/'.$t->id.'/manage', ['action' => 'revoke'])->assertSessionHasErrors();
        $this->assertSame('confirmed', $t->fresh()->status);
        $this->assertDatabaseCount('wallet_entries', 2);
        $this->post('/admin/topups/'.$t->id.'/manage', ['action' => 'edit', 'amount' => 20000, 'depositor' => '수정'])->assertSessionHasErrors();
        $this->assertSame(10000, (int) $t->fresh()->amount);
    }

    public function test_metadata_filter_cancel_restore_delete_and_permissions(): void
    {
        [$u,$a,$t] = $this->fixture();
        $this->actingAs($u)->post('/admin/topups/'.$t->id.'/manage', ['action' => 'vat', 'value' => true])->assertForbidden();
        $this->actingAs($a);
        foreach ([['action' => 'edit', 'amount' => 30000, 'depositor' => '수정', 'company' => '제이커브'], ['action' => 'bank', 'value' => 1], ['action' => 'vat', 'value' => 1], ['action' => 'cancel'], ['action' => 'restore']] as $d) {
            $this->post('/admin/topups/'.$t->id.'/manage', $d)->assertSessionHasNoErrors();
        }
        $this->get('/admin/topups?q='.rawurlencode('제이커브'))->assertOk()->assertSee('30,000')->assertSee('수정');
        $this->get('/admin/topups?q=absent')->assertOk()->assertDontSee('제이커브');
        $this->post('/admin/topups/'.$t->id.'/manage', ['action' => 'cancel']);
        $this->post('/admin/topups/'.$t->id.'/manage', ['action' => 'delete'])->assertSessionHasNoErrors();
        $this->assertNotNull($t->fresh()->deleted_at);
        $this->assertDatabaseCount('wallet_entries', 0);
        $this->assertDatabaseCount('topup_audits', 7);
        $this->post('/admin/topups/'.$t->id.'/review', ['status' => 'confirmed'])->assertSessionHasErrors('topup');
    }

    public function test_invoice_sms_claim_and_audit_render(): void
    {
        [$u,$a,$t] = $this->fixture();
        $this->actingAs($a);
        Http::fake(['apis.aligo.in/*' => Http::response(['result_code' => 1])]);
        config(['services.aligo.user_id' => 'fixture', 'services.aligo.key' => 'fixture', 'services.aligo.sender' => '021234567']);
        $this->post('/admin/topups/'.$t->id.'/review', ['status' => 'confirmed']);
        $this->post('/admin/topups/'.$t->id.'/manage', ['action' => 'invoice', 'value' => 1])->assertSessionHasNoErrors();
        $this->post('/admin/topups/'.$t->id.'/manage', ['action' => 'invoice', 'value' => 0])->assertSessionHasNoErrors();
        $this->post('/admin/topups/'.$t->id.'/manage', ['action' => 'vat_sms'])->assertSessionHasNoErrors();
        $this->post('/admin/topups/'.$t->id.'/manage', ['action' => 'vat_sms'])->assertStatus(409);
        // Laravel runs committed callbacks under its test transaction manager.
        $this->assertDatabaseHas('topup_audits', ['action' => 'vat_sms', 'after_state' => '{"sms":"sent"}']);
        $page = $this->get('/admin/topups')->assertOk()->assertSee('부가세 문자 요청');
        if ($dir = getenv('RF_SCREENSHOT_DIR')) {
            file_put_contents($dir.'/rankfree-admin.html',$page->getContent());
        }
    }
}
