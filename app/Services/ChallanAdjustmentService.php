<?php

namespace App\Services;

use App\Models\Challan;
use App\Models\ChallanAdjustment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChallanAdjustmentService
{
    public function adjust(Challan $challan, array $data, User $actor): ChallanAdjustment
    {
        return DB::transaction(function () use ($challan, $data, $actor) {
            $c = Challan::with('items', 'member')->lockForUpdate()->findOrFail($challan->id);
            $paid = $c->status === 'paid' || $c->payment_verified_at || $c->bankTransactions()->where('status', 'verified')->exists();
            $mappingError = null;
            try {
                $account = app(MembershipReconciliationService::class)->resolve($c->member);
            } catch (ValidationException $e) {
                if ($c->status === 'draft' && ! $paid) {
                    throw $e;
                }
                $account = null;
                $mappingError = 'Current membership account requires manual review.';
            }
            $old = $c->only(['status', 'sap_status', 'sap_document_no', 'reconciliation_account', 'amount', 'membership_category_id', 'membership_category_name']);
            $old['items'] = $c->items->toArray();
            $newAmount = $c->amount;
            $proposed = ['reconciliation_account' => $account, 'membership_category_id' => $c->member->membership_category_id, 'mapping_review' => $mappingError];
            if ($c->status === 'draft' && ! $c->sap_document_no && in_array($c->sap_status, ['draft', 'not_sent'], true) && ! $paid) {
                abort_if($c->member->status === 'frozen', 422, 'Use an approved freeze fee through challan generation.');
                abort_unless($c->items->isNotEmpty(), 422, 'Draft requires a fee breakdown.');
                $amounts = collect($data['items'] ?? [])->keyBy('id');
                if ($amounts->isNotEmpty()) {
                    abort_unless($amounts->count() === $c->items->count() && $c->items->every(fn ($i) => $amounts->has($i->id)), 422, 'Provide every draft item exactly once.');
                }
                foreach ($c->items as $item) {
                    $charge = $item->charge()->lockForUpdate()->firstOrFail();
                    abort_unless($charge->status === 'outstanding', 409, 'A paid/cancelled charge cannot be edited.');
                    abort_if($charge->challanItems()->where('challan_id', '<>', $c->id)->exists(), 409, 'Shared historical charges cannot be edited.');
                    $amount = $amounts->get($item->id)['amount'] ?? $item->amount;
                    $item->update(['amount' => $amount, 'reconciliation_account' => $account]);
                    $charge->update(['amount' => $amount, 'reconciliation_account' => $account]);
                }
                $items = $c->items()->get();
                $sum = fn ($rows) => number_format($rows->sum(fn ($i) => (int) round((float) $i->amount * 100)) / 100, 2, '.', '');
                $newAmount = $sum($items);
                $c->update(['amount' => $newAmount, 'current_amount' => $sum($items->where('is_previous_outstanding', false)), 'previous_outstanding' => $sum($items->where('is_previous_outstanding', true)), 'reconciliation_account' => $account, 'membership_category_id' => $c->member->membership_category_id, 'membership_category_name' => $c->member->category?->name, 'sap_payload' => null, 'request_url' => null, 'adjustment_status' => 'draft_adjusted']);
                $status = 'draft_adjusted';
                $sap = 'not_sent';
                $proposed['items'] = $items->toArray();
            } else {
                // No verified cancellation/reversal contract exists. Preserve the original, including its payment status.
                $status = $paid ? 'reversal_required' : 'manual_sap_reconciliation';
                $sap = $paid ? 'reversal_not_supported' : 'cancellation_not_supported';
                if (isset($data['proposed_amount'])) {
                    $newAmount = number_format((float) $data['proposed_amount'], 2, '.', '');
                }
                $c->update(['adjustment_status' => $status]);
                $proposed['requested_items'] = $data['items'] ?? [];
            }
            $proposed['amount'] = $newAmount;

            return ChallanAdjustment::create(['challan_id' => $c->id, 'old_account' => $old['reconciliation_account'], 'new_account' => $account, 'old_amount' => $old['amount'], 'new_amount' => $newAmount, 'reason' => $data['reason'], 'actor_id' => $actor->id, 'status' => $status, 'sap_status' => $sap, 'original_snapshot' => $old, 'proposed_snapshot' => $proposed]);
        });
    }
}
