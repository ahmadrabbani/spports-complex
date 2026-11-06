<?php

namespace Tests\Feature;

use App\Models\Challan;
use App\Models\FeeType;
use App\Models\Member;
use App\Models\MembershipCategory;
use App\Models\PaymentVerification;
use App\Models\SportsComplex;
use App\Models\User;
use App\Services\MockBankService;
use Database\Seeders\ReconciliationAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MockBankTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagram_flow_from_registration_to_verified_mock_receipt(): void
    {
        config(['services.payment_mode' => 'mock']);
        Http::preventStrayRequests();
        $this->seed(ReconciliationAccountSeeder::class);
        $complex = SportsComplex::create(['name' => 'Synthetic Flow Complex', 'code' => 'FLOW', 'cost_center' => '21070001', 'profit_center' => '21070001']);
        $admin = User::factory()->create(['role' => 'admin']);
        $category = MembershipCategory::where('name', 'General Public Family Membership')->firstOrFail();
        $fee = FeeType::create(['code' => 'FLOW', 'name' => 'Flow Test Fee', 'gl_account' => '0000610310', 'active' => true]);
        $memberId = $this->actingAs($admin)->postJson('/api/members', [
            'sports_complex_id' => $complex->id, 'membership_category_id' => $category->id,
            'customer_no' => 'SYNTHETIC-FLOW', 'membership_no' => 'QA-FLOW', 'full_name' => 'Synthetic Flow Applicant',
            'cnic' => '3520200000099', 'phone' => '03000000099', 'address' => 'Synthetic QA address', 'status' => 'active',
        ])->assertCreated()->json('id');
        $charges = [['fee_type_id' => $fee->id, 'description' => 'Synthetic flow test fee', 'amount' => 100]];
        $this->postJson("/api/members/$memberId/challan-preview", ['charges' => $charges])->assertOk()->assertJsonPath('total', '100.00');
        $payload = ['member_id' => $memberId, 'charges' => $charges, 'due_on' => today()->addWeek()->toDateString(), 'idempotency_key' => (string) Str::uuid()];
        $id = $this->postJson('/api/challans', $payload)->assertCreated()->assertJsonPath('environment', 'mock')->assertJsonPath('amount', '100.00')->json('id');
        $this->postJson('/api/challans', $payload)->assertCreated()->assertJsonPath('id', $id);
        $challan = Challan::findOrFail($id);
        $this->assertNotEmpty($challan->sap_document_no);
        $this->assertSame('0021070001', $challan->sap_payload['center']);
        $this->assertSame('021070001', $challan->sap_payload['pcenter']);
        $payment = ['scenario' => 'delayed', 'idempotency_key' => (string) Str::uuid()];
        $tx = $this->postJson("/api/challans/$id/mock-payment", $payment)->assertCreated()->assertJsonPath('status', 'pending')->json('id');
        $this->get("/api/mock-transactions/$tx/receipt")->assertNotFound();
        $this->assertSame('generated', $challan->fresh()->status);
        $this->postJson("/api/mock-transactions/$tx/confirm")->assertOk()->assertJsonPath('status', 'verified');
        $this->postJson("/api/mock-transactions/$tx/confirm")->assertOk();
        $this->postJson("/api/challans/$id/mock-payment", $payment)->assertOk()->assertJsonPath('id', $tx);
        $this->assertSame('paid', $challan->fresh()->status);
        $this->assertDatabaseHas('member_charges', ['member_id' => $memberId, 'source_type' => 'mock_generation', 'status' => 'paid']);
        $this->get("/api/mock-transactions/$tx/receipt")->assertOk()->assertSee('TEST MODE')->assertSee('Synthetic Flow Applicant')->assertSee('100.00');
        $this->assertDatabaseCount('challans', 1);
        $this->assertDatabaseCount('bank_transactions', 1);
        $this->assertDatabaseCount('payment_verifications', 2);
        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }

    private function actors(): array
    {
        config(['services.payment_mode' => 'mock']);
        $s = SportsComplex::create(['name' => 'Test '.Str::uuid(), 'code' => substr((string) Str::uuid(), 0, 8)]);
        $m = Member::create(['sports_complex_id' => $s->id, 'customer_no' => (string) Str::uuid(), 'full_name' => 'Synthetic Applicant', 'status' => 'active']);
        $c = Challan::create(['sports_complex_id' => $s->id, 'member_id' => $m->id, 'challan_no' => 'MOCK-'.Str::uuid(), 'purpose' => 'Test', 'amount' => 100, 'issued_on' => today(), 'due_on' => today()->addWeek(), 'status' => 'generated', 'environment' => 'mock']);

        return [User::factory()->create(['role' => 'admin']), $c];
    }

    public function test_success_is_idempotent_and_does_not_create_real_payment(): void
    {
        [$u,$c] = $this->actors();
        $key = (string) Str::uuid();
        $this->actingAs($u)->postJson("/api/challans/$c->id/mock-payment", ['scenario' => 'success', 'idempotency_key' => $key])->assertCreated()->assertJsonPath('status', 'verified');
        $this->postJson("/api/challans/$c->id/mock-payment", ['scenario' => 'success', 'idempotency_key' => $key])->assertOk();
        $this->assertDatabaseCount('bank_transactions', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('paid', $c->fresh()->status);
    }

    public function test_all_failure_scenarios_leave_challan_unpaid(): void
    {
        foreach (['failed', 'pending', 'cancelled', 'timeout', 'amount_mismatch', 'invalid_transaction', 'delayed'] as $scenario) {
            [$u,$c] = $this->actors();
            $this->actingAs($u)->postJson("/api/challans/$c->id/mock-payment", ['scenario' => $scenario, 'idempotency_key' => (string) Str::uuid()])->assertCreated();
            $this->assertSame('generated', $c->fresh()->status);
            $c->update(['challan_no' => 'DONE-'.$c->id]);
        }
    }

    public function test_live_challan_and_nonadmin_are_rejected(): void
    {
        [$u,$c] = $this->actors();
        $c->update(['environment' => 'live']);
        $this->actingAs($u)->postJson("/api/challans/$c->id/mock-payment", ['scenario' => 'success', 'idempotency_key' => (string) Str::uuid()])->assertConflict();
        $u->update(['role' => 'operator']);
        $this->postJson("/api/challans/$c->id/mock-payment", [])->assertForbidden();
    }

    public function test_delayed_confirmation_is_idempotent(): void
    {
        [$u,$c] = $this->actors();
        $tx = app(MockBankService::class)->run($c, $u, 'delayed', (string) Str::uuid());
        $this->actingAs($u)->postJson("/api/mock-transactions/$tx->id/confirm")->assertOk()->assertJsonPath('status', 'verified');
        $this->postJson("/api/mock-transactions/$tx->id/confirm")->assertOk();
        $this->assertDatabaseCount('payment_verifications', 2);
    }

    public function test_unknown_sap_response_is_encrypted_and_never_paid(): void
    {
        [$u,$c] = $this->actors();
        $c->update(['environment' => 'live', 'sap_document_no' => '170046514510002026']);
        Http::fake(['*' => Http::response('  ', 200)]);
        $this->actingAs($u)->postJson("/api/challans/$c->id/verify-payment")->assertOk()->assertJsonPath('result', 'verification_unknown');
        $this->assertSame('generated', $c->fresh()->status);
        $this->assertSame(['raw' => '  '], PaymentVerification::first()->response_payload);
    }
}
