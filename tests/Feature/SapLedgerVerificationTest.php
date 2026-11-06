<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\MemberTransaction;
use App\Models\SportsComplex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SapLedgerVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_check_persists_confirmation_and_resumed_run_skips_completed_records(): void
    {
        config(['services.sap.verification_parameter' => 'cid', 'services.sap.verification_url' => 'http://proxy.test/sapchallan/verify.php']);
        $c = SportsComplex::create(['name' => 'Test', 'code' => 'T']);
        $m = Member::create(['sports_complex_id' => $c->id, 'customer_no' => 'TEST', 'full_name' => 'Synthetic', 'status' => 'active']);
        $row = MemberTransaction::create(['member_id' => $m->id, 'sports_complex_id' => $c->id, 'customer_no' => 'TEST', 'company_code' => '2000', 'document_no' => '1700160703', 'document_type' => 'DR', 'document_date' => '2026-10-01', 'amount' => 1000, 'currency' => 'PKR', 'source_file' => 'synthetic', 'source_hash' => str_repeat('a', 64), 'imported_at' => now(), 'sap_verification_number' => '170016070320002026']);
        Http::fake(['*' => Http::sequence()->push("\n1800013055\n", 200)->push('  ', 200)]);
        $run = 'b6aa7c10-efc2-4c6d-b222-b491e3ce4897';
        $this->artisan('sap:verify-ledger-payments', ['--run' => $run])->assertSuccessful();
        $this->assertSame('paid', $row->fresh()->sap_payment_status);
        $this->assertSame('1800013055', $row->fresh()->sap_payment_document);
        $this->assertNull($row->fresh()->clearing_document);
        Http::assertSent(fn ($r) => $r['cid'] === '170016070320002026' && str_starts_with($r->url(), 'http://proxy.test/sapchallan/verify.php?'));
        $this->artisan('sap:verify-ledger-payments', ['--run' => $run])->assertSuccessful();
        $this->assertDatabaseCount('sap_ledger_payment_checks', 1);
        $this->artisan('sap:verify-ledger-payments', ['--run' => '2976b7f7-09c6-4bbb-9c4d-c5d89c8f1624'])->assertSuccessful();
        $this->assertSame('paid', $row->fresh()->sap_payment_status);
        $this->assertDatabaseHas('sap_ledger_payment_checks', ['result' => 'verification_unknown']);
    }

    public function test_explicit_cutoff_includes_client_date_but_excludes_future_and_anomalous_rows(): void
    {
        $c = SportsComplex::create(['name' => 'Test', 'code' => 'TT']);
        $m = Member::create(['sports_complex_id' => $c->id, 'customer_no' => 'TEST', 'full_name' => 'Synthetic', 'status' => 'active']);
        foreach ([['2026-11-01', false], ['2026-11-05', false], ['2026-11-06', false], ['2026-11-03', true]] as $i => [$date,$anomaly]) {
            $doc = '170016070'.$i;
            MemberTransaction::create(['member_id' => $m->id, 'sports_complex_id' => $c->id, 'customer_no' => 'TEST', 'company_code' => '2000', 'document_no' => $doc, 'document_type' => 'DR', 'document_date' => $date, 'amount' => 100, 'currency' => 'PKR', 'source_file' => 'synthetic', 'source_hash' => str_repeat((string) $i, 64), 'imported_at' => now(), 'date_is_anomalous' => $anomaly, 'sap_verification_number' => $doc.'20002026']);
        }
        Http::fake(['*' => Http::response(' ', 200)]);
        $this->artisan('sap:verify-ledger-payments', ['--month' => '2026-11', '--through' => '2026-11-05'])->assertSuccessful();
        Http::assertSentCount(2);
        $this->assertDatabaseCount('sap_ledger_payment_checks', 2);
    }
}
