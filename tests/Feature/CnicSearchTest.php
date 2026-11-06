<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentPurpose;
use App\Models\BankTransaction;
use App\Models\Challan;
use App\Models\Member;
use App\Models\SportsComplex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CnicSearchTest extends TestCase
{
    use RefreshDatabase;

    public static function formats(): array
    {
        return [
            'formatted input, raw stored' => ['3520200000001', '35202-0000000-1'],
            'raw input, formatted stored' => ['35202-0000000-1', '3520200000001'],
            'leading trailing whitespace' => ['3520200000001', '  35202-0000000-1  '],
            'spaces in input' => ['3520200000001', '35202 0000000 1'],
            'spaces stored' => ['35202 0000000 1', '35202-0000000-1'],
            'copy pasted unicode dashes' => ['3520200000001', '35202–0000000–1'],
            'nonbreaking spaces stored' => ["35202\u{00A0}0000000\u{00A0}1", '3520200000001'],
            'partial digits across separators' => ['35202-0000000-1', '35202000'],
            'partial formatted input' => ['3520200000001', '35202-000'],
        ];
    }

    #[DataProvider('formats')]
    public function test_supported_formats_match_without_modifying_stored_cnic(string $stored, string $search): void
    {
        [$u,$member] = $this->fixtures($stored);
        $this->actingAs($u)->getJson('/api/members?'.http_build_query(['q' => $search]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $member->id)->assertJsonPath('data.0.masked_cnic', '35202-*******-1');
        $this->assertSame($stored, $member->fresh()->cnic);
    }

    public function test_full_cnic_is_exact_and_does_not_match_an_invalid_extra_digit_record(): void
    {
        [$u,$m] = $this->fixtures('3520200000001');
        Member::create(['sports_complex_id' => $m->sports_complex_id, 'customer_no' => 'INVALID', 'full_name' => 'Invalid identity', 'cnic' => '35202000000019']);
        $this->actingAs($u)->getJson('/api/members?q=35202-0000000-1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $m->id);
    }

    public function test_numeric_normalization_cannot_escape_complex_scope_or_match_digits_in_a_name_query(): void
    {
        [$u,$m] = $this->fixtures('3520200000001');
        $other = SportsComplex::create(['name' => 'Other complex', 'code' => 'OTHER']);
        Member::create(['sports_complex_id' => $other->id, 'customer_no' => 'OTHER-CUSTOMER', 'full_name' => 'Other member', 'cnic' => '3520200000001']);
        $this->actingAs($u)->getJson('/api/members?q=35202-0000000-1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $m->id);
        $this->getJson('/api/members?'.http_build_query(['q' => '35202-0000000-1', 'complex_id' => $other->id]))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/members?q=Unrelated3520200000001')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_name_customer_membership_and_phone_search_remain_available(): void
    {
        [$u,$m] = $this->fixtures('3520200000001');
        $this->actingAs($u);
        foreach (['Synthetic Search', 'LOOKUP-001', 'MEMBER-CARD-01', '03001234567'] as $q) {
            $this->getJson('/api/members?'.http_build_query(['q' => $q]))->assertOk()->assertJsonPath('data.0.id', $m->id);
        }
        $this->getJson('/api/members?q=no-such-member')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_challan_list_supports_formatted_cnic_and_keeps_complex_scope(): void
    {
        [$u,$m] = $this->fixtures('3520200000001');
        $other = SportsComplex::create(['name' => 'Other', 'code' => 'B']);
        foreach ([[$m, $m->sports_complex_id, 'OWN'], [Member::create(['sports_complex_id' => $other->id, 'customer_no' => 'OTHER', 'full_name' => 'Other', 'cnic' => '3520200000001']), $other->id, 'OTHER']] as [$member,$complex,$no]) {
            Challan::create(['member_id' => $member->id, 'sports_complex_id' => $complex, 'challan_no' => $no, 'purpose' => 'Synthetic fee', 'amount' => 1000, 'issued_on' => today(), 'due_on' => today()->addWeek(), 'status' => 'issued', 'sap_status' => 'posted']);
        }
        $this->actingAs($u)->getJson('/api/challans?q=35202-0000000-1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.challan_no', 'OWN');
    }

    public function test_search_rejects_arrays_and_oversized_input(): void
    {
        [$u] = $this->fixtures('3520200000001');
        $this->actingAs($u);
        $this->getJson('/api/members?'.http_build_query(['q' => ['3520200000001']]))->assertUnprocessable();
        $this->getJson('/api/members?'.http_build_query(['q' => str_repeat('1', 256)]))->assertUnprocessable();
    }

    public function test_bank_and_appointment_search_use_the_same_cnic_matching(): void
    {
        [$u,$m] = $this->fixtures('3520200000001');
        $c = Challan::create(['member_id' => $m->id, 'sports_complex_id' => $m->sports_complex_id, 'challan_no' => 'SEARCH-CH', 'purpose' => 'Synthetic fee', 'amount' => 1000, 'issued_on' => today(), 'due_on' => today()->addWeek(), 'status' => 'issued', 'sap_status' => 'posted']);
        BankTransaction::create(['public_id' => (string) Str::uuid(), 'challan_id' => $c->id, 'member_id' => $m->id, 'order_id' => 'SEARCH-ORDER', 'amount' => 1000, 'initiation_key' => (string) Str::uuid()]);
        $this->actingAs($u)->getJson('/api/bank/transactions?q=35202-0000000-1')->assertOk()->assertJsonCount(1, 'transactions.data');
        $person = User::factory()->create(['role' => 'member', 'cnic' => '3520200000001']);
        $purpose = AppointmentPurpose::create(['name' => 'Synthetic purpose']);
        Appointment::create(['public_id' => (string) Str::uuid(), 'appointment_no' => 'SEARCH-APPOINTMENT', 'user_id' => $person->id, 'sports_complex_id' => $m->sports_complex_id, 'appointment_purpose_id' => $purpose->id, 'appointment_date' => today(), 'appointment_time' => '10:00']);
        $this->getJson('/api/admin/appointments?q=35202-0000000-1')->assertOk()->assertJsonCount(1, 'appointments.data');
    }

    private function fixtures(string $cnic): array
    {
        $complex = SportsComplex::create(['name' => 'Synthetic search complex', 'code' => 'SEARCH']);
        $u = User::factory()->create(['role' => 'admin', 'sports_complex_id' => $complex->id, 'is_complex_admin' => true]);
        $m = Member::create(['sports_complex_id' => $complex->id, 'customer_no' => 'LOOKUP-001', 'membership_no' => 'MEMBER-CARD-01', 'full_name' => 'Synthetic Search Member', 'phone' => '03001234567', 'cnic' => $cnic]);

        return [$u, $m];
    }
}
