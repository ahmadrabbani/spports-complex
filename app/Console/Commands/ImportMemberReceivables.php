<?php

namespace App\Console\Commands;

use App\Models\FeeType;
use App\Models\Member;
use App\Models\MemberCharge;
use App\Models\SportsComplex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportMemberReceivables extends Command
{
    protected $signature = 'members:import-receivables {files* : Receivables/recovery XLSX files to import} {--dry-run}';

    protected $description = 'Import member receivable balances idempotently as outstanding charges';

    private const CYCLE = '2026-10';

    private const FILE_CONFIG = [
        'gulberg' => ['complex' => 'GB', 'sheets' => ['family' => 'family', 'individual' => 'individual']],
        'minar-e-pakistan' => ['complex' => 'MP', 'sheets' => ['family list' => 'family', 'indv list' => 'individual']],
        'islamia ground' => ['complex' => 'IG', 'sheets' => ['family list' => 'family', 'indv list' => 'individual']],
        'tajpura' => ['complex' => 'TP', 'sheets' => ['family' => 'family', 'indv' => 'individual']],
    ];

    public function handle(): int
    {
        $created = $updated = $unchanged = 0;
        $grandRows = 0;
        $grandAmount = 0.0;

        DB::beginTransaction();

        try {
            foreach ($this->argument('files') as $file) {
                if (! is_file($file)) {
                    throw new \RuntimeException("Missing file: {$file}");
                }

                $config = $this->configFor($file);
                $complex = SportsComplex::where('code', $config['complex'])->firstOrFail();
                $spreadsheet = IOFactory::load($file);
                $fileRows = 0;
                $fileAmount = 0.0;

                foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
                    $sheetName = strtolower(trim($sheet->getTitle()));
                    if (! isset($config['sheets'][$sheetName])) {
                        continue;
                    }

                    $memberType = $config['sheets'][$sheetName];
                    $rows = $sheet->toArray(null, true, true, false);
                    $headers = array_map(fn ($value) => $this->header($value), array_shift($rows) ?: []);
                    $columns = $this->columns($headers);

                    foreach ($rows as $rowNumber => $row) {
                        $customerNo = $this->value($row, $columns['account']);
                        if ($customerNo === null || strtolower($customerNo) === 'total') {
                            continue;
                        }

                        $amount = $this->amount($row[$columns['amount']] ?? null);
                        if ($amount <= 0) {
                            throw new \RuntimeException("Invalid amount for account {$customerNo} in {$sheet->getTitle()} row ".($rowNumber + 2));
                        }

                        $member = Member::where('customer_no', $customerNo)->first();
                        if (! $member) {
                            throw new \RuntimeException("Member account {$customerNo} was not found.");
                        }
                        if ((int) $member->sports_complex_id !== (int) $complex->id) {
                            throw new \RuntimeException("Member account {$customerNo} does not belong to {$complex->name}.");
                        }
                        if (! $member->reconciliation_account) {
                            throw new \RuntimeException("Member account {$customerNo} has no reconciliation account.");
                        }

                        $feeType = FeeType::where('gl_account', $member->reconciliation_account)->where('active', true)->first();
                        if (! $feeType) {
                            throw new \RuntimeException("No active fee type is configured for member {$customerNo} account {$member->reconciliation_account}.");
                        }

                        $description = $this->value($row, $columns['description']) ?: 'Outstanding membership fee Oct-26';
                        $sourceId = 'REC-'.self::CYCLE.'-'.$complex->code.'-'.$customerNo;
                        $charge = MemberCharge::firstOrNew([
                            'source_type' => 'receivables_import',
                            'source_id' => $sourceId,
                        ]);
                        $isNew = ! $charge->exists;
                        $charge->fill([
                            'member_id' => $member->id,
                            'membership_id' => null,
                            'fee_type_id' => $feeType->id,
                            'description' => $description,
                            'amount' => $amount,
                            'gl_account' => $feeType->gl_account,
                            'reconciliation_account' => $member->reconciliation_account,
                            'status' => 'outstanding',
                            'due_on' => null,
                            'source_payload' => [
                                'cycle' => self::CYCLE,
                                'source_file' => basename($file),
                                'source_sheet' => $sheet->getTitle(),
                                'source_row' => $rowNumber + 2,
                                'member_type' => $memberType,
                                'form_no' => $this->value($row, $columns['form']),
                                'member_name' => $this->value($row, $columns['name']),
                                'document_no' => $this->value($row, $columns['document']),
                            ],
                        ]);

                        if (! $charge->isDirty()) {
                            $unchanged++;
                        } else {
                            $charge->save();
                            $isNew ? $created++ : $updated++;
                        }

                        $fileRows++;
                        $fileAmount += $amount;
                    }
                }

                if ($fileRows === 0) {
                    throw new \RuntimeException('No receivable rows were found in '.basename($file).'.');
                }

                $grandRows += $fileRows;
                $grandAmount += $fileAmount;
                $this->info(sprintf('%s: %d rows, PKR %s', $complex->name, $fileRows, number_format($fileAmount, 2)));
            }

            $this->option('dry-run') ? DB::rollBack() : DB::commit();
            $prefix = $this->option('dry-run') ? 'Dry run: ' : '';
            $this->info(sprintf('%s%d rows, PKR %s; %d created, %d updated, %d unchanged.', $prefix, $grandRows, number_format($grandAmount, 2), $created, $updated, $unchanged));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            DB::rollBack();
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function configFor(string $file): array
    {
        $name = strtolower(basename($file));
        foreach (self::FILE_CONFIG as $needle => $config) {
            if (str_contains($name, $needle)) {
                return $config;
            }
        }

        throw new \RuntimeException('Unable to identify the sports complex from '.basename($file).'.');
    }

    private function columns(array $headers): array
    {
        $find = function (array $names, bool $required = true) use ($headers): ?int {
            foreach ($headers as $index => $header) {
                if (in_array($header, $names, true)) {
                    return $index;
                }
            }
            if ($required) {
                throw new \RuntimeException('Required spreadsheet column is missing: '.implode(' / ', $names));
            }

            return null;
        };

        return [
            'account' => $find(['account']),
            'amount' => $find(['amount', 'amount in local currency']),
            'description' => $find(['pending fee', 'text']),
            'form' => $find(['form no', 'form no.', 'form#'], false),
            'name' => $find(['name', 'member name'], false),
            'document' => $find(['document number'], false),
        ];
    }

    private function header($value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', (string) $value)));
    }

    private function value(array $row, ?int $column): ?string
    {
        if ($column === null || ! isset($row[$column])) {
            return null;
        }

        $value = trim((string) $row[$column]);

        return $value === '' ? null : preg_replace('/\.0$/', '', $value);
    }

    private function amount($value): float
    {
        return round((float) str_replace([',', ' '], '', (string) $value), 2);
    }
}
