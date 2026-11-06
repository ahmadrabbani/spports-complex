<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FamilyMember extends Model
{
    protected $fillable = ['full_name', 'relationship', 'date_of_birth', 'parent_family_member_id'];

    protected $hidden = ['profile_image_path'];

    protected $appends = ['photo_url'];

    protected $casts = ['photo_updated_at' => 'datetime', 'membership_valid_until' => 'date:Y-m-d'];

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->profile_image_path ? route('family.photo', ['familyMember' => $this->id, 'v' => $this->photo_updated_at?->timestamp]) : null;
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
