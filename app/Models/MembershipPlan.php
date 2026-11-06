<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MembershipPlan extends Model
{
    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean', 'joining_fee' => 'decimal:2', 'recurring_fee' => 'decimal:2'];

    public function facilities()
    {
        return $this->belongsToMany(Facility::class, 'facility_membership_plan');
    }
}
