<?php

namespace App\Http\Middleware;

use App\Models\SportsComplex;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/logout', 'api/portal/logout')) {
            return $next($request);
        }
        if ($user = $request->user()) {
            $user->refresh();
            $valid = $user->is_active;
            if ($user->role !== 'member' && ($user->sports_complex_id || $user->is_complex_admin)) {
                $valid = $valid && $user->sports_complex_id && SportsComplex::whereKey($user->sports_complex_id)->where('is_active', true)->exists();
            }
            if (! $valid) {
                Auth::logout();
                if ($request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }
                abort(403, 'This account is disabled or has no active sports complex assignment. Contact the central administrator.');
            }
        }

        return $next($request);
    }
}
