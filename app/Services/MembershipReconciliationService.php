<?php

namespace App\Services;

use App\Models\FeeType;
use App\Models\FreezeFeeApproval;
use App\Models\Member;
use App\Models\MembershipCategory;
use App\Models\ReconciliationAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MembershipReconciliationService
{
    public function resolve(Member $member): string
    {
        if ($member->status === 'frozen') {
            return $this->activeAccount('260111');
        }
        if ($member->membership_category_id) {
            $category = MembershipCategory::with('account')->find($member->membership_category_id);
            if (! $category || ! $category->active || $category->is_freeze || ! $category->account->active) {
                throw ValidationException::withMessages(['membership_category_id' => 'An active regular category and reconciliation account are required.']);
            }

            return $category->account->code;
        }
        // Legacy members retain their known SAP account until the explicit backfill is run.
        if ($member->reconciliation_account === '260111') {
            throw ValidationException::withMessages(['member' => 'Legacy freeze customer needs reviewed freeze history and original account before billing.']);
        }

        return $this->activeAccount((string) $member->reconciliation_account);
    }

    private function activeAccount(string $code): string
    {
        if (! ReconciliationAccount::where('code', $code)->where('active', true)->exists()) {
            throw ValidationException::withMessages(['reconciliation_account' => 'No active, mapped SAP reconciliation account. Review this member before billing.']);
        }

        return $code;
    }

    public function freeze(Member $member, array $data, User $actor): Member
    {
        return DB::transaction(function () use ($member, $data, $actor) {
            $m = Member::lockForUpdate()->findOrFail($member->id);
            abort_if($m->status === 'frozen' || $m->freezes()->whereNull('unfrozen_at')->exists(), 409, 'Member is already frozen.');
            if ($m->reconciliation_account === '260111') {
                $category = MembershipCategory::with('account')->find($data['original_membership_category_id'] ?? 0);
                abort_unless($category && $category->active && ! $category->is_freeze && $category->account->active, 422, 'Select the verified original category for this legacy frozen customer.');
                $m->membership_category_id = $category->id;
                $account = $category->account->code;
            } else {
                $account = $this->resolve($m);
            }
            $states = $m->memberships()->lockForUpdate()->get()->map(fn ($s) => ['id' => $s->id, 'status' => $s->status])->all();
            $m->freezes()->create(['original_category_id' => $m->membership_category_id, 'original_account' => $account, 'original_status' => $m->status, 'membership_states' => $states, 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'] ?? null, 'reason' => $data['reason'], 'created_by' => $actor->id]);
            $m->memberships()->whereIn('status', ['active', 'pending'])->update(['status' => 'frozen']);
            $m->accountChangeReason = $data['reason'];
            $m->update(['status' => 'frozen', 'original_reconciliation_account' => $account, 'reconciliation_account' => $this->activeAccount('260111')]);

            return $m->fresh();
        });
    }

    public function unfreeze(Member $member, string $reason, User $actor): Member
    {
        return DB::transaction(function () use ($member, $reason, $actor) {
            $m = Member::lockForUpdate()->findOrFail($member->id);
            abort_unless($m->status === 'frozen', 409, 'Member is not frozen.');
            $freeze = $m->freezes()->whereNull('unfrozen_at')->lockForUpdate()->first();
            abort_unless($freeze && $freeze->original_account, 422, 'Original category/account must be reviewed before unfreezing.');
            $this->activeAccount($freeze->original_account);
            if ($freeze->original_category_id) {
                $category = MembershipCategory::with('account')->find($freeze->original_category_id);
                abort_unless($category && $category->active && $category->account->active && $category->account->code === $freeze->original_account, 422, 'The original category mapping changed while frozen. Review it before restoration.');
            }
            foreach ($freeze->membership_states as $state) {
                $membership = $m->memberships()->find($state['id']);
                if ($membership && $membership->status === 'frozen') {
                    $status = $state['status'];
                    if ($membership->expires_on && $membership->expires_on->lt(today())) {
                        $status = 'expired';
                    }
                    if ($status === 'active' && $membership->starts_on->gt(today())) {
                        $status = 'pending';
                    }
                    $membership->update(['status' => $status]);
                }
            }
            $hasMemberships = $m->memberships()->exists();
            $eligible = $m->memberships()->where('status', 'active')->whereDate('starts_on', '<=', today())->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', today()))->exists();
            $status = $freeze->original_status === 'active' && $hasMemberships && ! $eligible ? 'inactive' : $freeze->original_status;
            $m->accountChangeReason = $reason;
            $m->restoringFrozenAccount = true;
            $m->update(['status' => $status, 'membership_category_id' => $freeze->original_category_id, 'reconciliation_account' => $freeze->original_account, 'original_reconciliation_account' => null]);
            $freeze->update(['unfrozen_at' => now(), 'unfrozen_by' => $actor->id, 'unfreeze_reason' => $reason]);

            return $m->fresh();
        });
    }

    public function approveFee(Member $member, array $data, User $actor): FreezeFeeApproval
    {
        return DB::transaction(function () use ($member, $data, $actor) {
            $m = Member::lockForUpdate()->findOrFail($member->id);
            $freeze = $m->freezes()->whereNull('unfrozen_at')->first();
            abort_unless($m->status === 'frozen' && $freeze, 422, 'An active freeze is required.');
            $fee = FeeType::findOrFail($data['fee_type_id']);
            abort_unless($fee->active && $fee->is_freeze_fee && $fee->freeze_amount > 0 && filled($fee->gl_account), 422, 'Configure a freeze fee with a positive amount and validated GL account first.');

            return FreezeFeeApproval::create(['membership_freeze_id' => $freeze->id, 'fee_type_id' => $fee->id, 'amount' => $fee->freeze_amount, 'reason' => $data['reason'], 'approved_by' => $actor->id]);
        });
    }
}
