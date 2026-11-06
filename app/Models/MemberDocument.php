<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberDocument extends Model
{
    protected $guarded = [];

    protected $hidden = ['path', 'extraction_payload'];

    protected $casts = ['extraction_payload' => 'encrypted:array', 'extracted_at' => 'datetime'];

    protected $appends = ['download_url', 'family_extraction'];

    public function getFamilyExtractionAttribute(): ?array
    {
        $p = $this->extraction_payload;

        return $p ? array_intersect_key($p, array_flip(['membership_number', 'sponsor_name', 'rows', 'issues', 'created', 'skipped', 'method'])) : null;
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function getDownloadUrlAttribute(): string
    {
        return route('member-documents.download', $this);
    }
}
