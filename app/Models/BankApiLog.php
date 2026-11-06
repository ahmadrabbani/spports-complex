<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankApiLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'requested_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function transaction()
    {
        return $this->belongsTo(BankTransaction::class, 'bank_transaction_id');
    }
}
