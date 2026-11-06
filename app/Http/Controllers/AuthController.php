<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::attempt([...$credentials, 'is_active' => true], $request->boolean('remember')) || $request->user()->role === 'member') {
            Auth::logout();

            return response()->json(['message' => 'The supplied credentials are not valid.'], 422);
        }
        $user = $request->user();
        if (! in_array($user->role, ['admin', 'manager', 'operator'], true) || (($user->sports_complex_id || $user->is_complex_admin) && (! $user->sports_complex_id || ! $user->sportsComplex?->is_active))) {
            Auth::logout();

            return response()->json(['message' => 'This account does not have an active sports complex assignment.'], 422);
        }
        $request->session()->regenerate();

        return response()->json(['user' => $request->user()->load('sportsComplex:id,name,code')]);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
