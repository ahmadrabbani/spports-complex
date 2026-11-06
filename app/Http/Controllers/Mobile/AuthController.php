<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate(['login' => 'required|string|max:255', 'password' => 'required|string|max:1024', 'device_name' => 'required|string|max:100']);
        $field = filter_var($data['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'cnic';
        $login = $field === 'cnic' ? preg_replace('/\D+/', '', $data['login']) : strtolower(trim($data['login']));
        $user = User::where($field, $login)->where('role', 'member')->where('is_active', true)->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['login' => ['The supplied member credentials are not valid.']]);
        }

        return response()->json($this->token($user, $data['device_name']))->header('Cache-Control', 'no-store');
    }

    public function register(Request $request)
    {
        $request->merge(['cnic' => preg_replace('/\D+/', '', (string) $request->input('cnic')), 'email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => 'required|string|max:255', 'cnic' => 'required|digits:13|unique:users,cnic',
            'email' => 'required|email:rfc|max:255|unique:users,email', 'phone' => ['required', 'regex:/^03\d{9}$/'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()->mixedCase()],
            'device_name' => 'required|string|max:100',
        ]);
        // Knowledge of a CNIC alone must never grant access to an existing financial record.
        if (Member::where('cnic', $data['cnic'])->exists()) {
            throw ValidationException::withMessages(['cnic' => ['An existing member record requires staff-assisted account provisioning.']]);
        }

        return DB::transaction(function () use ($data) {
            $user = User::create(collect($data)->except('device_name')->all() + ['role' => 'member', 'is_active' => true, 'must_change_password' => false]);

            return response()->json($this->token($user, $data['device_name']), 201)->header('Cache-Control', 'no-store');
        });
    }

    public function password(Request $request)
    {
        $data = $request->validate(['current_password' => 'required|string', 'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()->mixedCase()]]);
        if (! Hash::check($data['current_password'], $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => ['The password is incorrect.']]);
        }
        DB::transaction(function () use ($request, $data) {
            $request->user()->forceFill(['password' => Hash::make($data['password']), 'must_change_password' => false, 'remember_token' => Str::random(60)])->save();
            $request->user()->tokens()->delete();
        });

        return ['message' => 'Password updated. All device tokens revoked. Sign in again.'];
    }

    public function forgot(Request $request)
    {
        $data = $request->validate(['email' => 'required|email']);
        // Same response for unknown, staff, inactive and member addresses.
        if (User::where('email', $data['email'])->where('role', 'member')->where('is_active', true)->exists()) {
            Password::sendResetLink($data);
        }

        return ['message' => 'If an eligible account exists, a reset link will be emailed.'];
    }

    public function reset(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'token' => 'required|string', 'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()->mixedCase()]]);
        $user = User::where('email', $data['email'])->where('role', 'member')->where('is_active', true)->first();
        $status = $user ? Password::reset($data, function (User $user, string $password) {
            DB::transaction(function () use ($user, $password) {
                $user->forceFill(['password' => Hash::make($password), 'must_change_password' => false, 'remember_token' => Str::random(60)])->save();
                $user->tokens()->delete();
            });
            event(new PasswordReset($user));
        }) : Password::INVALID_TOKEN;
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => ['The reset token or account is invalid or expired.']]);
        }

        return ['message' => 'Password reset. Sign in again.'];
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    private function token(User $user, string $device): array
    {
        $expires = now()->addDays(30);

        return ['token_type' => 'Bearer', 'access_token' => $user->createToken($device, ['mobile:member'], $expires)->plainTextToken,
            'expires_at' => $expires->toISOString(), 'must_change_password' => $user->must_change_password,
            'user' => $user->only(['id', 'name', 'email', 'cnic', 'phone', 'member_id', 'must_change_password'])];
    }
}
