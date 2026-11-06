<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\MembershipCategory;
use App\Models\SportsComplex;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class ImportZcmdMembers extends Command
{
    protected $signature = 'members:import-zcmd {files* : XLSX files to import} {--dry-run}';

    protected $description = 'Import ZCMD sports-complex member master files idempotently by customer number';

    private const COMPLEXES = [
        'canal west' => ['Canal Bank Sports Complex', 'CW', 'UD25'],
        'china scheme' => ['China Scheme Sports Complex', 'CS', 'UD27'],
        'gulberg' => ['Gulberg Sports Complex', 'GB', 'UD24'],
        'islamia ground' => ['Islamia Ground Sports Complex', 'IG', 'UD22'],
        'johar town' => ['Johar Town Sports Complex', 'JT', 'UD05'],
        'minar-e-pakistan' => ['Minar-e-Pakistan Sports Complex', 'MP', 'UD26'],
        'sabzazar' => ['Sabzazar Sports Complex', 'SZ', 'UD21'],
        'tajpura' => ['Tajpura Sports Complex', 'TP', 'UD23'],
    ];

    public function handle(): int
    {
        $created = $updated = $skipped = 0;
        DB::beginTransaction();
        try {
            foreach ($this->argument('files') as $file) {
                if (! is_file($file)) {
                    $this->error("Missing file: {$file}");
                    DB::rollBack();

                    return self::FAILURE;
                }
                $site = strtolower(trim(preg_replace('/^(?:ZCMD|master data)\s+|\.xlsx$/i', '', basename($file))));
                if (! isset(self::COMPLEXES[$site])) {
                    $this->warn("Skipped unknown complex file: {$file}");

                    continue;
                }
                [$name,$code,$group] = self::COMPLEXES[$site];
                $complex = SportsComplex::firstOrCreate(['code' => $code], ['name' => $name, 'account_group' => $group]);
                $sheet = IOFactory::load($file)->getActiveSheet();
                foreach ($sheet->toArray(null, true, true, false) as $i => $row) {
                    if ($i === 0 || blank($row[0] ?? null)) {
                        continue;
                    }
                    $customer = trim((string) $row[0]);
                    $date = $this->date($row[17] ?? null);
                    $changedDate = $this->date($row[25] ?? null);
                    $changedAt = $changedDate ? Carbon::parse($changedDate.' '.($row[26] ?: '00:00:00')) : null;
                    $data = [
                        'sports_complex_id' => $complex->id, 'membership_no' => $this->null($row[20] ?? null), 'full_name' => trim((string) $row[4]),
                        'title' => $this->null($row[14] ?? null), 'cnic' => $this->digits($row[22] ?? null), 'phone' => $this->null($row[10] ?? null),
                        'address' => $this->null($row[9] ?? null), 'postal_code' => $this->null($row[6] ?? null), 'country' => $this->null($row[3] ?? null) ?: 'PK',
                        'region' => $this->null($row[7] ?? null), 'reconciliation_account' => $this->null($row[2] ?? null),
                        'source_address_id' => $this->null($row[11] ?? null), 'source_created_by' => $this->null($row[18] ?? null),
                        'source_created_at' => $date, 'source_changed_at' => $changedAt, 'confirmation_status' => $this->null($row[24] ?? null),
                        'status' => (string) ($row[24] ?? '') === '1' ? 'active' : 'inactive', 'source_data' => ['company_code' => $row[1] ?? null, 'account_group' => $row[19] ?? null, 'planning_group' => $row[28] ?? null],
                    ];
                    $member = Member::withTrashed()->firstOrNew(['customer_no' => $customer]);
                    $wasNew = ! $member->exists;
                    // A later source export must not undo an audited freeze or administrator category mapping.
                    if ($member->exists && ($member->status === 'frozen' || $member->reconciliation_account === '260111' || $member->membership_category_id)) {
                        unset($data['reconciliation_account']);
                        if ($member->status === 'frozen' || $member->reconciliation_account === '260111') {
                            unset($data['status']);
                        }
                    }
                    if (! $member->membership_category_id && ($data['reconciliation_account'] ?? null) !== '260111') {
                        $candidates = MembershipCategory::where('active', true)->where('is_freeze', false)->whereHas('account', fn ($q) => $q->where('code', $data['reconciliation_account'] ?? null)->where('active', true))->get();
                        if ($candidates->count() === 1) {
                            $data['membership_category_id'] = $candidates->first()->id;
                        }
                    }
                    $member->accountChangeReason = 'SAP customer master import';
                    $member->fill($data);
                    if ($member->trashed()) {
                        $member->restore();
                    }
                    if (! $member->isDirty()) {
                        $skipped++;

                        continue;
                    }
                    $member->save();
                    $wasNew ? $created++ : $updated++;
                }
                $this->info('Read '.ucwords($site));
            }
            $this->option('dry-run') ? DB::rollBack() : DB::commit();
            $this->info(($this->option('dry-run') ? 'Dry run: ' : '')."{$created} created, {$updated} updated, {$skipped} unchanged.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function null($v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    private function digits($v): ?string
    {
        $v = preg_replace('/\D/', '', (string) $v);

        return $v === '' ? null : $v;
    }

    private function date($v): ?string
    {
        if (blank($v)) {
            return null;
        }
        if (is_numeric($v)) {
            return Date::excelToDateTimeObject($v)->format('Y-m-d');
        }

        return Carbon::parse($v)->format('Y-m-d');
    }
}
