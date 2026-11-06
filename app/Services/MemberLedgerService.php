<?php

namespace App\Services;

use App\Models\Member;
use Illuminate\Support\Collection;

class MemberLedgerService
{
    public function forMember(?Member $member): array
    {
        if (! $member) {
            return [
                'member' => null,
                'summary' => ['total_charged' => '0.00', 'total_paid' => '0.00', 'balance' => '0.00'],
                'transactions' => [],
                'challans' => [],
            ];
        }

        $charges = $member->charges()
            ->with('feeType:id,name,short_text,gl_account')
            ->whereNotIn('status', ['cancelled', 'reversed'])
            ->get();
        $payments = $member->payments()
            ->with('challan:id,challan_no')
            ->where('status', 'paid')
            ->get();

        $sapTransactions = $member->transactions()->oldest('document_date')->oldest('id')->get();
        if ($sapTransactions->isNotEmpty()) {
            $runningBalance = 0.0;
            $transactions = $sapTransactions->map(function ($transaction) use (&$runningBalance) {
                $amount = (float) $transaction->amount;
                $runningBalance += $amount;

                return [
                    'key' => 'sap-'.$transaction->id,
                    'date' => $transaction->document_date->toDateString(),
                    'created_at' => $transaction->id,
                    'type' => $amount >= 0 ? 'charge' : 'payment',
                    'reference' => $transaction->document_no,
                    'description' => $transaction->description ?: 'SAP '.$transaction->document_type.' transaction',
                    'gl_account' => null,
                    'document_type' => $transaction->document_type,
                    'clearing_document' => $transaction->clearing_document,
                    'sap_verification_number' => $transaction->sap_verification_number,
                    'sap_payment_status' => $transaction->sap_payment_status,
                    'sap_payment_document' => $transaction->sap_payment_document,
                    'sap_payment_checked_at' => $transaction->sap_payment_checked_at?->toIso8601String(),
                    'status' => $transaction->clearing_document ? 'cleared' : 'open',
                    'date_is_anomalous' => $transaction->date_is_anomalous,
                    'debit' => number_format(max($amount, 0), 2, '.', ''),
                    'credit' => number_format(abs(min($amount, 0)), 2, '.', ''),
                    'balance' => number_format($runningBalance, 2, '.', ''),
                ];
            });
            $totalCharged = (float) $sapTransactions->where('amount', '>', 0)->sum('amount');
            $totalPaid = abs((float) $sapTransactions->where('amount', '<', 0)->sum('amount'));

            return [
                'member' => $member->load('sportsComplex:id,name'),
                'source' => 'sap_customer_ledger',
                'summary' => [
                    'total_charged' => number_format($totalCharged, 2, '.', ''),
                    'total_paid' => number_format($totalPaid, 2, '.', ''),
                    'balance' => number_format($totalCharged - $totalPaid, 2, '.', ''),
                ],
                'transactions' => $transactions->where('date_is_anomalous', false)->reverse()->values()
                    ->concat($transactions->where('date_is_anomalous', true)->reverse()->values())->values(),
                'challans' => $this->memberChallans($member),
            ];
        }

        $transactions = new Collection;
        foreach ($charges as $charge) {
            $transactions->push([
                'key' => 'charge-'.$charge->id,
                'date' => ($charge->due_on ?? $charge->created_at)->toDateString(),
                'created_at' => $charge->created_at->toISOString(),
                'type' => 'charge',
                'reference' => $charge->source_id,
                'description' => $charge->description,
                'gl_account' => $charge->gl_account,
                'status' => $charge->status,
                'debit' => number_format((float) $charge->amount, 2, '.', ''),
                'credit' => '0.00',
            ]);
        }
        foreach ($payments as $payment) {
            $transactions->push([
                'key' => 'payment-'.$payment->id,
                'date' => $payment->paid_on->toDateString(),
                'created_at' => $payment->created_at->toISOString(),
                'type' => 'payment',
                'reference' => $payment->receipt_no,
                'description' => $payment->challan?->challan_no ? 'Payment for '.$payment->challan->challan_no : 'Membership payment',
                'gl_account' => null,
                'status' => $payment->status,
                'debit' => '0.00',
                'credit' => number_format((float) $payment->amount, 2, '.', ''),
            ]);
        }

        $runningBalance = 0.0;
        $transactions = $transactions
            ->sortBy(fn (array $entry) => $entry['date'].' '.$entry['created_at'].' '.$entry['key'])
            ->values()
            ->map(function (array $entry) use (&$runningBalance) {
                $runningBalance += (float) $entry['debit'] - (float) $entry['credit'];
                $entry['balance'] = number_format($runningBalance, 2, '.', '');

                return $entry;
            });

        $totalCharged = (float) $charges->sum('amount');
        $totalPaid = (float) $payments->sum('amount');

        return [
            'member' => $member->load('sportsComplex:id,name'),
            'source' => 'application_ledger',
            'summary' => [
                'total_charged' => number_format($totalCharged, 2, '.', ''),
                'total_paid' => number_format($totalPaid, 2, '.', ''),
                'balance' => number_format($totalCharged - $totalPaid, 2, '.', ''),
            ],
            'transactions' => $transactions->reverse()->values(),
            'challans' => $this->memberChallans($member),
        ];
    }

    private function memberChallans($member)
    {
        return $member->challans()->with(['bankTransactions' => fn ($transactions) => $transactions->latest('id')->limit(1)])->latest('issued_on')->limit(50)->get([
            'id', 'challan_no', 'purpose', 'amount', 'issued_on', 'due_on', 'status', 'sap_status', 'sap_document_no',
        ])->map(function ($challan) {
            $challan->download_url = in_array($challan->status, ['generated', 'issued', 'unpaid', 'expired', 'paid'], true)
                ? '/api/portal/challans/'.$challan->id.'/print'
                : null;
            $bank = $challan->bankTransactions->first();
            $challan->bank_payment = $bank ? [
                'public_id' => $bank->public_id,
                'order_id' => $bank->order_id,
                'status' => $bank->status,
                'reference' => $bank->bank_transaction_reference,
                'checkout_url' => filled($bank->session_id) && in_array($bank->status, ['session_created', 'pending'], true) ? route('bank.checkout', $bank->public_id) : null,
                'receipt_url' => $bank->status === 'verified' ? route('bank.receipt', $bank->public_id) : null,
            ] : null;
            unset($challan->bankTransactions);

            return $challan;
        });
    }
}
