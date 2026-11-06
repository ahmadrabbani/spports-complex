<?php

namespace App\Models;

use App\Services\MembershipReconciliationService;
use App\Support\CnicSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Member extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = ['source_created_at' => 'date', 'source_changed_at' => 'datetime', 'source_data' => 'array', 'photo_updated_at' => 'datetime'];

    protected $hidden = ['profile_image_path'];

    protected $appends = ['profile_image_url', 'masked_cnic', 'effective_reconciliation_account'];

    public string $accountChangeReason = 'Membership account updated';

    public bool $restoringFrozenAccount = false;

    protected static function booted(): void
    {
        static::saving(function (Member $member) {
            if ($member->isDirty('membership_category_id') && $member->membership_category_id && $member->status !== 'frozen' && ! $member->restoringFrozenAccount) {
                $member->reconciliation_account = app(MembershipReconciliationService::class)->resolve($member);
            }
        });
        static::saved(function (Member $member) {
            if ($member->wasRecentlyCreated || $member->wasChanged(['reconciliation_account', 'membership_category_id', 'status'])) {
                MembershipAccountAudit::create([
                    'member_id' => $member->id, 'old_account' => $member->getRawOriginal('reconciliation_account'), 'new_account' => $member->reconciliation_account,
                    'old_category_id' => $member->getRawOriginal('membership_category_id'), 'new_category_id' => $member->membership_category_id,
                    'old_status' => $member->getRawOriginal('status'), 'new_status' => $member->status,
                    'reason' => $member->accountChangeReason, 'actor_id' => auth()->id(),
                ]);
            }
        });
    }

    protected function effectiveReconciliationAccount(): Attribute
    {
        return Attribute::get(function () {
            if ($this->status === 'frozen') {
                return '260111';
            }

            return $this->category?->account?->code ?? $this->reconciliation_account;
        });
    }

    public function category()
    {
        return $this->belongsTo(MembershipCategory::class, 'membership_category_id');
    }

    public function freezes()
    {
        return $this->hasMany(MembershipFreeze::class)->latest('id');
    }

    public function accountAudits()
    {
        return $this->hasMany(MembershipAccountAudit::class)->latest('id');
    }

    protected function profileImageUrl(): Attribute
    {
        return Attribute::get(fn () => $this->profile_image_path
            ? route('members.photo', ['member' => $this->id, 'v' => $this->photo_updated_at?->timestamp ?: $this->updated_at?->timestamp])
            : null);
    }

    protected function maskedCnic(): Attribute
    {
        return Attribute::get(function () {
            $digits = preg_replace('/\D+/', '', (string) $this->cnic);

            return strlen($digits) === 13 ? substr($digits, 0, 5).'-*******-'.substr($digits, -1) : null;
        });
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $search = trim($search);
        if ($search === '') {
            return $query;
        }
        $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';

        return $query->where(function ($matches) use ($search, $term) {
            $matches->where('full_name', 'like', $term)
                ->orWhere('customer_no', 'like', $term)
                ->orWhere('membership_no', 'like', $term)
                ->orWhere('phone', 'like', $term);
            CnicSearch::orWhereMatches($matches, $search);
        });
    }

    public function allowsFamilyMembers(): bool
    {
        // Category names remain meaningful even when finance remaps account codes.
        if (preg_match('/\bindividual\b/i', $this->category?->name ?? '')) {
            return false;
        }
        if (in_array((string) $this->reconciliation_account, ['260110', '260115'], true)
            || ($this->status === 'frozen' && in_array((string) $this->original_reconciliation_account, ['260110', '260115'], true))) {
            return false;
        }
        // Legacy members may only have a plan, without a reconciliation category.
        if (! $this->membership_category_id) {
            return ! $this->memberships()->whereIn('status', ['active', 'frozen'])
                ->whereHas('plan', fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', ['%individual%']))->exists();
        }

        return true;
    }

    public function familyMembers()
    {
        return $this->hasMany(FamilyMember::class);
    }

    public function sportsComplex()
    {
        return $this->belongsTo(SportsComplex::class);
    }

    public function memberships()
    {
        return $this->hasMany(Membership::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function challans()
    {
        return $this->hasMany(Challan::class);
    }

    public function charges()
    {
        return $this->hasMany(MemberCharge::class);
    }

    public function transactions()
    {
        return $this->hasMany(MemberTransaction::class);
    }

    public function bankTransactions()
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function portalUsers()
    {
        return $this->hasMany(User::class);
    }

    public function photoUpdater()
    {
        return $this->belongsTo(User::class, 'photo_updated_by');
    }

    public function photoAudits()
    {
        return $this->hasMany(MemberPhotoAudit::class)->latest();
    }
}
