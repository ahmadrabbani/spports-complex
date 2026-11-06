<?php

namespace App\Http\Controllers;

use App\Models\Challan;
use App\Models\Member;
use App\Services\SportsComplexChallanService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function __construct(private SportsComplexChallanService $challanService) {}

    public function store(Request $request, Member $member)
    {
        if ($request->user()->sports_complex_id && $request->user()->sports_complex_id !== $member->sports_complex_id) {
            abort(403);
        }
        $data = $request->validate(['membership_id' => ['nullable', Rule::exists('memberships', 'id')->where(fn (Builder $q) => $q->where('member_id', $member->id))], 'challan_id' => ['nullable', Rule::exists('challans', 'id')->where(fn (Builder $q) => $q->where('member_id', $member->id))], 'receipt_no' => ['required', 'string', 'max:80', 'unique:payments'], 'amount' => ['required', 'numeric', 'gt:0'], 'paid_on' => ['required', 'date'], 'method' => ['required', Rule::in(['cash', 'card', 'bank', 'online'])], 'reference' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string']]);

        abort_if(! empty($data['challan_id']), 409, 'Challan payments require authoritative verification or the mock simulator.');

        $payment = DB::transaction(function () use ($data, $member, $request) {
            $payment = $member->payments()->create([...$data, 'received_by' => $request->user()->id, 'status' => 'paid']);
            if (! empty($data['challan_id'])) {
                $challan = Challan::lockForUpdate()->findOrFail($data['challan_id']);
                $paid = $member->payments()->where('challan_id', $challan->id)->where('status', 'paid')->sum('amount');
                if ((float) $paid >= (float) $challan->amount) {
                    $this->challanService->markPaid($challan);
                }
            }

            return $payment;
        });

        return response()->json($payment, 201);
    }
}
