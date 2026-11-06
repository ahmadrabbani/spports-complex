<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\MemberTransaction;
use App\Models\Payment;
use App\Models\SportsComplex;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $scope = fn ($q) => $request->user()->sports_complex_id ? $q->where('sports_complex_id', $request->user()->sports_complex_id) : $q;
        $members = $scope(Member::query());
        $complexes = SportsComplex::query()
            ->when($request->user()->sports_complex_id, fn ($q, $id) => $q->whereKey($id))
            ->withCount('members')->orderBy('name')->get(['id', 'name', 'code']);
        $payments = Payment::query()->where('payments.status', 'paid')
            ->when($request->user()->sports_complex_id, fn ($q, $id) => $q->whereHas('member', fn ($members) => $members->where('sports_complex_id', $id)));
        $transactions = MemberTransaction::query()
            ->when($request->user()->sports_complex_id, fn ($q, $id) => $q->where('sports_complex_id', $id));
        $monthlyTransactions = (clone $transactions)->whereBetween('document_date', [now()->startOfMonth(), now()->endOfMonth()]);

        return response()->json([
            'metrics' => [
                'members' => (clone $members)->count(),
                'active' => (clone $members)->where('status', 'active')->count(),
                'complexes' => $request->user()->sports_complex_id ? 1 : SportsComplex::where('is_active', true)->count(),
                'payments_this_month' => (float) $payments->whereBetween('paid_on', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount'),
                'sap_credits_this_month' => abs((float) (clone $monthlyTransactions)->where('amount', '<', 0)->sum('amount')),
                'sap_debits_this_month' => (float) (clone $monthlyTransactions)->where('amount', '>', 0)->sum('amount'),
                'sap_balance' => (float) (clone $transactions)->sum('amount'),
                'sap_transactions' => (clone $transactions)->count(),
            ],
            'complexes' => $complexes,
            'recent_members' => (clone $members)
                ->with('sportsComplex:id,name')
                ->withSum(['charges as due_amount' => fn ($charges) => $charges->where('status', 'outstanding')], 'amount')
                ->withSum('transactions as sap_balance', 'amount')
                ->latest('source_created_at')->limit(8)->get(),
            'registrations' => (clone $members)->selectRaw((DB::getDriverName() === 'sqlite' ? "strftime('%Y-%m', source_created_at)" : "DATE_FORMAT(source_created_at, '%Y-%m')").' as month, COUNT(*) as total')
                ->whereNotNull('source_created_at')->groupBy('month')->orderByDesc('month')->limit(8)->get()->reverse()->values(),
            'recent_transactions' => (clone $transactions)
                ->where('date_is_anomalous', false)
                ->with(['member:id,full_name,customer_no', 'sportsComplex:id,name,code'])
                ->latest('document_date')->latest('id')->limit(12)->get([
                    'id', 'member_id', 'sports_complex_id', 'document_no', 'document_type', 'document_date',
                    'amount', 'clearing_document', 'description',
                ]),
        ]);
    }
}
