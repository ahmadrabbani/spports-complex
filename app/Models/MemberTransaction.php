<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberTransaction extends Model
{
    protected $guarded = [];

    protected $casts = [
        'document_date' => 'date',
        'amount' => 'decimal:2',
        'date_is_anomalous' => 'boolean',
        'imported_at' => 'datetime',
        'sap_payment_checked_at' => 'datetime',
    ];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function sportsComplex()
    {
        return $this->belongsTo(SportsComplex::class);
    }
}
