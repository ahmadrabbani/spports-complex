<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            return response()->json([
                'message' => 'Change the temporary password before accessing your account.',
                'code' => 'PASSWORD_CHANGE_REQUIRED',
            ], 409);
        }

        return $next($request);
    }
}
