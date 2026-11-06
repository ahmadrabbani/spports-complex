<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Services\MemberLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    public function index(Request $request)
    {
        $query = Member::with(['sportsComplex:id,name', 'memberships.plan:id,name', 'category.account'])
            ->withSum(['charges as due_amount' => fn ($charges) => $charges->where('status', 'outstanding')], 'amount')
            ->withSum('transactions as sap_balance', 'amount')
            ->latest('id');
        if ($request->user()->sports_complex_id) {
            $query->where('sports_complex_id', $request->user()->sports_complex_id);
        }
        if ($request->filled('complex_id')) {
            $query->where('sports_complex_id', $request->integer('complex_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        $data = $request->validate(['q' => ['nullable', 'string', 'max:255']]);
        $query->search($data['q'] ?? '');

        return response()->json($query->paginate(min(max($request->integer('per_page', 20), 1), 100)))->header('Cache-Control', 'no-store');

    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        if ($request->user()->sports_complex_id && $request->user()->sports_complex_id !== (int) $data['sports_complex_id']) {
            abort(403);
        }

        if (! empty($data['membership_category_id'])) {
            abort_unless($request->user()->role === 'admin', 403);
        }

        return DB::transaction(fn () => response()->json(Member::create($data), 201));
    }

    public function show(Request $request, Member $member)
    {
        $this->authorizeMember($request, $member);

        return $member->load(['sportsComplex', 'memberships.plan', 'payments', 'photoUpdater:id,name', 'photoAudits.actor:id,name', 'category.account', 'freezes.actor:id,name', 'freezes.approvals.feeType', 'accountAudits.actor:id,name']);
    }

    public function ledger(Request $request, Member $member, MemberLedgerService $ledger)
    {
        $this->authorizeMember($request, $member);
        $data = $ledger->forMember($member);
        unset($data['challans']);

        return response()->json($data)->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, Member $member)
    {
        $this->authorizeMember($request, $member);
        $data = $this->validated($request, $member);

        return DB::transaction(function () use ($request, $member, $data) {
            $locked = Member::lockForUpdate()->findOrFail($member->id);
            abort_if(array_key_exists('membership_category_id', $data) && (int) $data['membership_category_id'] !== (int) $locked->membership_category_id, 422, 'Use the category action with a reason.');
            abort_if($request->user()->sports_complex_id && $request->user()->sports_complex_id !== (int) $data['sports_complex_id'], 403);
            abort_if($locked->status === 'frozen' && $data['status'] !== 'frozen', 409, 'Use the audited unfreeze action.');
            abort_if($locked->status !== 'frozen' && $data['status'] === 'frozen', 409, 'Use the audited freeze action.');
            $locked->update($data);

            return $locked->fresh('sportsComplex');
        });
    }

    public function destroy(Request $request, Member $member)
    {
        $this->authorizeMember($request, $member);
        abort_unless(in_array($request->user()->role, ['admin', 'manager']), 403);
        $member->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?Member $member = null): array
    {
        return $request->validate([
            'sports_complex_id' => ['required', 'exists:sports_complexes,id'],
            'customer_no' => ['required', 'string', 'max:20', Rule::unique('members')->ignore($member)],
            'membership_category_id' => ['sometimes', 'nullable', Rule::exists('membership_categories', 'id')->where('active', true)->where('is_freeze', 0)],
            'membership_no' => ['nullable', 'string', 'max:80'],
            'full_name' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:30'],
            'cnic' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string'],
            'status' => ['required', Rule::in($member?->status === 'frozen' ? ['frozen'] : ['active', 'inactive', 'suspended'])],
        ]);
    }

    private function authorizeMember(Request $request, Member $member): void
    {
        if ($request->user()->sports_complex_id && $request->user()->sports_complex_id !== $member->sports_complex_id) {
            abort(403);
        }
    }
}
