<?php

namespace Tests\Feature;

use App\Models\FeeType;
use App\Models\Member;
use App\Models\MemberCharge;
use App\Models\SportsComplex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportMemberReceivablesTest extends TestCase
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

    public function test_receivables_import_is_dry_run_safe_and_idempotent(): void
    {
        $complex = SportsComplex::create(['name' => 'Gulberg Sports Complex', 'code' => 'GB']);
        $familyFee = FeeType::create(['code' => 'FM_GENERAL_PUBLIC', 'name' => 'Family', 'gl_account' => '260108', 'active' => true]);
        $individualFee = FeeType::create(['code' => 'IM_GENERAL_PUBLIC', 'name' => 'Individual', 'gl_account' => '260110', 'active' => true]);
        $family = Member::create(['sports_complex_id' => $complex->id, 'customer_no' => '2400000001', 'full_name' => 'Family Member', 'reconciliation_account' => '260108']);
        $individual = Member::create(['sports_complex_id' => $complex->id, 'customer_no' => '2400000002', 'full_name' => 'Individual Member', 'reconciliation_account' => '260110']);
        $file = $this->workbook();

        $this->artisan('members:import-receivables', ['files' => [$file], '--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Dry run: 2 rows, PKR 12,000.00; 2 created');
        $this->assertDatabaseCount('member_charges', 0);

        $this->artisan('members:import-receivables', ['files' => [$file]])
            ->assertSuccessful()
            ->expectsOutputToContain('2 rows, PKR 12,000.00; 2 created, 0 updated, 0 unchanged.');
        $this->assertDatabaseCount('member_charges', 2);
        $this->assertDatabaseHas('member_charges', [
            'member_id' => $family->id,
            'fee_type_id' => $familyFee->id,
            'amount' => 7000,
            'description' => 'Fee Oct-26',
            'status' => 'outstanding',
            'source_id' => 'REC-2026-10-GB-2400000001',
        ]);
        $this->assertDatabaseHas('member_charges', [
            'member_id' => $individual->id,
            'fee_type_id' => $individualFee->id,
            'amount' => 5000,
        ]);
        $this->assertSame('family', MemberCharge::where('member_id', $family->id)->firstOrFail()->source_payload['member_type']);

        $this->artisan('members:import-receivables', ['files' => [$file]])
            ->assertSuccessful()
            ->expectsOutputToContain('0 created, 0 updated, 2 unchanged.');
        $this->assertDatabaseCount('member_charges', 2);
    }

    public function test_import_fails_atomically_when_member_is_in_the_wrong_complex(): void
    {
        $gulberg = SportsComplex::create(['name' => 'Gulberg Sports Complex', 'code' => 'GB']);
        $other = SportsComplex::create(['name' => 'Other Sports Complex', 'code' => 'XX']);
        FeeType::create(['code' => 'FM_GENERAL_PUBLIC', 'name' => 'Family', 'gl_account' => '260108', 'active' => true]);
        FeeType::create(['code' => 'IM_GENERAL_PUBLIC', 'name' => 'Individual', 'gl_account' => '260110', 'active' => true]);
        Member::create(['sports_complex_id' => $gulberg->id, 'customer_no' => '2400000001', 'full_name' => 'Family Member', 'reconciliation_account' => '260108']);
        Member::create(['sports_complex_id' => $other->id, 'customer_no' => '2400000002', 'full_name' => 'Wrong Complex', 'reconciliation_account' => '260110']);

        $this->artisan('members:import-receivables', ['files' => [$this->workbook()]])
            ->assertFailed()
            ->expectsOutputToContain('does not belong to Gulberg Sports Complex');
        $this->assertDatabaseCount('member_charges', 0);
    }

    private function workbook(): string
    {
        $spreadsheet = new Spreadsheet;
        $family = $spreadsheet->getActiveSheet();
        $family->setTitle('Family');
        $family->fromArray([
            ['Sr No.', 'Account', 'Name', 'Form No', 'Amount', 'Pending Fee'],
            [1, '2400000001', 'Family Member', 'F-1', 7000, 'Fee Oct-26'],
        ]);
        $individual = $spreadsheet->createSheet();
        $individual->setTitle('Individual');
        $individual->fromArray([
            ['Sr No.', 'Account', 'Name', 'Form No', 'Amount', 'Pending Fee'],
            [1, '2400000002', 'Individual Member', 'I-1', 5000, 'Fee Oct-26'],
        ]);
        $file = sys_get_temp_dir().'/Gulberg Receivables '.uniqid('', true).'.xlsx';
        (new Xlsx($spreadsheet))->save($file);
        $spreadsheet->disconnectWorksheets();
        $this->temporaryFiles[] = $file;

        return $file;
    }
}
