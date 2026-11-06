<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReconciliationAccount extends Model
{
    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];

    public function categories()
    {
        return $this->hasMany(MembershipCategory::class);
    }
}
