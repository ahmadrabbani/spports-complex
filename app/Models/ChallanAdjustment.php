<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChallanAdjustment extends Model
{
    protected $guarded = [];

    protected $casts = ['old_amount' => 'decimal:2', 'new_amount' => 'decimal:2', 'original_snapshot' => 'array', 'proposed_snapshot' => 'array'];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
