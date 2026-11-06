<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SportsComplex extends Model
{
    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean', 'sap_center_options' => 'array'];

    public function setCostCenterAttribute($value): void
    {
        $this->attributes['cost_center'] = $this->padCenter($value, 10);
    }

    public function setProfitCenterAttribute($value): void
    {
        $this->attributes['profit_center'] = $this->padCenter($value, 9);
    }

    private function padCenter($value, int $width): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $value = trim((string) $value);

        return ctype_digit($value) && strlen($value) <= $width ? str_pad($value, $width, '0', STR_PAD_LEFT) : $value;
    }

    public function staffUsers()
    {
        return $this->hasMany(User::class);
    }

    public function members()
    {
        return $this->hasMany(Member::class);
    }

    public function hasSapCenters(): bool
    {
        return (bool) preg_match('/^\d{10}$/D', (string) $this->cost_center)
            && (bool) preg_match('/^\d{9}$/D', (string) $this->profit_center);
    }

    public function facilities()
    {
        return $this->hasMany(Facility::class);
    }

    public function images()
    {
        return $this->hasMany(SportsComplexImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function appointmentSchedule()
    {
        return $this->hasOne(AppointmentSchedule::class);
    }
}
