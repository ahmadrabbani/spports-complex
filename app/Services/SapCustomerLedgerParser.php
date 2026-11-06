<?php

namespace App\Services;

use Carbon\Carbon;
use Generator;
use RuntimeException;

class SapCustomerLedgerParser
{
    /** @return Generator<int, array<string, mixed>> */
    public function parse(string $file): Generator
    {
        if (! is_file($file)) {
            throw new RuntimeException("Missing file: {$file}");
        }

        [$stream, $process] = $this->open($file);
        $customerNo = $companyCode = null;
        $page = 1;

        try {
            while (($line = fgets($stream)) !== false) {
                if (str_contains($line, "\f")) {
                    $page += substr_count($line, "\f");
                    $line = ltrim($line, "\f");
                }

                if (preg_match('/^Customer\s+(\d+)\s*$/', $line, $match)) {
                    $customerNo = $match[1];
                    $companyCode = null;

                    continue;
                }
                if (preg_match('/^Company Code\s+(\d+)\s*$/', $line, $match)) {
                    $companyCode = $match[1];

                    continue;
                }
                if (! preg_match('/^\s*(\d{6,12})\s+(\d{6,12})\s+([A-Z0-9]{2})\s+(\d{2}\.\d{2}\.\d{4})\s+(.*?)\s*$/', $line, $row)) {
                    continue;
                }
                if (! $customerNo) {
                    throw new RuntimeException("Transaction found before a customer heading on page {$page}.");
                }
                if (! preg_match('/(?:^|\s)([0-9][0-9,]*\.\d{2})(-?)\s+([A-Z]{3})(?:\s+(\d{6,12}))?(?:\s+(.*))?$/', $row[5], $amount)) {
                    throw new RuntimeException("Unable to parse a transaction amount on page {$page}: ".trim($line));
                }

                $signedAmount = (float) str_replace(',', '', $amount[1]);
                if ($amount[2] === '-') {
                    $signedAmount *= -1;
                }

                $date = Carbon::createFromFormat('d.m.Y', $row[4]);
                yield [
                    'customer_no' => $customerNo,
                    'company_code' => $companyCode,
                    'assignment' => $row[1],
                    'document_no' => $row[2],
                    'document_type' => $row[3],
                    'document_date' => $date->toDateString(),
                    'source_date' => $row[4],
                    'date_is_anomalous' => $date->year < 2017 || $date->year > 2029,
                    'amount' => number_format($signedAmount, 2, '.', ''),
                    'currency' => $amount[3],
                    'clearing_document' => $amount[4] ?? null,
                    'description' => isset($amount[5]) && trim($amount[5]) !== '' ? trim($amount[5]) : null,
                    'source_page' => $page,
                ];
            }
        } finally {
            fclose($stream);
            if (is_resource($process)) {
                $exitCode = proc_close($process);
                if ($exitCode !== 0) {
                    throw new RuntimeException("pdftotext failed for {$file} with exit code {$exitCode}.");
                }
            }
        }
    }

    /** @return array{0: resource, 1: resource|null} */
    private function open(string $file): array
    {
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'pdf') {
            $stream = fopen($file, 'rb');
            if (! $stream) {
                throw new RuntimeException("Unable to read {$file}.");
            }

            return [$stream, null];
        }

        $process = proc_open(
            ['pdftotext', '-layout', $file, '-'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start pdftotext. Install Poppler before importing PDF ledgers.');
        }
        fclose($pipes[0]);
        fclose($pipes[2]);

        return [$pipes[1], $process];
    }
}
