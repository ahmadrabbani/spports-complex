<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\BankTransaction;
use App\Models\Challan;
use App\Services\BopMpgsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class BankController extends Controller
{
    public function __construct(private BopMpgsService $bank) {}

    public function initiate(Request $request, Challan $challan)
    {
        abort_unless($request->user()->member_id === $challan->member_id, 403);
        $data = $request->validate(['initiation_key' => 'required|uuid']);

        return $this->present($this->bank->initiate($challan, $request->user(), $data['initiation_key'], true));
    }

    public function show(Request $request, BankTransaction $transaction)
    {
        $this->authorizeOwner($request, $transaction);

        return $this->present($transaction);
    }

    public function verify(Request $request, BankTransaction $transaction)
    {
        $this->authorizeOwner($request, $transaction);

        return $this->present($this->bank->verify($transaction));
    }

    public function receipt(Request $request, BankTransaction $transaction)
    {
        $this->authorizeOwner($request, $transaction);
        abort_unless($transaction->status === 'verified', 404);

        return view('bank.receipt', ['transaction' => $transaction->load(['challan.sportsComplex', 'member'])]);
    }

    public function checkout(Request $request, BankTransaction $transaction)
    {
        // Short-lived signature is scoped to this checkout, never an account token.
        abort_unless($transaction->member()->whereHas('portalUsers', fn ($q) => $q->where('is_active', true)->where('role', 'member'))->exists(), 403);
        abort_unless(in_array($transaction->status, ['session_created', 'pending'], true) && filled($transaction->session_id), 409);

        return response()->view('bank.checkout', [
            'transaction' => $transaction->load(['challan.sportsComplex', 'member']),
            'checkoutScript' => rtrim(config('services.bop_mpgs.base_url'), '/').'/static/checkout/checkout.min.js',
        ])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function authorizeOwner(Request $request, BankTransaction $transaction): void
    {
        abort_unless($request->user()->member_id === $transaction->member_id, 403);
    }

    private function present(BankTransaction $transaction): array
    {
        return $transaction->only(['public_id', 'challan_id', 'order_id', 'status', 'amount', 'currency', 'environment', 'bank_transaction_reference', 'failure_message', 'verified_at']) + [
            'checkout_url' => filled($transaction->session_id) && in_array($transaction->status, ['session_created', 'pending'], true)
                ? URL::temporarySignedRoute('mobile.bank.checkout', now()->addMinutes(10), ['transaction' => $transaction->public_id]) : null,
            'receipt_url' => $transaction->status === 'verified' ? url('/api/mobile/v1/bank-transactions/'.$transaction->public_id.'/receipt') : null,
        ];
    }
}
