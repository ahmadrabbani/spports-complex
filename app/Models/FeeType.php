<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeeType extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean', 'is_freeze_fee' => 'boolean', 'freeze_amount' => 'decimal:2'];

    public function charges()
    {
        return $this->hasMany(MemberCharge::class);
    }
}
