<?php

namespace App\Http\Controllers;

use App\Models\BankTransaction;
use App\Models\Challan;
use App\Models\PaymentVerification;
use App\Services\MockBankService;
use App\Services\SapPaymentVerificationService;
use Illuminate\Http\Request;

class PaymentTestingController extends Controller
{
    private function authorizeChallan(Request $r, Challan $c): void
    {
        abort_unless($r->user()->role === 'admin', 403);
        if ($r->user()->sports_complex_id) {
            abort_unless($r->user()->sports_complex_id === $c->sports_complex_id, 403);
        }
    }

    public function mock(Request $r, Challan $challan, MockBankService $service)
    {
        $this->authorizeChallan($r, $challan);
        $d = $r->validate(['scenario' => 'required|in:'.implode(',', MockBankService::SCENARIOS), 'idempotency_key' => 'required|uuid']);

        return $service->run($challan, $r->user(), $d['scenario'], $d['idempotency_key']);
    }

    public function confirm(Request $r, BankTransaction $transaction, MockBankService $service)
    {
        $this->authorizeChallan($r, $transaction->challan);

        return $service->confirm($transaction, $r->user());
    }

    public function verify(Request $r, Challan $challan, SapPaymentVerificationService $service)
    {
        $this->authorizeChallan($r, $challan);

        return ['result' => $service->verify($challan, $r->user()->id)];
    }

    public function history(Request $r, Challan $challan)
    {
        $this->authorizeChallan($r, $challan);

        return ['environment' => $challan->environment, 'sap_response' => $challan->response_payload, 'transactions' => $challan->bankTransactions()->get(), 'verifications' => PaymentVerification::where('challan_id', $challan->id)->latest()->get()];
    }

    public function receipt(Request $r, BankTransaction $transaction)
    {
        $this->authorizeChallan($r, $transaction->challan);
        abort_unless($transaction->provider === 'mock' && $transaction->status === 'verified', 404);

        return view('bank.mock-receipt',['transaction' => $transaction->load('challan','member')]);
    }
}
