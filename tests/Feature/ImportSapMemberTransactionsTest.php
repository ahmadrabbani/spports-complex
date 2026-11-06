<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\SportsComplex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImportSapMemberTransactionsTest extends TestCase
{
    use RefreshDatabase;

    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_sap_ledger_import_is_source_backed_dry_run_safe_and_idempotent(): void
    {
        $complex = SportsComplex::create(['name' => 'Gulberg Sports Complex', 'code' => 'GB']);
        $member = Member::create([
            'sports_complex_id' => $complex->id,
            'customer_no' => '2400000000',
            'full_name' => 'Qasim Shaukat Khan',
            'status' => 'active',
        ]);
        $file = $this->ledger();

        $this->artisan('members:import-sap-transactions', ['files' => [$file], '--dry-run' => true, '--strict' => true])
            ->assertSuccessful()->expectsOutputToContain('Dry run: 2 rows parsed; 2 created');
        $this->assertDatabaseCount('member_transactions', 0);

        $this->artisan('members:import-sap-transactions', ['files' => [$file], '--strict' => true])
            ->assertSuccessful()->expectsOutputToContain('2 rows parsed; 2 created, 0 duplicates, 0 unmatched.');
        $this->assertDatabaseCount('member_transactions', 2);
        $this->assertDatabaseHas('member_transactions', [
            'member_id' => $member->id,
            'document_no' => '1700164279',
            'document_type' => 'DR',
            'amount' => 10000,
            'description' => 'Fee Oct-26',
        ]);
        $this->assertDatabaseHas('member_transactions', [
            'member_id' => $member->id,
            'document_no' => '1800001438',
            'document_type' => 'DZ',
            'amount' => -10000,
            'clearing_document' => '1800001438',
        ]);

        $this->artisan('members:import-sap-transactions', ['files' => [$file], '--strict' => true])
            ->assertSuccessful()->expectsOutputToContain('0 created, 2 duplicates, 0 unmatched.');
        $this->assertDatabaseCount('member_transactions', 2);
    }

    public function test_dashboard_and_member_ledger_use_imported_sap_history(): void
    {
        $complex = SportsComplex::create(['name' => 'Gulberg Sports Complex', 'code' => 'GB', 'is_active' => true]);
        $member = Member::create([
            'sports_complex_id' => $complex->id,
            'customer_no' => '2400000000',
            'full_name' => 'Qasim Shaukat Khan',
            'status' => 'active',
            'source_created_at' => now(),
        ]);
        $this->artisan('members:import-sap-transactions', ['files' => [$this->ledger()], '--strict' => true])->assertSuccessful();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('metrics.sap_transactions', 2)
            ->assertJsonPath('metrics.sap_balance', fn ($value) => (float) $value === 0.0)
            ->assertJsonPath('recent_transactions.0.document_no', '1700164279');

        $portal = User::factory()->create([
            'role' => 'member',
            'member_id' => $member->id,
            'sports_complex_id' => $complex->id,
            'must_change_password' => false,
        ]);
        $this->actingAs($portal)->getJson('/api/portal/dashboard')->assertOk()
            ->assertJsonPath('account_summary.source', 'sap_customer_ledger')
            ->assertJsonPath('account_summary.transaction_count', 2)
            ->assertJsonPath('account_summary.total_charged', '10000.00')
            ->assertJsonPath('account_summary.total_paid', '10000.00')
            ->assertJsonPath('account_summary.balance', '0.00');
        $this->actingAs($portal)->getJson('/api/portal/ledger')->assertOk()
            ->assertJsonPath('source', 'sap_customer_ledger')
            ->assertJsonPath('summary.total_charged', '10000.00')
            ->assertJsonPath('summary.total_paid', '10000.00')
            ->assertJsonPath('summary.balance', '0.00')
            ->assertJsonCount(2, 'transactions');
    }

    private function ledger(): string
    {
        $file = sys_get_temp_dir().'/gulberg-ledger-'.Str::uuid().'.txt';
        file_put_contents($file, <<<'TEXT'
Customer                 2400000000
Company Code             2000

Name                     Qasim Shaukat Khan
City                     Lahore

       St Assignment      DocumentNo Typ Doc..Date     S DD   Amt in loc.cur. LCurr Clrng doc. Text

         1700164279       1700164279 DR   06.10.2026               10,000.00   PKR             Fee Oct-26
         1800001438       1800001438 DZ   21.07.2025               10,000.00- PKR    1800001438
TEXT);
        $this->temporaryFiles[] = $file;

        return $file;
    }
}
