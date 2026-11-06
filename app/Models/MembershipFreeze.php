<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MembershipFreeze extends Model
{
    protected $guarded = [];

    protected $casts = ['membership_states' => 'array', 'starts_on' => 'date', 'ends_on' => 'date', 'unfrozen_at' => 'datetime'];

    public function actor()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvals()
    {
        return $this->hasMany(FreezeFeeApproval::class);
    }
}
