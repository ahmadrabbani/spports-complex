<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankTransaction extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'last_response' => 'array',
        'session_created_at' => 'datetime',
        'last_verified_at' => 'datetime',
        'verified_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function challan()
    {
        return $this->belongsTo(Challan::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function logs()
    {
        return $this->hasMany(BankApiLog::class);
    }
}
