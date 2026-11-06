<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\MembershipCategory;
use App\Models\MembershipPlan;
use App\Models\ReconciliationAccount;
use App\Services\MembershipReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReconciliationController extends Controller
{
    private function admin(Request $request, ?Member $member = null): void
    {
        abort_unless($request->user()->role === 'admin', 403);
        if (! $member) {
            abort_if($request->user()->sports_complex_id, 403, 'Global accounting configuration requires an unscoped administrator.');
        }
        if ($member) {
            abort_if($request->user()->sports_complex_id && $request->user()->sports_complex_id !== $member->sports_complex_id, 403);
        }
    }

    public function index(Request $request)
    {
        return ['accounts' => ReconciliationAccount::with('categories')->orderBy('code')->get(), 'categories' => MembershipCategory::with('account')->orderBy('name')->get(), 'plans' => MembershipPlan::when($request->user()->sports_complex_id, fn ($q, $id) => $q->where(fn ($p) => $p->whereNull('sports_complex_id')->orWhere('sports_complex_id', $id)))->orderBy('name')->get()];
    }

    public function updateAccount(Request $request, ReconciliationAccount $account)
    {
        $this->admin($request);
        $account->update($request->validate(['long_description' => ['required', 'string', 'max:255'], 'short_description' => ['required', 'string', 'max:80'], 'active' => ['required', 'boolean']]));

        return $account->fresh('categories');
    }

    public function storeAccount(Request $request)
    {
        $this->admin($request);

        return response()->json(ReconciliationAccount::create($request->validate(['code' => ['required', 'string', 'regex:/^\d{1,20}$/D', 'unique:reconciliation_accounts,code'], 'long_description' => ['required', 'string', 'max:255'], 'short_description' => ['required', 'string', 'max:80'], 'active' => ['required', 'boolean']])), 201);
    }

    public function updateCategory(Request $request, MembershipCategory $category)
    {
        $this->admin($request);
        $data = $request->validate(['reconciliation_account_id' => ['required', 'exists:reconciliation_accounts,id'], 'active' => ['required', 'boolean']]);
        $account = ReconciliationAccount::findOrFail($data['reconciliation_account_id']);
        abort_if($category->is_freeze ? $account->code !== '260111' : $account->code === '260111', 422, '260111 is reserved for frozen customers.');

        return DB::transaction(function () use ($category, $data, $account) {
            $category->update($data);
            Member::where('membership_category_id', $category->id)->where('status', '<>', 'frozen')->orderBy('id')->chunkById(250, function ($members) use ($account) {
                foreach ($members as $member) {
                    $m = Member::lockForUpdate()->findOrFail($member->id);
                    if ($m->status === 'frozen') {
                        continue;
                    }
                    $m->accountChangeReason = 'Administrator changed category REACON mapping';
                    $m->update(['reconciliation_account' => $account->code]);
                }
            });

            return $category->fresh('account');
        });
    }

    public function updatePlan(Request $request, MembershipPlan $plan)
    {
        $this->admin($request);
        abort_if($request->user()->sports_complex_id && $plan->sports_complex_id && $request->user()->sports_complex_id !== $plan->sports_complex_id, 403);
        $data = $request->validate(['membership_category_id' => ['nullable', Rule::exists('membership_categories', 'id')->where('active', true)->where('is_freeze', 0)]]);
        $plan->update($data);

        return $plan->fresh();
    }

    public function assign(Request $request, Member $member)
    {
        $this->admin($request, $member);
        $data = $request->validate(['membership_category_id' => ['required', Rule::exists('membership_categories', 'id')->where('active', true)->where('is_freeze', 0)], 'reason' => ['required', 'string', 'max:1000']]);

        return DB::transaction(function () use ($member, $data) {
            $m = Member::lockForUpdate()->findOrFail($member->id);
            abort_if($m->status === 'frozen' || $m->reconciliation_account === '260111', 409, 'Record and review the freeze before changing the original membership category.');
            $m->accountChangeReason = $data['reason'];
            $m->update(['membership_category_id' => $data['membership_category_id']]);

            return $m->fresh('category.account');
        });
    }

    public function freeze(Request $request, Member $member, MembershipReconciliationService $service)
    {
        $this->admin($request, $member);
        $data = $request->validate(['original_membership_category_id' => ['nullable', Rule::exists('membership_categories', 'id')->where('active', true)->where('is_freeze', 0)], 'starts_on' => ['required', 'date', 'before_or_equal:today'], 'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'], 'reason' => ['required', 'string', 'max:1000']]);

        return $service->freeze($member, $data, $request->user());
    }

    public function unfreeze(Request $request, Member $member, MembershipReconciliationService $service)
    {
        $this->admin($request, $member);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $service->unfreeze($member, $data['reason'], $request->user());
    }

    public function approveFee(Request $request, Member $member, MembershipReconciliationService $service)
    {
        $this->admin($request, $member);
        $data = $request->validate(['fee_type_id' => ['required', 'exists:fee_types,id'], 'reason' => ['required', 'string', 'max:1000']]);

        return response()->json($service->approveFee($member, $data, $request->user()), 201);
    }
}
