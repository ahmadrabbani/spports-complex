<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentPurpose;
use App\Models\BankTransaction;
use App\Models\Challan;
use App\Models\Facility;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\SportsComplex;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function withToken($token, $type = 'Bearer')
    {
        $this->app['auth']->forgetGuards();

        return parent::withToken($token, $type);
    }

    private function user(array $attrs = []): User
    {
        return User::factory()->create($attrs + ['role' => 'member', 'is_active' => true, 'must_change_password' => false, 'password' => Hash::make('ExamplePass123')]);
    }

    private function member(string $code): Member
    {
        $c = SportsComplex::create(['name' => $code, 'code' => $code, 'slug' => strtolower($code), 'city' => 'Lahore']);

        return Member::create(['sports_complex_id' => $c->id, 'customer_no' => $code, 'full_name' => 'Synthetic Member', 'status' => 'active']);
    }

    public function test_mobile_cannot_add_family_to_an_individual_membership(): void
    {
        $member = $this->member('IND');
        $member->update(['reconciliation_account' => '260115']);
        $user = $this->user(['member_id' => $member->id]);
        $token = $user->createToken('phone', ['mobile:member'])->plainTextToken;
        $this->withToken($token)->getJson('/api/mobile/v1/family-members?include_eligibility=1')->assertOk()->assertJsonPath('can_add_family_members', false);
        $this->withToken($token)->postJson('/api/mobile/v1/family-members', ['full_name' => 'Blocked', 'relationship' => 'Son'])->assertUnprocessable()->assertJsonPath('code', 'FAMILY_MEMBERS_NOT_ALLOWED');
        $this->assertSame(0, $member->familyMembers()->count());
    }

    public function test_token_login_requires_member_credentials_and_no_session_or_csrf(): void
    {
        $u = $this->user(['cnic' => '9999900000001']);
        $result = $this->postJson('/api/mobile/v1/auth/login', ['login' => '99999-0000000-1', 'password' => 'ExamplePass123', 'device_name' => 'Test phone'])->assertOk()->assertJsonPath('token_type', 'Bearer')->assertJsonMissingPath('user.password');
        $token = $result->json('access_token');
        $this->assertDatabaseMissing('personal_access_tokens', ['token' => $token]);
        $this->withToken($token)->getJson('/api/mobile/v1/auth/me')->assertOk()->assertJsonPath('user.id', $u->id);
        $u->update(['is_active' => false]);
        $this->withToken($token)->getJson('/api/mobile/v1/auth/me')->assertForbidden();
        $staff = $this->user(['role' => 'admin']);
        $this->postJson('/api/mobile/v1/auth/login', ['login' => $staff->email, 'password' => 'ExamplePass123', 'device_name' => 'Test'])->assertUnprocessable();
    }

    public function test_missing_expired_wrong_ability_and_revoked_tokens_are_rejected_as_json(): void
    {
        $this->get('/api/mobile/v1/me')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        $u = $this->user();
        $expired = $u->createToken('old', ['mobile:member'], now()->subMinute())->plainTextToken;
        $this->withToken($expired)->getJson('/api/mobile/v1/me')->assertUnauthorized();
        $wrong = $u->createToken('wrong', ['other'])->plainTextToken;
        $this->withToken($wrong)->getJson('/api/mobile/v1/me')->assertForbidden();
        $token = $u->createToken('phone', ['mobile:member'])->plainTextToken;
        $this->withToken($token)->postJson('/api/mobile/v1/auth/logout')->assertNoContent();
        $this->withToken($token)->getJson('/api/mobile/v1/auth/me')->assertUnauthorized();
    }

    public function test_temporary_password_gate_and_change_revoke_all_devices(): void
    {
        $u = $this->user(['must_change_password' => true]);
        $a = $u->createToken('a', ['mobile:member'])->plainTextToken;
        $u->createToken('b', ['mobile:member']);
        $this->withToken($a)->getJson('/api/mobile/v1/me')->assertStatus(409)->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->withToken($a)->putJson('/api/mobile/v1/auth/password', ['current_password' => 'wrong', 'password' => 'ChangedPass123', 'password_confirmation' => 'ChangedPass123'])->assertUnprocessable();
        $this->withToken($a)->putJson('/api/mobile/v1/auth/password', ['current_password' => 'ExamplePass123', 'password' => 'ChangedPass123', 'password_confirmation' => 'ChangedPass123'])->assertOk();
        $this->assertFalse($u->fresh()->must_change_password);
        $this->assertSame(0, $u->tokens()->count());
        $this->withToken($a)->getJson('/api/mobile/v1/me')->assertUnauthorized();
    }

    public function test_members_can_only_read_their_own_finances_and_cards(): void
    {
        $m = $this->member('AA');
        $other = $this->member('BB');
        $u = $this->user(['member_id' => $m->id]);
        $t = $u->createToken('phone', ['mobile:member'])->plainTextToken;
        $c = Challan::create(['member_id' => $other->id, 'sports_complex_id' => $other->sports_complex_id, 'challan_no' => 'TEST-OTHER', 'purpose' => 'Test', 'amount' => 100, 'issued_on' => today(), 'due_on' => today()->addDays(7), 'status' => 'unpaid']);
        $this->withToken($t)->getJson('/api/mobile/v1/challans/'.$c->id)->assertForbidden();
        $this->withToken($t)->getJson('/api/mobile/v1/challans/'.$c->id.'/print')->assertForbidden();
        $this->withToken($t)->postJson('/api/mobile/v1/challans/'.$c->id.'/bank/initiate', ['initiation_key' => (string) Str::uuid()])->assertForbidden();
        $this->withToken($t)->getJson('/api/mobile/v1/challans')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($t)->getJson('/api/mobile/v1/me/card.pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->withToken($t)->getJson('/api/mobile/v1/dashboard')->assertOk();
        $this->withToken($t)->getJson('/api/mobile/v1/ledger')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_registration_does_not_claim_existing_member_by_cnic(): void
    {
        $m = $this->member('AA');
        $m->update(['cnic' => '9999900000002']);
        $data = ['name' => 'Demo Applicant', 'cnic' => '9999900000002', 'email' => 'synthetic@example.test', 'phone' => '03000000000', 'password' => 'ExamplePass123', 'password_confirmation' => 'ExamplePass123', 'device_name' => 'Phone'];
        $this->postJson('/api/mobile/v1/auth/register', $data)->assertUnprocessable();
        $data['cnic'] = '9999900000003';
        $this->postJson('/api/mobile/v1/auth/register', $data + ['role' => 'admin', 'member_id' => $m->id])->assertCreated();
        $u = User::where('email', $data['email'])->first();
        $this->assertSame('member', $u->role);
        $this->assertNull($u->member_id);
    }

    public function test_reset_revokes_tokens_and_clears_temporary_password(): void
    {
        $u = $this->user(['must_change_password' => true]);
        $u->createToken('phone', ['mobile:member']);
        $reset = Password::createToken($u);
        $this->postJson('/api/mobile/v1/auth/reset-password', ['email' => $u->email, 'token' => $reset, 'password' => 'ChangedPass123', 'password_confirmation' => 'ChangedPass123'])->assertOk();
        $this->assertSame(0, $u->tokens()->count());
        $this->assertFalse($u->fresh()->must_change_password);
    }

    public function test_auth_endpoint_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/mobile/v1/auth/login', ['login' => 'missing@example.test', 'password' => 'wrong', 'device_name' => 'phone'])->assertUnprocessable();
        }
        $this->postJson('/api/mobile/v1/auth/login', ['login' => 'missing@example.test', 'password' => 'wrong', 'device_name' => 'phone'])->assertStatus(429);
    }

    public function test_mobile_bank_checkout_and_verified_payment_flow_without_cookies(): void
    {
        config(['services.bop_mpgs.enabled' => true, 'services.bop_mpgs.environment' => 'test',
            'services.bop_mpgs.base_url' => 'https://test-gateway.mastercard.com', 'services.bop_mpgs.api_version' => 73,
            'services.bop_mpgs.merchant_id' => 'TEST', 'services.bop_mpgs.api_password' => 'synthetic-secret']);
        $m = $this->member('AA');
        $u = $this->user(['member_id' => $m->id]);
        $token = $u->createToken('phone', ['mobile:member'])->plainTextToken;
        $c = Challan::create(['member_id' => $m->id, 'sports_complex_id' => $m->sports_complex_id, 'challan_no' => 'TEST-MOBILE', 'purpose' => 'Test', 'amount' => 100, 'issued_on' => today(), 'due_on' => today()->addDays(7), 'status' => 'generated']);
        Http::fake(fn ($r) => Http::response($r['apiOperation'] === 'INITIATE_CHECKOUT' ? 'result=SUCCESS&session.id=TESTSESSION&successIndicator=TESTINDICATOR' : 'result=SUCCESS&order.id='.$r['order.id'].'&order.amount=100.00&order.currency=PKR&order.status=CAPTURED&transaction%5B0%5D.id=TESTREF'));
        $response = $this->withToken($token)->postJson('/api/mobile/v1/challans/'.$c->id.'/bank/initiate', ['initiation_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('status', 'session_created')->assertJsonMissingPath('session_id');
        Http::assertSent(fn ($r) => $r['interaction.returnUrl'] === route('mobile.bank.return'));
        $checkout = $response->json('checkout_url');
        $this->get($checkout)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $tx = BankTransaction::firstOrFail();
        $this->get('/api/mobile/v1/bank/checkout/'.$tx->public_id)->assertForbidden();
        $this->travel(11)->minutes();
        $this->get($checkout)->assertForbidden();
        $this->travelBack();
        $this->get('/api/mobile/v1/bank/return?resultIndicator=anything')->assertOk();
        $this->assertDatabaseCount('payments', 0);
        $other = $this->user();
        $otherToken = $other->createToken('other', ['mobile:member'])->plainTextToken;
        $this->withToken($otherToken)->getJson('/api/mobile/v1/bank-transactions/'.$tx->public_id)->assertForbidden();
        $this->withToken($token)->get('/api/mobile/v1/bank-transactions/'.$tx->public_id.'/receipt')->assertNotFound();
        $this->withToken($token)->postJson('/api/mobile/v1/bank-transactions/'.$tx->public_id.'/verify')->assertOk()->assertJsonPath('status', 'verified');
        $this->withToken($token)->get('/api/mobile/v1/bank-transactions/'.$tx->public_id.'/receipt')->assertOk();
        $this->withToken($token)->postJson('/api/mobile/v1/bank-transactions/'.$tx->public_id.'/verify')->assertOk();
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_facility_plan_access_and_notification_ownership(): void
    {
        $m = $this->member('AA');
        $u = $this->user(['member_id' => $m->id]);
        $token = $u->createToken('phone', ['mobile:member'])->plainTextToken;
        $plan = MembershipPlan::create(['name' => 'Allowed', 'sports_complex_id' => $m->sports_complex_id]);
        $otherPlan = MembershipPlan::create(['name' => 'Other', 'sports_complex_id' => $m->sports_complex_id]);
        Membership::create(['member_id' => $m->id, 'membership_plan_id' => $plan->id, 'starts_on' => today(), 'status' => 'active']);
        $open = Facility::create(['sports_complex_id' => $m->sports_complex_id, 'name' => 'Open', 'slug' => 'open', 'is_active' => true]);
        $restricted = Facility::create(['sports_complex_id' => $m->sports_complex_id, 'name' => 'Restricted', 'slug' => 'restricted', 'is_active' => true]);
        $restricted->membershipPlans()->attach($otherPlan);
        $this->withToken($token)->getJson('/api/mobile/v1/facilities')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $open->id);
        $n = UserNotification::create(['user_id' => $u->id, 'type' => 'test', 'title' => 'Test', 'message' => 'Test']);
        $foreign = UserNotification::create(['user_id' => $this->user()->id, 'type' => 'test', 'title' => 'Other', 'message' => 'Other']);
        $this->withToken($token)->putJson('/api/mobile/v1/notifications/'.$foreign->id.'/read')->assertForbidden();
        $this->withToken($token)->putJson('/api/mobile/v1/notifications/'.$n->id.'/read')->assertOk();
        $this->assertNotNull($n->fresh()->read_at);
    }

    public function test_appointments_are_private_and_browser_session_cannot_replace_token(): void
    {
        $m = $this->member('AA');
        $u = $this->user(['member_id' => $m->id]);
        $other = $this->user();
        $purpose = AppointmentPurpose::create(['name' => 'Visit', 'is_active' => true]);
        $appointment = Appointment::create(['public_id' => (string) Str::uuid(), 'appointment_no' => 'APT-TEST-1', 'user_id' => $other->id, 'sports_complex_id' => $m->sports_complex_id, 'appointment_purpose_id' => $purpose->id, 'appointment_date' => today()->addWeek(), 'appointment_time' => '10:00', 'status' => 'pending']);
        $token = $u->createToken('phone', ['mobile:member'])->plainTextToken;
        $this->withToken($token)->getJson('/api/mobile/v1/appointments/'.$appointment->public_id)->assertForbidden();
        $this->withToken($token)->postJson('/api/mobile/v1/appointments/'.$appointment->public_id.'/cancel')->assertForbidden();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->actingAs($u, 'web')->getJson('/api/mobile/v1/me')->assertUnauthorized();
    }
}
