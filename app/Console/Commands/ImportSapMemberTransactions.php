<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\MemberTransaction;
use App\Models\SportsComplex;
use App\Services\SapChallanNumber;
use App\Services\SapCustomerLedgerParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportSapMemberTransactions extends Command
{
    protected $signature = 'members:import-sap-transactions {files* : SAP customer-ledger PDF or extracted text files} {--dry-run} {--strict}';

    protected $description = 'Import source-backed SAP member ledger transactions idempotently';

    private const FILE_COMPLEXES = [
        'gulberg' => 'GB',
        'china scheme' => 'CS',
        'islamia' => 'IG',
        'johar town' => 'JT',
        'minare pakistan' => 'MP',
        'minar-e-pakistan' => 'MP',
        'sabzazar' => 'SZ',
        'tajpura' => 'TP',
    ];

    public function handle(SapCustomerLedgerParser $parser): int
    {
        $created = $duplicates = $unmatched = $rows = 0;
        DB::beginTransaction();

        try {
            foreach ($this->argument('files') as $file) {
                $complex = $this->complexFor($file);
                $members = Member::query()->where('sports_complex_id', $complex->id)->pluck('id', 'customer_no');
                $batch = [];
                $fileRows = $fileCreated = $fileUnmatched = 0;

                foreach ($parser->parse($file) as $transaction) {
                    $rows++;
                    $fileRows++;
                    $memberId = $members[$transaction['customer_no']] ?? null;
                    if (! $memberId) {
                        $unmatched++;
                        $fileUnmatched++;
                        if ($this->option('strict')) {
                            throw new \RuntimeException("Member {$transaction['customer_no']} from ".basename($file)." was not found in {$complex->name}.");
                        }

                        continue;
                    }

                    $canonical = implode('|', [
                        $transaction['customer_no'], $transaction['assignment'], $transaction['document_no'],
                        $transaction['document_type'], $transaction['document_date'], $transaction['amount'],
                        $transaction['currency'], $transaction['clearing_document'], $transaction['description'],
                    ]);
                    $now = now();
                    $batch[] = [
                        ...$transaction,
                        'sap_verification_number' => $transaction['document_type'] === 'DR' && preg_match('/^\d{10}$/D', $transaction['document_no']) && preg_match('/^\d{4}$/D', (string) $transaction['company_code']) ? SapChallanNumber::make($transaction['document_no'], (string) $transaction['company_code'], substr($transaction['document_date'], 0, 4)) : null,
                        'member_id' => $memberId,
                        'sports_complex_id' => $complex->id,
                        'source_file' => basename($file),
                        'source_hash' => hash('sha256', $canonical),
                        'imported_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($batch) === 1000) {
                        $inserted = MemberTransaction::insertOrIgnore($batch);
                        $created += $inserted;
                        $fileCreated += $inserted;
                        $duplicates += count($batch) - $inserted;
                        $batch = [];
                    }
                }

                if ($batch) {
                    $inserted = MemberTransaction::insertOrIgnore($batch);
                    $created += $inserted;
                    $fileCreated += $inserted;
                    $duplicates += count($batch) - $inserted;
                }

                $this->info(sprintf('%s: %s parsed, %s imported, %s unmatched.', $complex->name, number_format($fileRows), number_format($fileCreated), number_format($fileUnmatched)));
            }

            $this->option('dry-run') ? DB::rollBack() : DB::commit();
            $prefix = $this->option('dry-run') ? 'Dry run: ' : '';
            $this->info(sprintf('%s%s rows parsed; %s created, %s duplicates, %s unmatched.', $prefix, number_format($rows), number_format($created), number_format($duplicates), number_format($unmatched)));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            DB::rollBack();
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function complexFor(string $file): SportsComplex
    {
        $name = strtolower(basename($file));
        foreach (self::FILE_COMPLEXES as $needle => $code) {
            if (str_contains($name, $needle)) {
                return SportsComplex::where('code', $code)->firstOrFail();
            }
        }

        throw new \RuntimeException('Unable to identify the sports complex from '.basename($file).'.');
    }
}
