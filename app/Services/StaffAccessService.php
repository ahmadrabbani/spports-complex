<?php

namespace App\Services;

use App\Models\StaffAccessAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StaffAccessService
{
    public function createComplexAdmin(array $data, ?User $actor = null): array
    {
        return DB::transaction(function () use ($data, $actor) {
            $password = Str::password(24);
            $user = User::create(['name' => $data['name'], 'email' => strtolower($data['email']), 'sports_complex_id' => $data['sports_complex_id'], 'role' => 'admin', 'is_complex_admin' => true, 'is_active' => true, 'password' => $password, 'must_change_password' => true]);
            StaffAccessAudit::create(['user_id' => $user->id, 'actor_id' => $actor?->id, 'action' => 'created', 'after' => $this->snapshot($user)]);

            return ['user' => $user->load('sportsComplex:id,name,code'), 'temporary_password' => $password];
        });
    }

    public function snapshot(User $user): array
    {
        return $user->only(['name', 'email', 'sports_complex_id', 'role', 'is_complex_admin', 'is_active', 'must_change_password']);
    }
}
