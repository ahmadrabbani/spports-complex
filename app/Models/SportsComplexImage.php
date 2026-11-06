<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SportsComplexImage extends Model
{
    protected $guarded = [];

    protected $casts = ['is_featured' => 'boolean'];

    public function sportsComplex()
    {
        return $this->belongsTo(SportsComplex::class);
    }
}
