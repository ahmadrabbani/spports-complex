<?php

namespace App\Console\Commands;

use App\Models\MemberTransaction;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class VerifySapLedgerPayments extends Command
{
    protected $signature = 'sap:verify-ledger-payments {--month=2026-10} {--limit=0} {--run=} {--through= : Verification cutoff in YYYY-MM-DD}';

    protected $description = 'Fresh read-only SAP inquiries with encrypted audit history and conservative ledger payment updates';

    public static function classify(?int $http, string $body): array
    {
        $value = trim($body);
        if ($http === null || $http < 200 || $http >= 300) {
            return ['verification_error', null];
        }
        // The observed SAP endpoint returns a plain 10-digit clearing document, surrounded by whitespace.
        if (preg_match('/^\d{10}$/D', $value)) {
            return ['paid', $value];
        }

        return ['verification_unknown', null];
    }

    public function handle(): int
    {
        $month = (string) $this->option('month');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) {
            $this->error('Use YYYY-MM');

            return 1;
        }
        $through = (string) ($this->option('through') ?: now()->toDateString());
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $through);
        if (! $date || $date->format('Y-m-d') !== $through) {
            $this->error('Use YYYY-MM-DD for --through');

            return 1;
        }
        $run = $this->option('run') ?: (string) Str::uuid();
        if (! Str::isUuid($run)) {
            return 1;
        }
        $q = MemberTransaction::where('document_type', 'DR')->whereBetween('document_date', [$month.'-01', min($through, date('Y-m-t', strtotime($month.'-01'))).' 23:59:59'])->where('date_is_anomalous', false)->whereNotNull('sap_verification_number')->whereNotExists(fn ($b) => $b->selectRaw('1')->from('sap_ledger_payment_checks')->whereColumn('member_transaction_id', 'member_transactions.id')->where('run_id', $run));
        $ids = $q->orderBy('id')->pluck('id');
        if ((int) $this->option('limit') > 0) {
            $ids = $ids->take((int) $this->option('limit'));
        }
        $this->info('Run '.$run.'; records '.$ids->count());
        $done = 0;
        foreach ($ids->chunk(10) as $chunk) {
            $started = microtime(true);
            $rows = MemberTransaction::whereIn('id', $chunk)->get();
            foreach ($rows as $row) {
                $expected = (string) $row->document_no.(string) $row->company_code.$row->document_date->format('Y');
                if (! preg_match('/^[0-9]{18}$/D', (string) $row->sap_verification_number) || $row->sap_verification_number !== $expected) {
                    $this->error('Invalid 18-digit verification code on ledger record '.$row->id.'. No batch was sent.');

                    return 1;
                }
            }
            $responses = Http::pool(function ($pool) use ($rows) {
                $requests = [];
                foreach ($rows as $row) {
                    $requests[] = $pool->as((string) $row->id)->connectTimeout(5)->timeout(15)->get(config('services.sap.verification_url'), [config('services.sap.verification_parameter', 'challan') => $row->sap_verification_number]);
                }

                return $requests;
            });
            DB::transaction(function () use ($responses, $rows, $run) {
                foreach ($rows as $row) {
                    $response = $responses[(string) $row->id] ?? null;
                    $http = $response instanceof Response ? $response->status() : null;
                    $body = $http === null ? 'Connection unavailable' : $response->body();
                    [$result,$document] = self::classify($http, $body);
                    DB::table('sap_ledger_payment_checks')->insert(['member_transaction_id' => $row->id, 'run_id' => $run, 'challan' => $row->sap_verification_number, 'result' => $result, 'http_status' => $http, 'response_payload' => Crypt::encryptString(json_encode(['body' => mb_substr($body, 0, 50000)])), 'created_at' => now(), 'updated_at' => now()]);
                    $updates = ['sap_payment_checked_at' => now(), 'sap_payment_status' => $result];
                    if ($document) {
                        $updates['sap_payment_document'] = $document;
                    }
                    // Unknown/error inquiries preserve an earlier confirmed paid state.
                    $current = MemberTransaction::lockForUpdate()->findOrFail($row->id);
                    if ($current->sap_payment_status === 'paid' && $result !== 'paid') {
                        $updates['sap_payment_status'] = 'paid';
                    }
                    $row->update($updates);
                }
            });
            $done += $rows->count();
            if ($done % 100 === 0 || $done === $ids->count()) {
                $this->info('Checked '.$done.'/'.$ids->count());
            }
            $remaining = 1.0 - (microtime(true) - $started);
            if ($remaining > 0) {
                usleep((int) ($remaining * 1000000));
            }
        }
        $this->line(json_encode(DB::table('sap_ledger_payment_checks')->where('run_id', $run)->selectRaw('result, count(*) as total')->groupBy('result')->get()));

        return 0;
    }
}
