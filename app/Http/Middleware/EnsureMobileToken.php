<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class EnsureMobileToken
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->bearerToken() && $request->user()?->currentAccessToken() instanceof PersonalAccessToken, 401);
        abort_unless($request->user()->is_active && $request->user()->role === 'member' && $request->user()->tokenCan('mobile:member'), 403);

        return $next($request)->header('Cache-Control', 'no-store');
    }
}
