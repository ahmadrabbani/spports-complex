<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MembershipCategory extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean', 'is_freeze' => 'boolean'];

    public function account()
    {
        return $this->belongsTo(ReconciliationAccount::class, 'reconciliation_account_id');
    }
}
