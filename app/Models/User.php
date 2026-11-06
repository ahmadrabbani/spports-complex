<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, \Laravel\Sanctum\HasApiTokens, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $attributes = ['is_active' => true, 'must_change_password' => false, 'is_complex_admin' => false];

    protected $fillable = ['name', 'email', 'cnic', 'phone', 'password', 'role', 'sports_complex_id', 'member_id', 'profile_image_path', 'is_active', 'must_change_password', 'is_complex_admin'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'is_complex_admin' => 'boolean',
            'sports_complex_id' => 'integer',
        ];
    }

    public function isRestrictedComplexAdmin(): bool
    {
        return $this->is_complex_admin || ($this->role === 'admin' && (bool) $this->sports_complex_id);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'admin' && ! $this->sports_complex_id && ! $this->is_complex_admin;
    }

    public function sportsComplex()
    {
        return $this->belongsTo(SportsComplex::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function portalNotifications()
    {
        return $this->hasMany(UserNotification::class)->latest();
    }
}
