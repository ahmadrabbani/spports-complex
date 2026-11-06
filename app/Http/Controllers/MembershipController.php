<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MembershipController extends Controller
{
    public function store(Request $request, Member $member)
    {
        $this->authorizeMember($request, $member);
        abort_if($member->status === 'frozen', 409, 'Unfreeze the member before changing membership benefits.');
        $data = $request->validate(['membership_plan_id' => ['required', 'exists:membership_plans,id'], 'starts_on' => ['required', 'date'], 'expires_on' => ['nullable', 'date', 'after_or_equal:starts_on'], 'status' => ['required', Rule::in(['pending', 'active', 'expired', 'cancelled', 'suspended'])], 'notes' => ['nullable', 'string']]);
        $plan = MembershipPlan::findOrFail($data['membership_plan_id']);
        abort_if($plan->sports_complex_id && $plan->sports_complex_id !== $member->sports_complex_id, 422, 'The plan belongs to another sports complex.');

        return DB::transaction(function () use ($member, $plan, $data, $request) {
            $locked = Member::lockForUpdate()->findOrFail($member->id);
            abort_if($locked->status === 'frozen', 409, 'Member is frozen.');
            if ($plan->membership_category_id) {
                abort_unless($request->user()->role === 'admin', 403, 'An administrator must approve a membership category change.');
                $locked->accountChangeReason = 'Membership plan assigned';
                $locked->update(['membership_category_id' => $plan->membership_category_id]);
            }

            return response()->json($locked->memberships()->create($data)->load('plan'), 201);
        });
    }

    public function update(Request $request, Membership $membership)
    {
        $this->authorizeMember($request, $membership->member()->firstOrFail());
        $data = $request->validate(['membership_plan_id' => ['sometimes', 'exists:membership_plans,id'], 'starts_on' => ['sometimes', 'date'], 'expires_on' => ['nullable', 'date'], 'status' => ['sometimes', Rule::in(['pending', 'active', 'expired', 'cancelled', 'suspended'])], 'notes' => ['nullable', 'string']]);

        return DB::transaction(function () use ($membership, $data, $request) {
            $member = Member::lockForUpdate()->findOrFail($membership->member_id);
            abort_if($member->status === 'frozen', 409, 'Unfreeze the member before changing memberships.');
            if (isset($data['membership_plan_id'])) {
                $plan = MembershipPlan::findOrFail($data['membership_plan_id']);
                abort_if($plan->sports_complex_id && $plan->sports_complex_id !== $member->sports_complex_id, 422, 'The plan belongs to another sports complex.');
                if ($plan->membership_category_id) {
                    abort_unless($request->user()->role === 'admin', 403);
                    $member->accountChangeReason = 'Membership plan changed';
                    $member->update(['membership_category_id' => $plan->membership_category_id]);
                }
            }
            $membership->update($data);

            return $membership->fresh('plan');
        });

    }

    private function authorizeMember(Request $request, Member $member): void
    {
        if ($request->user()->sports_complex_id && $request->user()->sports_complex_id !== $member->sports_complex_id) {
            abort(403);
        }
    }
}
