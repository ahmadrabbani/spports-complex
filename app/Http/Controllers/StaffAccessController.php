<?php

namespace App\Http\Controllers;

use App\Models\SportsComplex;
use App\Models\StaffAccessAudit;
use App\Models\User;
use App\Services\StaffAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StaffAccessController extends Controller
{
    private function super(Request $r): void
    {
        abort_unless($r->user()->isSuperAdmin(), 403, 'Only the central administrator can manage staff access.');
    }

    public function index(Request $r)
    {
        $this->super($r);

        return response()->json(['users' => User::where('role', 'admin')->where(fn ($q) => $q->where('is_complex_admin', true)->orWhereNotNull('sports_complex_id'))->with('sportsComplex:id,name,code')->orderBy('name')->get(), 'complexes' => SportsComplex::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code'])])->header('Cache-Control', 'no-store');
    }

    public function store(Request $r, StaffAccessService $service)
    {
        $this->super($r);
        $r->merge(['email' => strtolower(trim((string) $r->input('email')))]);
        $data = $r->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'], 'sports_complex_id' => ['required', 'integer', Rule::exists('sports_complexes', 'id')->where('is_active', true)], 'role' => ['prohibited'], 'member_id' => ['prohibited'], 'is_complex_admin' => ['prohibited']]);

        return response()->json($service->createComplexAdmin($data, $r->user()), 201)->header('Cache-Control', 'no-store');
    }

    public function update(Request $r, User $staffUser, StaffAccessService $service)
    {
        $this->super($r);
        abort_unless($staffUser->role === 'admin' && ($staffUser->is_complex_admin || $staffUser->sports_complex_id), 403, 'Central administrator accounts cannot be changed here.');
        if ($r->has('email')) {
            $r->merge(['email' => strtolower(trim((string) $r->input('email')))]);
        }
        $data = $r->validate(['name' => ['sometimes', 'required', 'string', 'max:255'], 'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users')->ignore($staffUser)], 'sports_complex_id' => ['sometimes', 'required', 'integer', Rule::exists('sports_complexes', 'id')->where('is_active', true)], 'is_active' => ['sometimes', 'boolean'], 'role' => ['prohibited'], 'member_id' => ['prohibited'], 'password' => ['prohibited'], 'is_complex_admin' => ['prohibited']]);

        return DB::transaction(function () use ($r, $staffUser, $service, $data) {
            $u = User::lockForUpdate()->findOrFail($staffUser->id);
            $before = $service->snapshot($u);
            $u->update($data + ['is_complex_admin' => true]);
            if ($u->wasChanged()) {
                StaffAccessAudit::create(['user_id' => $u->id, 'actor_id' => $r->user()->id, 'action' => 'updated', 'before' => $before, 'after' => $service->snapshot($u)]);
            }

            return response()->json($u->fresh('sportsComplex:id,name,code'))->header('Cache-Control', 'no-store');
        });
    }

    public function password(Request $r)
    {
        $data = $r->validate(['current_password' => ['required', 'current_password:web'], 'password' => ['required', 'confirmed', 'different:current_password', Password::min(12)->mixedCase()->numbers()->symbols()]]);
        $user = $r->user();
        DB::transaction(function () use ($user, $data) {
            $user->update(['password' => Hash::make($data['password']), 'must_change_password' => false, 'remember_token' => null]);
            StaffAccessAudit::create(['user_id' => $user->id, 'actor_id' => $user->id, 'action' => 'password_changed', 'after' => ['must_change_password' => false]]);
        });
        $r->session()->regenerate();

        return response()->json(['user' => $user->fresh('sportsComplex:id,name,code')])->header('Cache-Control', 'no-store');
    }
}
