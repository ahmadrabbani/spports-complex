<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\SportsComplex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffMemberLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_ledger_matches_member_portal_and_preserves_running_balances(): void
    {
        $complex = SportsComplex::create(['name' => 'Test Complex', 'code' => 'TT']);
        $member = Member::create(['sports_complex_id' => $complex->id, 'customer_no' => 'TEST', 'full_name' => 'Test Member', 'status' => 'active']);
        foreach ([['2026-10-01', 1000], ['2026-10-02', -400]] as $i => [$date, $amount]) {
            $member->transactions()->create(['sports_complex_id' => $complex->id, 'customer_no' => 'TEST', 'document_no' => 'DOC-'.$i, 'document_type' => $amount > 0 ? 'DR' : 'DZ', 'document_date' => $date, 'amount' => $amount, 'source_file' => 'synthetic.pdf', 'source_hash' => hash('sha256', (string) Str::uuid()), 'imported_at' => now()]);
        }
        $portal = User::factory()->create(['role' => 'member', 'member_id' => $member->id, 'must_change_password' => false]);
        $expected = $this->actingAs($portal)->getJson('/api/portal/ledger')->assertOk()->json();
        foreach (['admin', 'manager', 'operator'] as $role) {
            $staff = User::factory()->create(['role' => $role, 'sports_complex_id' => $complex->id]);
            $r = $this->actingAs($staff)->getJson('/api/members/'.$member->id.'/ledger')->assertOk()
                ->assertJsonPath('source', 'sap_customer_ledger')->assertJsonPath('summary.balance', '600.00')
                ->assertJsonPath('transactions.0.balance', '600.00')->assertJsonPath('transactions.1.balance', '1000.00')
                ->assertJsonMissingPath('challans');
            $this->assertSame($expected['transactions'], $r->json('transactions'));
            $this->assertSame($expected['summary'], $r->json('summary'));
        }
    }

    public function test_ledger_is_staff_only_and_respects_complex_scope_and_empty_accounts(): void
    {
        $a = SportsComplex::create(['name' => 'A', 'code' => 'AA']);
        $b = SportsComplex::create(['name' => 'B', 'code' => 'BB']);
        $member = Member::create(['sports_complex_id' => $a->id, 'customer_no' => 'TEST', 'full_name' => 'Test', 'status' => 'active']);
        $path = '/api/members/'.$member->id.'/ledger';
        $this->getJson($path)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'member', 'member_id' => $member->id]))->getJson($path)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'manager', 'sports_complex_id' => $b->id]))->getJson($path)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson($path)->assertOk()
            ->assertJsonPath('source', 'application_ledger')->assertJsonPath('summary.balance', '0.00')->assertJsonCount(0, 'transactions');
    }
}
