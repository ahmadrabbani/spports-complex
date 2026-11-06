<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberCharge extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'due_on' => 'date',
        'paid_at' => 'datetime',
        'source_payload' => 'array',
    ];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function feeType()
    {
        return $this->belongsTo(FeeType::class);
    }

    public function challanItems()
    {
        return $this->hasMany(ChallanItem::class);
    }
}
