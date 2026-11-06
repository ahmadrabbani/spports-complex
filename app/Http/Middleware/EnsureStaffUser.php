<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffUser
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user() && in_array($request->user()->role, ['admin', 'manager', 'operator'], true), 403);

        if ($request->is('api/logout')) {
            return $next($request);
        }
        $user = $request->user();
        abort_unless($user->is_active, 403, 'This staff account is disabled.');
        if ($user->is_complex_admin || $user->sports_complex_id) {
            abort_unless($user->sports_complex_id && $user->sportsComplex?->is_active, 403, 'An active sports complex assignment is required.');
        }
        if ($user->must_change_password && ! $request->is('api/me', 'api/logout', 'api/staff/password')) {
            return response()->json(['message' => 'Change your temporary password before accessing customer records.', 'code' => 'PASSWORD_CHANGE_REQUIRED'], 409);
        }

        if ($user->isRestrictedComplexAdmin() && ! $request->isMethodSafe()) {
            $allowed = ($request->isMethod('POST') && preg_match('#^api/members/[0-9]+/(photo|family-members|documents)$#D', $request->path()))
                || ($request->isMethod('POST') && preg_match('#^api/member-documents/[0-9]+/(extract-family|confirm-family)$#D', $request->path()))
                || ($request->isMethod('PUT') && $request->is('api/staff/password'));
            abort_unless($allowed, 403, 'This account can only add family members, upload a member picture and upload one offer letter. Member data is read-only.');
        }

        return $next($request);
    }
}
