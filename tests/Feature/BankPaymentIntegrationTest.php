<?php

namespace Tests\Feature;

use App\Models\BankTransaction;
use App\Models\Challan;
use App\Models\Member;
use App\Models\SportsComplex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BankPaymentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.bop_mpgs.enabled' => true,
            'services.bop_mpgs.environment' => 'test',
            'services.bop_mpgs.base_url' => 'https://test-gateway.mastercard.com',
            'services.bop_mpgs.api_version' => 73,
            'services.bop_mpgs.merchant_id' => 'TESTMERCHANT',
            'services.bop_mpgs.api_password' => 'test-secret',
            'services.bop_mpgs.merchant_name' => 'LDA Sports Complex',
        ]);
    }

    public function test_member_initiates_documented_hosted_checkout_without_exposing_credentials(): void
    {
        [$user, $challan] = $this->actors();
        Http::fake(['*' => Http::response('result=SUCCESS&session.id=SESSION123&successIndicator=SAFE123', 200, ['Content-Type' => 'text/plain'])]);

        $response = $this->actingAs($user)->postJson('/api/portal/challans/'.$challan->id.'/bank/initiate', [
            'initiation_key' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('status', 'session_created');

        $this->assertStringContainsString('/bank/checkout/', $response->json('checkout_url'));
        $this->assertDatabaseHas('bank_transactions', ['challan_id' => $challan->id, 'amount' => 10000, 'currency' => 'PKR']);
        $log = \App\Models\BankApiLog::firstOrFail();
        $this->assertSame('[REDACTED]', $log->request_payload['apiPassword']);
        Http::assertSent(fn ($request) => $request['apiOperation'] === 'INITIATE_CHECKOUT'
            && $request['apiUsername'] === 'merchant.TESTMERCHANT'
            && $request['order.amount'] === '10000.00'
            && $request['order.currency'] === 'PKR');
    }

    public function test_successful_retrieve_order_is_the_only_path_that_records_online_payment(): void
    {
        [$user, $challan] = $this->actors();
        $transaction = $this->transaction($challan);
        $body = 'result=SUCCESS&order.id='.rawurlencode($transaction->order_id).'&order.amount=10000.00&order.currency=PKR&order.status=CAPTURED&transaction%5B0%5D.id=TXN-77';
        Http::fake(['*' => Http::response($body, 200, ['Content-Type' => 'text/plain'])]);

        $this->actingAs($user)->postJson('/api/portal/bank-transactions/'.$transaction->public_id.'/verify')
            ->assertOk()->assertJsonPath('status', 'verified');

        $this->assertDatabaseHas('payments', ['challan_id' => $challan->id, 'amount' => 10000, 'method' => 'online', 'status' => 'paid']);
        $this->assertDatabaseHas('challans', ['id' => $challan->id, 'status' => 'paid']);
        $this->assertNotNull($transaction->fresh()->verified_at);

        $this->actingAs($user)->postJson('/api/portal/bank-transactions/'.$transaction->public_id.'/verify')->assertOk();
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_amount_mismatch_never_marks_challan_paid(): void
    {
        [$user, $challan] = $this->actors();
        $transaction = $this->transaction($challan);
        Http::fake(['*' => Http::response('result=SUCCESS&order.id='.$transaction->order_id.'&order.amount=1.00&order.currency=PKR&order.status=CAPTURED', 200)]);

        $this->actingAs($user)->postJson('/api/portal/bank-transactions/'.$transaction->public_id.'/verify')
            ->assertOk()->assertJsonPath('status', 'verification_required');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('challans', ['id' => $challan->id, 'status' => 'generated']);
    }

    public function test_pending_bank_transaction_blocks_manual_paid_status(): void
    {
        [, $challan] = $this->actors();
        $this->transaction($challan);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->putJson('/api/challans/'.$challan->id, ['status' => 'paid'])->assertConflict();
        $this->assertDatabaseHas('challans', ['id' => $challan->id, 'status' => 'generated']);
    }

    private function actors(): array
    {
        $complex = SportsComplex::create(['name' => 'Gulberg Sports Complex', 'code' => 'GB']);
        $member = Member::create(['sports_complex_id' => $complex->id, 'customer_no' => '2400000001', 'full_name' => 'Test Applicant', 'cnic' => '3520212345671', 'status' => 'active']);
        $user = User::factory()->create(['role' => 'member', 'member_id' => $member->id, 'must_change_password' => false]);
        $challan = Challan::create([
            'challan_no' => 'CH-2026-GB-000001', 'sports_complex_id' => $complex->id, 'member_id' => $member->id,
            'purpose' => 'Membership fee', 'amount' => 10000, 'issued_on' => today(), 'due_on' => today()->addWeek(),
            'status' => 'generated', 'sap_status' => 'posted', 'sap_document_no' => 'SAP-1',
        ]);

        return [$user, $challan];
    }

    private function transaction(Challan $challan): BankTransaction
    {
        return BankTransaction::create([
            'public_id' => (string) Str::uuid(), 'challan_id' => $challan->id, 'member_id' => $challan->member_id,
            'environment' => 'test', 'order_id' => 'LDA-261013-'.$challan->id, 'session_id' => 'SESSION123',
            'success_indicator' => 'SAFE123', 'status' => 'session_created', 'amount' => $challan->amount,
            'currency' => 'PKR', 'initiation_key' => (string) Str::uuid(), 'session_created_at' => now(),
        ]);
    }
}
