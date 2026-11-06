<?php

namespace Tests\Feature;

use App\Models\Challan;
use App\Models\ChallanAdjustment;
use App\Models\FeeType;
use App\Models\Member;
use App\Models\MemberCharge;
use App\Models\MembershipAccountAudit;
use App\Models\MembershipCategory;
use App\Models\MembershipFreeze;
use App\Models\MembershipPlan;
use App\Models\ReconciliationAccount;
use App\Models\SportsComplex;
use App\Models\User;
use Database\Seeders\ReconciliationAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MembershipReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.payment_mode' => 'live', 'services.sap.challan_url' => 'https://sap.test/SAPVERIFICATION/Challan', 'services.sap.verification_url' => 'https://sap.test/SAPVERIFICATION/PaymentVerification']);
        Http::preventStrayRequests();
    }

    public static function accounts(): array
    {
        $out = [];
        foreach (ReconciliationAccountSeeder::ACCOUNTS as $code => $name) {
            $out[$code] = [(string) $code, $name];
        }

        return $out;
    }

    #[DataProvider('accounts')]
    public function test_official_account_seed_and_category_assignment(string $code, string $name): void
    {
        $account = ReconciliationAccount::where('code', $code)->firstOrFail();
        $this->assertSame($name, $account->long_description);
        $this->assertDatabaseCount('reconciliation_accounts', 11);
        $this->seed(ReconciliationAccountSeeder::class);
        $this->assertDatabaseCount('reconciliation_accounts', 11);
        if ($code === '260111') {
            $this->assertTrue($account->categories()->first()->is_freeze);

            return;
        }
        [$admin,$member,$fee] = $this->actors();
        $category = $account->categories()->first();
        $this->actingAs($admin)->postJson('/api/members', ['sports_complex_id' => $member->sports_complex_id, 'customer_no' => 'NEW-'.$code, 'full_name' => 'Synthetic member', 'status' => 'active', 'membership_category_id' => $category->id])->assertCreated()->assertJsonPath('reconciliation_account', $code);
    }

    public function test_all_regular_categories_generate_the_correct_reacon_and_snapshots(): void
    {
        Http::fake(['*' => Http::response(['challan_no' => '1700000100'])]);
        [$admin,$base,$fee] = $this->actors();
        // A legacy fee-type override must not override the member category.
        $fee->update(['reconciliation_account' => '260108']);
        foreach (MembershipCategory::where('is_freeze', false)->with('account')->get() as $category) {
            $m = Member::create(['sports_complex_id' => $base->sports_complex_id, 'customer_no' => 'CAT-'.$category->id, 'full_name' => 'Synthetic Customer', 'cnic' => '3520200000001', 'phone' => '03000000001', 'address' => 'Test address', 'membership_category_id' => $category->id]);
            $payload = $this->payload($m, $fee);
            $this->actingAs($admin)->postJson('/api/challans', $payload)->assertCreated()->assertJsonPath('reconciliation_account', $category->account->code)->assertJsonPath('membership_category_name', $category->name)->assertJsonPath('member_status_snapshot', 'active');
            $c = $m->challans()->first();
            $this->assertSame($category->account->code, $c->sap_payload['reacon']);
            $this->assertSame($category->account->code, $c->items()->first()->reconciliation_account);
        }
        Http::assertSentCount(10);
    }

    public function test_backfill_is_dry_run_by_default_idempotent_and_preserves_financial_records(): void
    {
        [$admin,$m,$fee] = $this->actors();
        $unknown = Member::create(['sports_complex_id' => $m->sports_complex_id, 'customer_no' => 'UNKNOWN', 'full_name' => 'Unknown', 'reconciliation_account' => '260000']);
        $frozen = Member::create(['sports_complex_id' => $m->sports_complex_id, 'customer_no' => 'LEGACY-FROZEN', 'full_name' => 'Legacy Frozen', 'reconciliation_account' => '260111']);
        $paid = $this->draft($m, $fee);
        $paid->update(['status' => 'paid', 'sap_status' => 'posted', 'sap_document_no' => '1700000001']);
        $before = $paid->fresh()->getAttributes();
        $this->artisan('members:backfill-reconciliation')->assertSuccessful();
        $this->assertNull($m->fresh()->membership_category_id);
        $this->artisan('members:backfill-reconciliation --apply')->assertSuccessful();
        $this->assertSame('260108', $m->fresh()->category->account->code);
        $this->assertNull($unknown->fresh()->membership_category_id);
        $this->assertNull($frozen->fresh()->membership_category_id);
        $count = MembershipAccountAudit::count();
        $this->artisan('members:backfill-reconciliation --apply')->assertSuccessful();
        $this->assertSame($count, MembershipAccountAudit::count());
        $this->assertSame($before, $paid->fresh()->getAttributes());
    }

    public function test_freeze_and_unfreeze_preserve_category_and_history_without_changing_challans(): void
    {
        [$admin,$m,$fee] = $this->actors(true);
        $plan = MembershipPlan::create(['name' => 'Approved family', 'duration_months' => 12]);
        $membership = $m->memberships()->create(['membership_plan_id' => $plan->id, 'starts_on' => today()->subMonth(), 'expires_on' => today()->addMonth(), 'status' => 'active']);
        $c = $this->draft($m, $fee);
        $c->update(['status' => 'paid', 'sap_status' => 'posted', 'sap_document_no' => '1700000001']);
        $before = $c->fresh()->getAttributes();
        $category = $m->membership_category_id;
        $this->actingAs($admin)->postJson("/api/members/$m->id/freeze", ['starts_on' => today()->toDateString(), 'reason' => 'Travel'])->assertOk()->assertJsonPath('status', 'frozen')->assertJsonPath('reconciliation_account', '260111')->assertJsonPath('original_reconciliation_account', '260108');
        $this->assertSame('frozen', $membership->fresh()->status);
        $this->actingAs($admin)->postJson("/api/members/$m->id/freeze", ['starts_on' => today()->toDateString(), 'reason' => 'Duplicate'])->assertConflict();
        $this->actingAs($admin)->postJson("/api/members/$m->id/unfreeze", ['reason' => 'Returned'])->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('reconciliation_account', '260108')->assertJsonPath('membership_category_id', $category);
        $this->assertSame('active', $membership->fresh()->status);
        $this->assertNotNull(MembershipFreeze::first()->unfrozen_at);
        $this->assertSame($before, $c->fresh()->getAttributes());
        $this->assertDatabaseHas('membership_account_audits', ['member_id' => $m->id, 'old_account' => '260108', 'new_account' => '260111', 'actor_id' => $admin->id]);
    }

    public function test_expired_membership_is_not_reactivated_by_unfreeze(): void
    {
        [$admin,$m] = $this->actors(true);
        $m->memberships()->create(['starts_on' => today()->subYear(), 'expires_on' => today()->subDay(), 'status' => 'active']);
        $this->freeze($admin, $m);
        $this->actingAs($admin)->postJson("/api/members/$m->id/unfreeze", ['reason' => 'Review'])->assertOk()->assertJsonPath('status', 'inactive');
        $this->assertSame('expired', $m->memberships()->first()->status);
    }

    public function test_frozen_standard_fees_are_blocked_and_approved_freeze_fee_is_one_use(): void
    {
        Http::fake(['*' => Http::response(['challan_no' => '1700000101'])]);
        [$admin,$m,$regular] = $this->actors(true);
        $this->freeze($admin, $m);
        $this->actingAs($admin)->postJson('/api/challans', $this->payload($m, $regular))->assertUnprocessable();
        Http::assertNothingSent();
        $fee = FeeType::create(['code' => 'APPROVED_FREEZE', 'name' => 'Freeze fee', 'gl_account' => '610400', 'active' => true, 'is_freeze_fee' => true, 'freeze_amount' => 500]);
        $approval = $this->actingAs($admin)->postJson("/api/members/$m->id/freeze-fee-approvals", ['fee_type_id' => $fee->id, 'reason' => 'Approved schedule'])->assertCreated()->json('id');
        $payload = $this->payload($m, $fee, 500);
        $payload['charges'][0]['freeze_fee_approval_id'] = $approval;
        $this->actingAs($admin)->postJson('/api/challans', $payload)->assertCreated()->assertJsonPath('reconciliation_account', '260111')->assertJsonPath('previous_outstanding', '0.00');
        $this->actingAs($admin)->postJson('/api/challans', $payload)->assertCreated();
        $payload['idempotency_key'] = (string) Str::uuid();
        $this->actingAs($admin)->postJson('/api/challans', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('challans', 1);
        Http::assertSentCount(1);
    }

    public function test_unapproved_or_wrong_amount_freeze_fee_never_calls_sap(): void
    {
        Http::fake();
        [$admin,$m,$fee] = $this->actors(true);
        $this->freeze($admin, $m);
        $fee->update(['is_freeze_fee' => true, 'freeze_amount' => 500]);
        $id = $this->actingAs($admin)->postJson("/api/members/$m->id/freeze-fee-approvals", ['fee_type_id' => $fee->id, 'reason' => 'Approved'])->assertCreated()->json('id');
        $payload = $this->payload($m, $fee, 600);
        $payload['charges'][0]['freeze_fee_approval_id'] = $id;
        $this->actingAs($admin)->postJson('/api/challans', $payload)->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_unpaid_generated_adjustment_preserves_original_and_records_manual_sap_requirement(): void
    {
        Http::fake(['*' => Http::response(['challan_no' => '1700000101'])]);
        [$admin,$m,$fee] = $this->actors(true);
        $c = $this->actingAs($admin)->postJson('/api/challans', $this->payload($m, $fee))->assertCreated()->json();
        $this->actingAs($admin)->putJson("/api/members/$m->id/category", ['membership_category_id' => MembershipCategory::where('name', 'Student Membership')->first()->id, 'reason' => 'Correct category'])->assertOk();
        $this->actingAs($admin)->postJson('/api/challans/'.$c['id'].'/adjust', ['reason' => 'Wrong category', 'proposed_amount' => 2000])->assertCreated()->assertJsonPath('status', 'manual_sap_reconciliation')->assertJsonPath('old_account', '260108')->assertJsonPath('new_account', '260118');
        $original = Challan::find($c['id']);
        $this->assertSame('260108', $original->reconciliation_account);
        $this->assertSame('1000.00', $original->amount);
        $this->assertSame('1700000101', $original->sap_document_no);
        $this->assertSame('generated', $original->status);
        $this->actingAs($admin)->putJson('/api/challans/'.$c['id'], ['status' => 'cancelled'])->assertConflict();
        Http::assertSentCount(1);
    }

    public function test_paid_adjustment_requires_reversal_and_never_edits_financial_fields(): void
    {
        [$admin,$m,$fee] = $this->actors(true);
        $c = $this->draft($m, $fee);
        $c->update(['status' => 'paid', 'sap_status' => 'posted', 'sap_document_no' => '1700000010']);
        $this->actingAs($admin)->postJson("/api/challans/$c->id/adjust", ['reason' => 'Fee review', 'proposed_amount' => 2500])->assertCreated()->assertJsonPath('status', 'reversal_required');
        $this->assertSame('1000.00', $c->fresh()->amount);
        $this->assertSame('paid', $c->fresh()->status);
        $this->assertSame('260108', $c->fresh()->reconciliation_account);
    }

    public function test_draft_recalculation_changes_only_unposted_charges_and_audits_original_items(): void
    {
        [$admin,$m,$fee] = $this->actors(true);
        $c = $this->draft($m, $fee);
        $item = $c->items()->first();
        $this->actingAs($admin)->postJson("/api/challans/$c->id/adjust", ['reason' => 'Correct fee', 'items' => [['id' => $item->id, 'amount' => 1250]]])->assertCreated()->assertJsonPath('status', 'draft_adjusted');
        $this->assertSame('1250.00', $c->fresh()->amount);
        $this->assertSame('1250.00', $item->fresh()->charge->amount);
        $this->assertSame('1000.00', ChallanAdjustment::first()->original_snapshot['items'][0]['amount']);
        Http::assertNothingSent();
    }

    public function test_operators_and_cross_complex_admins_cannot_adjust_freeze_or_map(): void
    {
        [$admin,$m,$fee] = $this->actors(true);
        $c = $this->draft($m, $fee);
        $operator = User::factory()->create(['role' => 'operator']);
        $this->actingAs($operator)->postJson("/api/challans/$c->id/adjust", ['reason' => 'No'])->assertForbidden();
        $this->actingAs($operator)->postJson("/api/members/$m->id/freeze", [])->assertForbidden();
        $this->actingAs($operator)->putJson('/api/accounting/membership-categories/'.$m->membership_category_id, [])->assertForbidden();
        $other = SportsComplex::create(['name' => 'Other', 'code' => 'OTHER']);
        $admin->update(['sports_complex_id' => $other->id]);
        $this->actingAs($admin)->postJson("/api/challans/$c->id/adjust", ['reason' => 'No'])->assertForbidden();
        $this->actingAs($admin)->postJson("/api/members/$m->id/freeze", [])->assertForbidden();
    }

    public function test_verification_uses_original_challan_number_is_deduplicated_and_does_not_settle_unknown_response(): void
    {
        config(['services.sap.verification_parameter'=>'challan']);
        Http::fake(['*' => Http::response('  ')]);
        [$admin,$m,$fee] = $this->actors(true);
        $c = $this->draft($m, $fee);
        $c->update(['status' => 'generated', 'sap_status' => 'posted', 'sap_document_no' => '1700000001', 'company_code' => '2000']);
        $other = $this->draft($m, $fee);
        $other->update(['regenerates_challan_id' => $c->id]);
        $api = "/api/challans/$c->id/verify-payment";
        $this->actingAs($admin)->postJson($api)->assertOk()->assertJsonPath('result', 'verification_unknown');
        $this->actingAs($admin)->postJson($api)->assertOk()->assertJsonPath('result', 'verification_unknown');
        Http::assertSent(fn ($r) => $r['challan'] === '17000000012000'.now()->format('Y'));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('payment_verifications', 1);
        $this->assertSame('260108', $c->fresh()->reconciliation_account);
        $this->assertSame('draft', $other->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
        $c->update(['status' => 'superseded']);
        $this->actingAs($admin)->postJson($api)->assertConflict();
        Http::assertSentCount(1);
    }

    public function test_sap_timeout_blocks_replay_and_no_transaction_is_open_during_http(): void
    {
        [$admin,$m,$fee] = $this->actors(true);
        $baseline = DB::transactionLevel();
        Http::fake(function () use ($baseline) {
            $this->assertSame($baseline, DB::transactionLevel());
            throw new ConnectionException('timeout');
        });
        $payload = $this->payload($m, $fee);
        $this->actingAs($admin)->postJson('/api/challans', $payload)->assertConflict()->assertJsonPath('challan.sap_status', 'verification_required');
        $c = Challan::first();
        $this->actingAs($admin)->postJson("/api/challans/$c->id/retry")->assertConflict();
        $payload['idempotency_key'] = (string) Str::uuid();
        $this->actingAs($admin)->postJson('/api/challans', $payload)->assertConflict();
        $this->assertDatabaseCount('challans', 1);
    }

    public function test_inactive_and_unknown_accounts_fail_before_sap_and_monthly_fee_is_not_duplicated(): void
    {
        Http::fake(['*' => Http::response(['challan_no' => '1700000001'])]);
        [$admin,$m,$fee] = $this->actors(true);
        $account = $m->category->account;
        $account->update(['active' => false]);
        $this->actingAs($admin)->postJson('/api/challans', $this->payload($m, $fee))->assertUnprocessable();
        Http::assertNothingSent();
        $account->update(['active' => true]);
        $fee = FeeType::where('code', 'MONTHLY_FEE')->first() ?? tap($fee)->update(['code' => 'MONTHLY_FEE']);
        $this->actingAs($admin)->postJson('/api/challans', $this->payload($m, $fee))->assertCreated();
        $this->actingAs($admin)->postJson('/api/challans', $this->payload($m, $fee))->assertUnprocessable();
        Http::assertSentCount(1);
    }

    public function test_members_cannot_bypass_freeze_via_profile_or_membership_update(): void
    {
        [$admin,$m] = $this->actors(true);
        $this->freeze($admin, $m);
        $this->actingAs($admin)->putJson("/api/members/$m->id", ['sports_complex_id' => $m->sports_complex_id, 'customer_no' => $m->customer_no, 'full_name' => $m->full_name, 'status' => 'active'])->assertUnprocessable();
        $this->actingAs($admin)->postJson("/api/members/$m->id/memberships", [])->assertConflict();
    }

    public function test_shared_generation_lock_blocks_duplicate_external_requests(): void
    {
        [$admin,$m,$fee] = $this->actors(true);
        $lock = Cache::lock('sap-generation-member:'.$m->id, 120);
        $lock->get();
        try {
            $this->actingAs($admin)->postJson('/api/challans', $this->payload($m, $fee))->assertConflict();
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_admin_remapping_audits_members_but_preserves_historical_challan_account(): void
    {
        [$admin,$m,$fee] = $this->actors(true);
        $c = $this->draft($m, $fee);
        $this->actingAs($admin)->putJson('/api/accounting/membership-categories/'.$m->membership_category_id, ['reconciliation_account_id' => ReconciliationAccount::where('code', '260118')->value('id'), 'active' => true])->assertOk();
        $this->assertSame('260118', $m->fresh()->reconciliation_account);
        $this->assertSame('260108', $c->fresh()->reconciliation_account);
        $this->assertDatabaseHas('membership_account_audits', ['member_id' => $m->id, 'old_account' => '260108', 'new_account' => '260118']);
    }

    public function test_duplicate_master_codes_and_using_freeze_as_a_regular_category_are_rejected(): void
    {
        [$admin,$m] = $this->actors(true);
        $this->actingAs($admin)->postJson('/api/accounting/reconciliation-accounts', ['code' => '260108', 'long_description' => 'Duplicate', 'short_description' => 'Duplicate', 'active' => true])->assertUnprocessable();
        $this->actingAs($admin)->putJson('/api/accounting/membership-categories/'.$m->membership_category_id, ['reconciliation_account_id' => ReconciliationAccount::where('code', '260111')->value('id'), 'active' => true])->assertUnprocessable();
    }

    public function test_legacy_frozen_customer_requires_explicit_original_category_review(): void
    {
        [$admin,$m] = $this->actors();
        $m->update(['reconciliation_account' => '260111']);
        $this->actingAs($admin)->postJson('/api/challans', $this->payload($m, FeeType::where('code', 'TEST_FEE')->first()))->assertUnprocessable();
        $this->actingAs($admin)->postJson("/api/members/$m->id/freeze", ['starts_on' => today()->toDateString(), 'reason' => 'Legacy review'])->assertUnprocessable();
        $category = MembershipCategory::where('name', 'General Public Individual Membership')->first();
        $this->actingAs($admin)->postJson("/api/members/$m->id/freeze", ['starts_on' => today()->toDateString(), 'reason' => 'Original category verified against record', 'original_membership_category_id' => $category->id])->assertOk()->assertJsonPath('original_reconciliation_account', '260110');
        $this->actingAs($admin)->postJson("/api/members/$m->id/unfreeze", ['reason' => 'Return approved'])->assertOk()->assertJsonPath('reconciliation_account', '260110');
    }

    public function test_frozen_members_have_no_portal_facility_benefits(): void
    {
        [$admin,$m] = $this->actors(true);
        $m->memberships()->create(['starts_on' => today()->subMonth(), 'expires_on' => today()->addMonth(), 'status' => 'active']);
        $this->freeze($admin, $m);
        $portal = User::factory()->create(['role' => 'member', 'member_id' => $m->id, 'must_change_password' => false]);
        $this->actingAs($portal)->getJson('/api/portal/facilities')->assertOk()->assertJsonPath('facilities', []);
    }

    public function test_payment_verification_timeout_can_be_rechecked_after_cooldown_without_posting(): void
    {
        [$admin,$m,$fee] = $this->actors(true);
        $c = $this->draft($m, $fee);
        $c->update(['status' => 'generated', 'sap_status' => 'posted', 'sap_document_no' => '1700000001', 'company_code' => '2000']);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            if (++$calls === 1) {
                throw new ConnectionException('timeout');
            }

            return Http::response('unrecognized paid string');
        });
        $api = "/api/challans/$c->id/verify-payment";
        $this->actingAs($admin)->postJson($api)->assertOk()->assertJsonPath('result', 'verification_error');
        $this->travel(31)->seconds();

        $this->actingAs($admin)->postJson($api)->assertOk()->assertJsonPath('result', 'verification_unknown');
        $this->assertDatabaseCount('payment_verifications', 2);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('generated', $c->fresh()->status);
        $this->travelBack();
    }

    public function test_paid_challan_with_unknown_current_mapping_still_records_a_manual_reversal_request(): void
    {
        [$admin,$member,$fee] = $this->actors();
        $challan = $this->draft($member, $fee);
        $challan->update(['status' => 'paid', 'sap_status' => 'posted', 'sap_document_no' => '1700000001']);
        $member->update(['reconciliation_account' => '260000']);
        $this->actingAs($admin)->postJson("/api/challans/{$challan->id}/adjust", ['reason' => 'Mapping requires review'])->assertCreated()->assertJsonPath('status', 'reversal_required')->assertJsonPath('new_account', null);
        $this->assertSame('260108', $challan->fresh()->reconciliation_account);
        $this->assertSame('paid', $challan->fresh()->status);
    }

    private function actors(bool $mapped = false): array
    {
        $complex = SportsComplex::create(['name' => 'Synthetic complex', 'code' => 'TEST', 'cost_center' => '21070001', 'profit_center' => '21070001']);
        $m = Member::create(['sports_complex_id' => $complex->id, 'customer_no' => 'TEST-1', 'full_name' => 'Synthetic member', 'cnic' => '3520200000001', 'phone' => '03000000001', 'address' => 'Test address', 'reconciliation_account' => '260108', 'membership_category_id' => $mapped ? MembershipCategory::where('name', 'General Public Family Membership')->first()->id : null]);
        $fee = FeeType::create(['code' => 'TEST_FEE', 'name' => 'Test fee', 'gl_account' => '610400', 'active' => true]);

        return [User::factory()->create(['role' => 'admin']), $m, $fee];
    }

    private function freeze(User $u, Member $m): void
    {
        $this->actingAs($u)->postJson("/api/members/$m->id/freeze", ['starts_on' => today()->toDateString(), 'reason' => 'Approved freeze'])->assertOk();
    }

    private function payload(Member $m, FeeType $fee, int $amount = 1000): array
    {
        return ['member_id' => $m->id, 'idempotency_key' => (string) Str::uuid(), 'due_on' => today()->addWeek()->toDateString(), 'charges' => [['fee_type_id' => $fee->id, 'amount' => $amount]]];
    }

    private function draft(Member $m, FeeType $fee): Challan
    {
        $c = Challan::create(['sports_complex_id' => $m->sports_complex_id, 'member_id' => $m->id, 'challan_no' => 'DRAFT-'.Str::uuid(), 'amount' => 1000, 'current_amount' => 1000, 'issued_on' => today(), 'due_on' => today()->addWeek(), 'purpose' => 'Test', 'status' => 'draft', 'sap_status' => 'not_sent', 'environment' => 'live', 'reconciliation_account' => '260108', 'membership_category_id' => $m->membership_category_id]);
        $charge = MemberCharge::create(['member_id' => $m->id, 'fee_type_id' => $fee->id, 'description' => 'Test', 'amount' => 1000, 'gl_account' => '610400', 'reconciliation_account' => '260108']);
        $c->items()->create(['member_charge_id' => $charge->id, 'fee_type_id' => $fee->id, 'description' => 'Test', 'amount' => 1000, 'gl_account' => '610400', 'cost_center' => '0021070001', 'profit_center' => '021070001', 'reconciliation_account' => '260108']);

        return $c;
    }
}
