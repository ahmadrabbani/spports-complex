<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\MembershipCategory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillMemberReconciliation extends Command
{
    protected $signature = 'members:backfill-reconciliation {--apply : Apply reviewed, unambiguous mappings; default is dry-run} {--report= : Private CSV report path}';

    protected $description = 'Safely map legacy member accounts; report ambiguous, unknown and frozen accounts without changing financial records';

    public function handle(): int
    {
        $counts = ['mapped' => 0, 'unchanged' => 0, 'review' => 0];
        $report = [];
        Member::orderBy('id')->chunkById(250, function ($members) use (&$counts, &$report) {
            foreach ($members as $member) {
                DB::transaction(function () use ($member, &$counts, &$report) {
                    $m = Member::lockForUpdate()->findOrFail($member->id);
                    $reason = null;
                    $category = null;
                    if ($m->status === 'frozen' || $m->reconciliation_account === '260111') {
                        $reason = 'Frozen customer: original account and freeze history require manual review';
                    } elseif ($m->membership_category_id) {
                        $category = $m->category;
                        if (! $category?->active || ! $category?->account?->active || $category?->is_freeze) {
                            $reason = 'Inactive or invalid category mapping';
                        }
                    } else {
                        $candidates = MembershipCategory::where('active', true)->where('is_freeze', false)->whereHas('account', fn ($q) => $q->where('code', $m->reconciliation_account)->where('active', true))->get();
                        if ($candidates->count() === 1) {
                            $category = $candidates->first();
                        } else {
                            $reason = 'Unknown or ambiguous existing REACON; no category guessed';
                        }
                    }
                    // Existing active plan associations can contradict imported SAP customer categories.
                    $planCategories = $m->memberships()->whereIn('status', ['active', 'pending'])->with('plan')->get()->pluck('plan.membership_category_id')->filter()->unique();
                    if ($category && $planCategories->contains(fn ($id) => (int) $id !== $category->id)) {
                        $reason = 'Membership plan conflicts with existing SAP category';
                    }
                    if ($reason) {
                        $counts['review']++;
                        $report[] = [$m->id, $m->reconciliation_account, $reason];

                        return;
                    }
                    if ($m->membership_category_id === $category->id && $m->reconciliation_account === $category->account->code) {
                        $counts['unchanged']++;

                        return;
                    }
                    $counts['mapped']++;
                    if ($this->option('apply')) {
                        $m->accountChangeReason = 'Idempotent legacy REACON backfill';
                        $m->update(['membership_category_id' => $category->id, 'reconciliation_account' => $category->account->code]);
                    }
                });
            }
        });
        $this->line(json_encode(['mode' => $this->option('apply') ? 'apply' : 'dry-run'] + $counts));
        if ($path = $this->option('report')) {
            $f = fopen($path, 'w');
            if (! $f) {
                return self::FAILURE;
            }
            chmod($path, 0600);
            fputcsv($f, ['member_id', 'existing_reacon', 'review_reason']);
            foreach ($report as $row) {
                fputcsv($f, $row);
            } fclose($f);
        }

        return self::SUCCESS;
    }
}
