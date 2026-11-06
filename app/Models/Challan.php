<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Challan extends Model
{
    protected $hidden = ['sap_payload', 'request_url', 'response_payload'];

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'previous_outstanding' => 'decimal:2',
        'current_amount' => 'decimal:2',
        'issued_on' => 'date',
        'due_on' => 'date',
        'sap_posted_at' => 'datetime',
        'sap_payload' => 'array',
        'response_payload' => 'array',
        'generated_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function adjustments()
    {
        return $this->hasMany(ChallanAdjustment::class)->latest('id');
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function sportsComplex()
    {
        return $this->belongsTo(SportsComplex::class);
    }

    public function membership()
    {
        return $this->belongsTo(Membership::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items()
    {
        return $this->hasMany(ChallanItem::class);
    }

    public function regeneratedChallan()
    {
        return $this->belongsTo(self::class, 'regenerates_challan_id');
    }

    public function bankTransactions()
    {
        return $this->hasMany(BankTransaction::class);
    }
}
