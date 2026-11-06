<?php

namespace App\Http\Controllers;

use App\Models\BankApiLog;
use App\Models\BankTransaction;
use App\Models\Challan;
use App\Services\BopMpgsService;
use Illuminate\Http\Request;

class BankPaymentController extends Controller
{
    public function __construct(private BopMpgsService $bank) {}

    public function portalInitiate(Request $request, Challan $challan)
    {
        abort_unless($request->user()->member_id === $challan->member_id, 403);
        $data = $request->validate(['initiation_key' => ['required', 'uuid']]);
        $transaction = $this->bank->initiate($challan, $request->user(), $data['initiation_key']);

        return response()->json($this->present($transaction), $transaction->wasRecentlyCreated ? 201 : 200);
    }

    public function portalVerify(Request $request, BankTransaction $transaction)
    {
        abort_unless($request->user()->member_id === $transaction->member_id, 403);

        return $this->present($this->bank->verify($transaction));
    }

    public function checkout(Request $request, BankTransaction $transaction)
    {
        abort_unless($request->user()->member_id === $transaction->member_id, 403);
        abort_unless(in_array($transaction->status, ['session_created', 'pending'], true) && filled($transaction->session_id), 409, 'A valid bank checkout session is not available.');

        return view('bank.checkout', [
            'transaction' => $transaction->load(['challan', 'member', 'challan.sportsComplex']),
            'checkoutScript' => rtrim((string) config('services.bop_mpgs.base_url'), '/').'/static/checkout/checkout.min.js',
        ]);
    }

    public function returned(Request $request, BankTransaction $transaction)
    {
        abort_unless($request->user()->member_id === $transaction->member_id, 403);
        $indicatorMatches = filled($request->query('resultIndicator'))
            && hash_equals((string) $transaction->success_indicator, (string) $request->query('resultIndicator'));
        try {
            $verified = $this->bank->verify($transaction);
        } catch (\Throwable) {
            $verified = $transaction->fresh();
        }

        return view('bank.return', [
            'transaction' => $verified,
            'indicatorMatches' => $indicatorMatches,
        ]);
    }

    public function receipt(Request $request, BankTransaction $transaction)
    {
        abort_unless($request->user()->member_id === $transaction->member_id || $request->user()->role !== 'member', 403);
        if ($request->user()->role !== 'member' && $request->user()->sports_complex_id) {
            abort_unless($transaction->challan?->sports_complex_id === $request->user()->sports_complex_id, 403);
        }
        abort_unless($transaction->status === 'verified', 404);

        return view('bank.receipt', ['transaction' => $transaction->load(['challan.sportsComplex', 'member'])]);
    }

    public function index(Request $request)
    {
        $query = BankTransaction::select([
            'id', 'public_id', 'challan_id', 'member_id', 'provider', 'environment', 'order_id',
            'bank_transaction_reference', 'gateway_result', 'gateway_order_status', 'status', 'amount',
            'currency', 'verification_attempts', 'last_verified_at', 'verified_at', 'failed_at',
            'failure_message', 'created_at', 'updated_at',
        ])->with(['challan:id,challan_no,sports_complex_id', 'member:id,full_name,customer_no,cnic', 'challan.sportsComplex:id,name,code'])->latest('id');
        if ($request->user()->sports_complex_id) {
            $query->whereHas('challan', fn ($challans) => $challans->where('sports_complex_id', $request->user()->sports_complex_id));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('q')) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $request->string('q')).'%';
            $query->where(fn ($q) => $q->where('order_id', 'like', $term)
                ->orWhere('bank_transaction_reference', 'like', $term)
                ->orWhereHas('challan', fn ($challans) => $challans->where('challan_no', 'like', $term))
                ->orWhereHas('member', fn ($members) => $members->search((string) $request->input('q'))));
        }

        $scope = BankTransaction::query()->when($request->user()->sports_complex_id, fn ($q, $id) => $q->whereHas('challan', fn ($challans) => $challans->where('sports_complex_id', $id)));

        return response()->json([
            'configuration' => $this->bank->configuration(),
            'metrics' => [
                'total' => (clone $scope)->count(),
                'pending' => (clone $scope)->whereIn('status', ['created', 'session_created', 'pending', 'verification_required'])->count(),
                'verified' => (clone $scope)->where('status', 'verified')->count(),
                'failed' => (clone $scope)->whereIn('status', ['failed', 'expired', 'reversed'])->count(),
                'collected' => (float) (clone $scope)->where('status', 'verified')->sum('amount'),
                'exceptions' => (clone $scope)->where('status', 'verification_required')->count(),
            ],
            'last_api_log' => ($request->user()->isSuperAdmin() ? BankApiLog::latest('id')->first(['operation', 'environment', 'http_status', 'duration_ms', 'error_message', 'requested_at', 'responded_at']) : null),
            'transactions' => $query->paginate(min($request->integer('per_page', 20), 100)),
        ]);
    }

    public function diagnostic(Request $request)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);

        return $this->bank->testConnection();
    }

    public function staffVerify(Request $request, BankTransaction $transaction)
    {
        if ($request->user()->sports_complex_id) {
            abort_unless($transaction->challan()->where('sports_complex_id', $request->user()->sports_complex_id)->exists(), 403);
        }

        return $this->present($this->bank->verify($transaction));
    }

    private function present(BankTransaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'public_id' => $transaction->public_id,
            'order_id' => $transaction->order_id,
            'status' => $transaction->status,
            'gateway_result' => $transaction->gateway_result,
            'gateway_order_status' => $transaction->gateway_order_status,
            'bank_transaction_reference' => $transaction->bank_transaction_reference,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'environment' => $transaction->environment,
            'failure_message' => $transaction->failure_message,
            'last_verified_at' => $transaction->last_verified_at,
            'verified_at' => $transaction->verified_at,
            'checkout_url' => filled($transaction->session_id) && in_array($transaction->status, ['session_created', 'pending'], true)
                ? route('bank.checkout', $transaction->public_id) : null,
            'receipt_url' => $transaction->status === 'verified' ? route('bank.receipt', $transaction->public_id) : null,
        ];
    }
}
