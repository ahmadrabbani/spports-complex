<?php

namespace Tests\Feature;

use App\Models\Challan;
use App\Models\FeeType;
use App\Models\Member;
use App\Models\MemberCharge;
use App\Models\SportsComplex;
use App\Models\User;
use App\Services\SportsComplexChallanService;
use Database\Seeders\DatabaseSeeder;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SportsComplexManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.payment_mode' => 'live', 'services.sap.challan_url' => 'http://sap.test/SAPVERIFICATION/Challan']);
    }

    public function test_guest_cannot_read_member_data(): void
    {
        $this->getJson('/api/members')->assertUnauthorized();
    }

    public function test_approved_charge_gl_accounts_are_seeded_without_reconciliation_overrides(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (['Monthly Fee' => '610400', 'Arrears Monthly Fee' => '610403', 'Fine Monthly Fee' => '610402', 'Form Fee' => '610404', 'Card Fee' => '610401', 'Membership Registration Fee' => '610357'] as $name => $gl) {
            $this->assertDatabaseHas('fee_types', ['name' => $name, 'gl_account' => $gl, 'active' => true, 'reconciliation_account' => null]);
        }

    }

    public function test_complex_operator_only_sees_members_from_assigned_complex(): void
    {
        $a = $this->complex('A', '10000001');
        $b = $this->complex('B', '10000002');
        Member::create(['sports_complex_id' => $a->id, 'customer_no' => 'A1', 'full_name' => 'Alpha', 'status' => 'active']);
        Member::create(['sports_complex_id' => $b->id, 'customer_no' => 'B1', 'full_name' => 'Beta', 'status' => 'active']);
        $user = User::factory()->create(['role' => 'operator', 'sports_complex_id' => $a->id]);
        $this->actingAs($user)->getJson('/api/members')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.customer_no', 'A1');
    }

    public function test_admin_can_create_and_search_a_member(): void
    {
        $complex = $this->complex();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson('/api/members', ['sports_complex_id' => $complex->id, 'customer_no' => '2501', 'full_name' => 'Ayesha Khan', 'cnic' => '3520200000000', 'status' => 'active'])->assertCreated();
        $this->actingAs($admin)->getJson('/api/members?q=3520200000000')->assertOk()->assertJsonPath('data.0.full_name', 'Ayesha Khan');
    }

    public function test_admin_member_lists_show_each_members_outstanding_due_amount(): void
    {
        $complex = $this->complex();
        $admin = User::factory()->create(['role' => 'admin']);
        $member = Member::create([
            'sports_complex_id' => $complex->id,
            'customer_no' => '2502',
            'full_name' => 'Due Member',
            'status' => 'active',
            'source_created_at' => now(),
        ]);
        $fee = $this->fee();
        foreach ([['outstanding', 12500], ['paid', 4000], ['cancelled', 3000]] as [$status, $amount]) {
            MemberCharge::create([
                'member_id' => $member->id,
                'fee_type_id' => $fee->id,
                'description' => ucfirst($status).' fee',
                'amount' => $amount,
                'gl_account' => $fee->gl_account,
                'reconciliation_account' => '260108',
                'status' => $status,
            ]);
        }

        $dueIsCorrect = fn ($value) => (float) $value === 12500.0;
        $this->actingAs($admin)->getJson('/api/members?q=2502')
            ->assertOk()
            ->assertJsonPath('data.0.due_amount', $dueIsCorrect);
        $this->actingAs($admin)->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('recent_members.0.due_amount', $dueIsCorrect);
    }

    public function test_single_charge_posts_encoded_applicant_and_accounting_fields_to_sap(): void
    {
        Http::fake(['sap.test/*' => Http::response(['success' => true, 'challan_no' => 'SAP-1001', 'reference' => 'REF-8'])]);
        [$admin, $member, $fee] = $this->actors();

        $response = $this->actingAs($admin)->postJson('/api/challans', $this->payload($member, $fee, 10000, 'Current membership & renewal'))
            ->assertCreated()
            ->assertJsonPath('sap_document_no', 'SAP-1001')
            ->assertJsonPath('previous_outstanding', '0.00')
            ->assertJsonPath('current_amount', '10000.00')
            ->assertJsonPath('amount', '10000.00');

        Http::assertSent(function ($request) {
            return $request['fname'] === 'Tahira'
                && $request['lname'] === 'M. Ashraf Jamil'
                && $request['cnic'] === '3520222561630'
                && $request['amount'] === '10000.00'
                && $request['desc'] === 'Current membership & renewal'
                && $request['glSAP'] === '0000610310'
                && $request['reacon'] === '260108'
                && $request['center'] === '0021070001'
                && $request['pcenter'] === '021070001'
                && $request['file'] === 'MR-1600-II-2381';
        });
        $this->assertStringContainsString('Current%20membership%20%26%20renewal', Challan::find($response->json('id'))->request_url);
    }

    public function test_multiple_current_fees_keep_amount_description_and_gl_arrays_aligned(): void
    {
        Http::fake(['*' => Http::response(['challan_no' => 'SAP-2001'])]);
        [$admin, $member, $membership] = $this->actors();
        $penalty = $this->fee('PENALTY', 'Penalty', '0000610343');
        $payload = $this->payload($member, $membership, 228300);
        $payload['charges'][] = ['fee_type_id' => $penalty->id, 'description' => 'Late Fee', 'amount' => 3300];

        $this->actingAs($admin)->postJson('/api/challans', $payload)->assertCreated()->assertJsonCount(2, 'items')->assertJsonPath('amount', '231600.00');
        Http::assertSent(fn ($request) => $request['amount'] === '228300.00,3300.00'
            && $request['desc'] === 'Membership Fee,Late Fee'
            && $request['glSAP'] === '0000610310,0000610343');
    }

    public function test_live_sap_charge_is_not_rebilled_and_duplicate_click_is_idempotent(): void
    {
        Http::fake(['*' => Http::sequence()->push(['challan_no' => 'SAP-1'])->push(['challan_no' => 'SAP-2'])]);
        [$admin, $member, $fee] = $this->actors();
        $first = $this->payload($member, $fee, 20000);
        $this->actingAs($admin)->postJson('/api/challans', $first)->assertCreated();
        $second = $this->payload($member, $fee, 10000);
        $created = $this->actingAs($admin)->postJson('/api/challans', $second)->assertCreated()
            ->assertJsonPath('previous_outstanding', '0.00')->assertJsonPath('current_amount', '10000.00')->assertJsonPath('amount', '10000.00');

        $this->actingAs($admin)->postJson('/api/challans', $second)->assertCreated()->assertJsonPath('id', $created->json('id'))->assertJsonPath('amount', '10000.00');
        $this->assertDatabaseCount('member_charges', 2);
        $this->assertDatabaseCount('challans', 2);
        $this->assertSame('generated', Challan::first()->status);
    }

    public function test_paid_previous_charge_is_not_carried_forward(): void
    {
        Http::fake(['*' => Http::sequence()->push(['challan_no' => 'SAP-1'])->push(['challan_no' => 'SAP-2'])]);
        [$admin, $member, $fee] = $this->actors();
        $first = $this->actingAs($admin)->postJson('/api/challans', $this->payload($member, $fee, 20000))->assertCreated()->json();
        $this->actingAs($admin)->putJson('/api/challans/'.$first['id'], ['status' => 'paid'])->assertConflict();
        app(SportsComplexChallanService::class)->markPaid(Challan::findOrFail($first['id']));

        $this->actingAs($admin)->postJson('/api/challans', $this->payload($member, $fee, 10000))->assertCreated()
            ->assertJsonPath('previous_outstanding', '0.00')->assertJsonPath('amount', '10000.00');
    }

    public function test_multiple_previous_charges_are_carried_with_original_gl_breakup(): void
    {
        Http::fake(['*' => Http::response(['challan_no' => 'SAP-3'])]);
        [$admin, $member, $fee] = $this->actors();
        $penalty = $this->fee('PENALTY', 'Penalty', '0000610343');
        foreach ([[$fee, 20000], [$penalty, 3000]] as [$type, $amount]) {
            MemberCharge::create(['member_id' => $member->id, 'fee_type_id' => $type->id, 'description' => $type->name, 'amount' => $amount, 'gl_account' => $type->gl_account, 'reconciliation_account' => '260108', 'status' => 'outstanding']);
        }

        $this->actingAs($admin)->postJson('/api/challans', $this->payload($member, $fee, 10000))->assertCreated()
            ->assertJsonPath('previous_outstanding', '23000.00')->assertJsonPath('amount', '33000.00')->assertJsonCount(3, 'items');
        Http::assertSent(fn ($request) => $request['glSAP'] === '0000610310,0000610343,0000610310');
    }

    public function test_live_sap_challan_cannot_be_cancelled_locally(): void
    {
        Http::fake(['*' => Http::sequence()->push(['challan_no' => 'SAP-1'])->push(['challan_no' => 'SAP-2'])]);
        [$admin, $member, $fee] = $this->actors();
        $first = $this->actingAs($admin)->postJson('/api/challans', $this->payload($member, $fee, 20000))->assertCreated()->json();
        $this->actingAs($admin)->putJson('/api/challans/'.$first['id'], ['status' => 'cancelled'])->assertConflict();

        $this->actingAs($admin)->postJson('/api/challans', $this->payload($member, $fee, 10000))->assertCreated()->assertJsonPath('previous_outstanding', '0.00');
    }

    public function test_expired_sap_charge_is_not_rebilled_without_sap_cancellation(): void
    {
        Http::fake(['*' => Http::sequence()->push(['challan_no' => 'SAP-1'])->push(['challan_no' => 'SAP-2'])]);
        [$admin, $member, $fee] = $this->actors();
        $first = $this->actingAs($admin)->postJson('/api/challans', $this->payload($member, $fee, 20000))->assertCreated()->json();
        Challan::find($first['id'])->update(['status' => 'expired']);

        $this->actingAs($admin)->postJson('/api/challans', $this->payload($member, $fee, 10000))->assertCreated()->assertJsonPath('previous_outstanding', '0.00');
    }

    public function test_missing_gl_or_exact_center_fails_closed_before_sap_call(): void
    {
        Http::fake();
        [$admin, $member, $fee] = $this->actors();
        $fee->update(['gl_account' => null]);
        $this->actingAs($admin)->postJson('/api/members/'.$member->id.'/challan-preview', ['charges' => [['fee_type_id' => $fee->id, 'amount' => 10]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['charges.0.fee_type_id']);
        $fee->update(['gl_account' => '0000610310']);
        $member->sportsComplex->update(['cost_center' => null]);
        $this->actingAs($admin)->postJson('/api/members/'.$member->id.'/challan-preview', ['charges' => [['fee_type_id' => $fee->id, 'amount' => 10]]])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_timeout_is_audited_as_verification_required_and_not_silently_retried(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));
        [$admin, $member, $fee] = $this->actors();
        $this->actingAs($admin)->postJson('/api/challans', $this->payload($member, $fee, 10000))->assertConflict()
            ->assertJsonPath('challan.sap_status', 'verification_required');
        $this->assertDatabaseHas('challans', ['status' => 'failed', 'sap_status' => 'verification_required']);
    }

    public function test_http_error_and_ambiguous_200_are_not_treated_as_success(): void
    {
        [$admin, $member, $fee] = $this->actors();
        Http::fake(['*' => Http::sequence()->push(['message' => 'validation error'], 422)->push(['status' => 'ok'], 200)]);
        $this->actingAs($admin)->postJson('/api/challans', $this->payload($member, $fee, 10000))->assertStatus(502)->assertJsonPath('challan.sap_status', 'failed');
        $this->actingAs($admin)->postJson('/api/challans', $this->payload($member, $fee, 12000))->assertConflict()
            ->assertJsonPath('message', 'SAP service did not return a valid challan number.');
    }

    public function test_applicant_cnic_phone_address_and_file_are_required(): void
    {
        Http::fake();
        [$admin, $member, $fee] = $this->actors();
        $member->update(['cnic' => null, 'phone' => null, 'address' => null, 'membership_no' => null, 'customer_no' => '']);
        $this->actingAs($admin)->postJson('/api/members/'.$member->id.'/challan-preview', ['charges' => [['fee_type_id' => $fee->id, 'amount' => 10]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['cnic', 'phone', 'address', 'file']);
    }

    public function test_operator_cannot_generate_challan_for_another_complex(): void
    {
        Http::fake();
        [$admin, $member, $fee] = $this->actors();
        $other = $this->complex('Other', '21090001');
        $operator = User::factory()->create(['role' => 'operator', 'sports_complex_id' => $other->id]);
        $this->actingAs($operator)->postJson('/api/challans', $this->payload($member, $fee, 100))->assertForbidden();
    }

    public function test_johar_town_requires_a_configured_pair_and_snapshots_each_choice(): void
    {
        [$admin, $member, $fee] = $this->actors();
        $this->seed(DatabaseSeeder::class);
        $jt = SportsComplex::where('code', 'JT')->firstOrFail();
        $member->update(['sports_complex_id' => $jt->id]);
        config(['services.payment_mode' => 'mock']);
        Http::preventStrayRequests();
        $payload = $this->payload($member, $fee, 100);
        $previewUrl = "/api/members/{$member->id}/challan-preview";
        $this->actingAs($admin)->postJson($previewUrl, $payload)->assertUnprocessable()->assertJsonValidationErrors('sap_center_option');
        $this->postJson('/api/challans', $payload)->assertUnprocessable();
        $this->postJson($previewUrl, $payload + ['sap_center_option' => 'CW-1'])->assertUnprocessable();
        $this->assertSame(0, Challan::count());
        $first = null;
        for ($i = 1; $i <= 4; $i++) {
            $cost = '002101000'.$i;
            $profit = '02101000'.$i;
            $data = $this->payload($member, $fee, 100) + ['sap_center_option' => 'JT-'.$i];
            $this->postJson($previewUrl, $data)->assertOk()->assertJsonPath('cost_center', $cost)->assertJsonPath('profit_center', $profit);
            $id = $this->postJson('/api/challans', $data)->assertCreated()->json('id');
            $challan = Challan::findOrFail($id);
            $this->assertSame($cost, $challan->sap_payload['center']);
            $this->assertSame($profit, $challan->sap_payload['pcenter']);
            $this->assertSame($cost, $challan->items()->first()->cost_center);
            $this->assertSame($profit, $challan->items()->first()->profit_center);
            $first ??= $id;
        }
        $this->assertSame('021010001', Challan::find($first)->items()->first()->profit_center);
        $this->assertNull($jt->fresh()->cost_center);
        $this->assertNull($jt->fresh()->profit_center);
        Http::assertNothingSent();
        $cw = SportsComplex::where('code', 'CW')->firstOrFail();
        $this->assertSame('0021070001', $cw->cost_center);
        $this->assertSame('021070001', $cw->profit_center);
        $member->update(['sports_complex_id' => $cw->id]);
        $this->postJson($previewUrl, $payload + ['sap_center_option' => 'JT-1'])->assertUnprocessable();
    }

    public function test_public_php_creation_sends_all_applicant_and_accounting_parameters(): void
    {
        [$admin, $member, $fee] = $this->actors();
        $member->sportsComplex->update(['profit_center' => '21070001']);
        config(['services.sap.challan_url' => 'http://public.test/sapchallan/index.php', 'services.sap.challan_fallback_url' => 'http://internal.test/sapchallan/index.php']);
        Http::fake(['*' => Http::response(['challan_no' => 'PHP-1001'])]);
        $this->actingAs($admin)->postJson('/api/challans', $this->payload($member, $fee, 100))->assertCreated();
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $this->assertEqualsCanonicalizing(['fname', 'lname', 'address', 'phone', 'cnic', 'amount', 'desc', 'area', 'type', 'file', 'glSAP', 'reacon', 'center', 'pcenter'], array_keys($request->data()));

            return str_starts_with($request->url(), 'http://public.test/sapchallan/index.php')
                && $request['center'] === '0021070001' && $request['pcenter'] === '021070001'
                && $request['reacon'] === '260108' && $request['glSAP'] === '0000610310';
        });
    }

    public function test_php_proxy_plain_number_is_saved_without_changing_the_document(): void
    {
        [$admin, $member, $fee] = $this->actors();
        Http::fake(['*' => Http::response('1700160703', 200, ['Content-Type' => 'text/plain'])]);
        $this->actingAs($admin)->postJson('/api/challans', $this->payload($member, $fee, 100))
            ->assertCreated()->assertJsonPath('sap_document_no', '1700160703')->assertJsonPath('sap_status', 'posted');
        Http::assertSentCount(1);
    }

    public function test_preview_displays_the_encoded_generation_url_without_contacting_sap(): void
    {
        [$admin, $member, $fee] = $this->actors();
        $member->update(['address' => 'House A+B & Main Road']);
        Http::fake(['*' => Http::response(['challan_no' => '1700160703'])]);
        $payload = $this->payload($member, $fee, 100);
        $preview = $this->actingAs($admin)->postJson('/api/members/'.$member->id.'/challan-preview', $payload)->assertOk();
        $url = $preview->json('request_url');
        $this->assertStringStartsWith('http://sap.test/SAPVERIFICATION/Challan?', $url);
        $this->assertStringContainsString('House%20A%2BB%20%26%20Main%20Road', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $parameters);
        $this->assertSame('100.00', $parameters['amount']);
        $this->assertSame('0000610310', $parameters['glSAP']);
        $this->assertSame($member->membership_no, $parameters['file']);
        Http::assertNothingSent();
        $this->postJson('/api/challans', $payload)->assertCreated();
        Http::assertSent(fn ($request) => $request->data() === $parameters);
    }

    public function test_php_proxy_debug_url_is_not_a_challan_number(): void
    {
        [$admin, $member, $fee] = $this->actors();
        Http::fake(['*' => Http::response('http://172.16.70.8:8080/SAPVERIFICATION/Challan?cnic=3520200000000&amount=100', 200)]);
        $this->actingAs($admin)->postJson('/api/challans', $this->payload($member, $fee, 100))
            ->assertConflict()->assertJsonPath('challan.sap_status', 'verification_required');
    }

    public function test_internal_fallback_only_follows_a_proven_unsent_connection_failure(): void
    {
        [$admin, $member, $fee] = $this->actors();
        config(['services.sap.challan_url' => 'http://public.test/sapchallan/index.php', 'services.sap.challan_fallback_url' => 'http://internal.test/sapchallan/index.php']);
        $attempts = [];
        Http::fake(function ($request) use (&$attempts) {
            $attempts[] = parse_url($request->url(), PHP_URL_HOST);
            if (count($attempts) === 1) {
                $transport = new ConnectException('Connection refused', new Request('GET', 'http://public.test/sapchallan/index.php'), null, ['errno' => 7, 'request_size' => 0]);
                throw new ConnectionException('Primary unavailable', 0, $transport);
            }

            return Http::response(['challan_no' => 'PHP-FALLBACK-1']);
        });
        $id = $this->actingAs($admin)->postJson('/api/challans', $this->payload($member, $fee, 100))->assertCreated()->json('id');
        $this->assertSame(['public.test', 'internal.test'], $attempts);
        $this->assertStringStartsWith('http://internal.test/sapchallan/index.php?', Challan::find($id)->request_url);
    }

    public function test_timeout_does_not_submit_again_to_internal_fallback(): void
    {
        [$admin, $member, $fee] = $this->actors();
        config(['services.sap.challan_url' => 'http://public.test/sapchallan/index.php', 'services.sap.challan_fallback_url' => 'http://internal.test/sapchallan/index.php']);
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            $transport = new ConnectException('Timeout', new Request('GET', 'http://public.test/sapchallan/index.php'), null, ['errno' => 28, 'request_size' => 150]);
            throw new ConnectionException('Unknown result', 0, $transport);
        });
        $data = $this->payload($member, $fee, 100);
        $this->actingAs($admin)->postJson('/api/challans', $data)->assertConflict()->assertJsonPath('challan.sap_status', 'verification_required');
        $this->postJson('/api/challans', $data)->assertConflict();
        $this->assertSame(1, $attempts);
    }

    public static function phpAmbiguousResponses(): array
    {
        return ['empty success' => [200, ''], 'server error' => [500, 'Unavailable'], 'redirect' => [302, 'Redirect']];
    }

    #[DataProvider('phpAmbiguousResponses')]
    public function test_http_responses_do_not_trigger_fallback(int $status, string $body): void
    {
        [$admin, $member, $fee] = $this->actors();
        config(['services.sap.challan_url' => 'http://public.test/sapchallan/index.php', 'services.sap.challan_fallback_url' => 'http://internal.test/sapchallan/index.php']);
        Http::fake(['*' => Http::response($body, $status)]);
        $response = $this->actingAs($admin)->postJson('/api/challans', $this->payload($member, $fee, 100));
        $this->assertContains($response->status(), [409, 502]);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'http://public.test/sapchallan/index.php'));
        $this->assertNotSame('posted', Challan::first()->sap_status);
    }

    private function actors(): array
    {
        $complex = $this->complex();
        $member = Member::create([
            'sports_complex_id' => $complex->id,
            'customer_no' => '2501',
            'membership_no' => 'MR-1600-II-2381',
            'full_name' => 'Tahira M. Ashraf Jamil',
            'cnic' => '35202-2256163-0',
            'phone' => '0321-7775292',
            'address' => 'House 1, Lahore',
            'reconciliation_account' => '260108',
            'status' => 'active',
        ]);

        return [User::factory()->create(['role' => 'admin']), $member, $this->fee()];
    }

    private function complex(string $code = 'CW', ?string $center = '21070001'): SportsComplex
    {
        return SportsComplex::create(['name' => $code.' Sports Complex', 'code' => $code, 'cost_center' => $center, 'profit_center' => $center]);
    }

    private function fee(string $code = 'MEMBERSHIP', string $name = 'Membership Fee', ?string $gl = '0000610310'): FeeType
    {
        return FeeType::create(['code' => $code, 'name' => $name, 'gl_account' => $gl, 'reconciliation_account' => '260108', 'active' => true]);
    }

    private function payload(Member $member, FeeType $fee, float $amount, string $description = 'Membership Fee'): array
    {
        return [
            'member_id' => $member->id,
            'idempotency_key' => (string) Str::uuid(),
            'due_on' => now()->addWeek()->toDateString(),
            'charges' => [['fee_type_id' => $fee->id, 'description' => $description, 'amount' => $amount]],
        ];
    }
}
