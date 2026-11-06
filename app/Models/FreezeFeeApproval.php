<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FreezeFeeApproval extends Model
{
    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:2'];

    public function freeze()
    {
        return $this->belongsTo(MembershipFreeze::class, 'membership_freeze_id');
    }

    public function feeType()
    {
        return $this->belongsTo(FeeType::class);
    }
}
