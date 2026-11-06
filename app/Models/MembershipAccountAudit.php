<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MembershipAccountAudit extends Model
{
    protected $guarded = [];

    protected $casts = [];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
