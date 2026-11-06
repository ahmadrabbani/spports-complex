<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\MemberPhotoAudit;
use App\Models\User;
use App\Services\MemberPhotoService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PortalAuthController extends Controller
{
    public function __construct(private MemberPhotoService $photos) {}

    public function register(Request $request)
    {
        $request->merge(['cnic' => $this->cnic($request->input('cnic')), 'phone' => $this->phone($request->input('phone'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'cnic' => ['required', 'digits:13', 'unique:users,cnic'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'regex:/^03\d{9}$/'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
            'photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192', 'dimensions:min_width=240,min_height=240,max_width=6000,max_height=6000'],
        ]);
        $member = Member::where('cnic', $data['cnic'])->first();
        $user = User::create([...$data, 'role' => 'member', 'member_id' => $member?->id, 'is_active' => true]);
        if ($member?->profile_image_path) {
            $user->update(['profile_image_path' => $member->profile_image_path]);
        } elseif ($request->hasFile('photo')) {
            $path = $this->photos->store($request->file('photo'), $member ? 'member-photos/'.$member->id : 'applicant-photos/'.$user->id);
            $user->update(['profile_image_path' => $path]);
            if ($member) {
                $member->update(['profile_image_path' => $path, 'photo_updated_at' => now(), 'photo_updated_by' => null]);
            }
            MemberPhotoAudit::create([
                'member_id' => $member?->id,
                'applicant_user_id' => $user->id,
                'actor_user_id' => $user->id,
                'action' => 'uploaded',
                'source' => 'registration',
                'new_path' => $path,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
            ]);
        }
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $user], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['login' => ['required', 'string'], 'password' => ['required', 'string']]);
        $field = filter_var($data['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'cnic';
        $login = $field === 'cnic' ? $this->cnic($data['login']) : strtolower($data['login']);
        if (! Auth::attempt([$field => $login, 'password' => $data['password'], 'role' => 'member', 'is_active' => true], $request->boolean('remember'))) {
            return response()->json(['message' => 'The supplied portal credentials are not valid.'], 422);
        }
        $request->session()->regenerate();

        return ['user' => $request->user()];
    }

    public function forgot(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);
        $status = Password::sendResetLink($request->only('email'));

        return $status === Password::RESET_LINK_SENT ? response()->json(['message' => __($status)]) : response()->json(['message' => __($status)], 422);
    }

    public function reset(Request $request)
    {
        $data = $request->validate(['token' => ['required'], 'email' => ['required', 'email'], 'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()]]);
        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            $user->tokens()->delete();
            event(new PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET ? response()->json(['message' => __($status)]) : response()->json(['message' => __($status)], 422);
    }

    private function cnic(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value);
    }

    private function phone(?string $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        if (str_starts_with($digits, '92') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 2);
        }

        return $digits;
    }
}
