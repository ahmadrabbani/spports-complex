<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChallanItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_previous_outstanding' => 'boolean',
    ];

    public function challan()
    {
        return $this->belongsTo(Challan::class);
    }

    public function charge()
    {
        return $this->belongsTo(MemberCharge::class, 'member_charge_id');
    }

    public function feeType()
    {
        return $this->belongsTo(FeeType::class);
    }
}
