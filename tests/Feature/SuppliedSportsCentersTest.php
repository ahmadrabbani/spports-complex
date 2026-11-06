<?php

namespace Tests\Feature;

use App\Models\FeeType;
use App\Models\Member;
use App\Models\SportsComplex;
use App\Models\User;
use App\Services\SapChallanService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SuppliedSportsCentersTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_supplied_center_creates_a_persisted_sap_challan_with_http_fake(): void
    {
        $this->seed(DatabaseSeeder::class);
        config(['services.payment_mode' => 'live', 'services.sap.challan_url' => 'http://sap.test/Challan']);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response("\n1700999999\n", 200)]);
        $user = User::factory()->create(['role' => 'admin']);
        $fee = FeeType::where('code', 'MONTHLY_FEE')->firstOrFail();
        $expected = ['CW' => '21070001', 'GB' => '21040001', 'SZ' => '21030002', 'IG' => '21050001', 'CS' => '21090001', 'MP' => '21080001', 'TP' => '21060001', 'PA' => '21100001', 'HS' => '22010001'];
        foreach (SportsComplex::all() as $complex) {
            $options = $complex->code === 'JT' ? $complex->sap_center_options : [null];
            foreach ($options as $index => $option) {
                $center = $expected[$complex->code] ?? ('2101000'.($index + 1));
                $member = Member::create(['sports_complex_id' => $complex->id, 'customer_no' => 'TEST-'.$complex->code.'-'.$index, 'membership_no' => 'TEST-'.$complex->code.'-'.$index, 'full_name' => 'Synthetic Applicant', 'cnic' => '3520200000000', 'phone' => '03000000000', 'address' => 'Synthetic QA address', 'reconciliation_account' => '260108', 'status' => 'active']);
                $challan = app(SapChallanService::class)->generate($member, [['fee_type_id' => $fee->id, 'amount' => 100]], (string) Str::uuid(), today()->addWeek()->toDateString(), $user, null, $option['id'] ?? null);
                $this->assertSame('posted', $challan->sap_status);
                $this->assertSame('1700999999', $challan->sap_document_no);
                $this->assertSame(str_pad($center, 10, '0', STR_PAD_LEFT), $challan->sap_payload['center']);
                $this->assertSame(str_pad($center, 9, '0', STR_PAD_LEFT), $challan->sap_payload['pcenter']);
                $this->assertDatabaseHas('challan_items', ['challan_id' => $challan->id, 'cost_center' => str_pad($center, 10, '0', STR_PAD_LEFT), 'profit_center' => str_pad($center, 9, '0', STR_PAD_LEFT)]);
            }
        }
        Http::assertSentCount(13);
    }
}
