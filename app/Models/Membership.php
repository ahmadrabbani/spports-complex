<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Membership extends Model
{
    protected $guarded = [];

    protected $casts = ['starts_on' => 'date', 'expires_on' => 'date'];

    public function plan()
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
