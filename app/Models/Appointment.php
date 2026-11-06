<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'appointment_date' => 'date',
        'documents_verified' => 'boolean',
        'file_entry_completed' => 'boolean',
        'file_entry_completed_at' => 'datetime',
        'form_number_assigned_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sportsComplex()
    {
        return $this->belongsTo(SportsComplex::class);
    }

    public function purpose()
    {
        return $this->belongsTo(AppointmentPurpose::class, 'appointment_purpose_id');
    }

    public function events()
    {
        return $this->hasMany(AppointmentEvent::class)->oldest();
    }
}
