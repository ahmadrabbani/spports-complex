<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppointmentSchedule extends Model
{
    protected $guarded = [];

    protected $casts = ['working_days' => 'array', 'is_active' => 'boolean'];

    public function sportsComplex()
    {
        return $this->belongsTo(SportsComplex::class);
    }
}
