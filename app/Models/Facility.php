<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Facility extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'hourly_rate' => 'decimal:2',
        'maintenance_starts_at' => 'datetime',
        'maintenance_ends_at' => 'datetime',
    ];

    public function sportsComplex()
    {
        return $this->belongsTo(SportsComplex::class);
    }

    public function membershipPlans()
    {
        return $this->belongsToMany(MembershipPlan::class, 'facility_membership_plan');
    }
}
