<?php

use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureMemberUser;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureStaffUser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('web', EnsureActiveAccount::class);
        $middleware->redirectGuestsTo(fn () => url('/portal-login'));
        $middleware->alias([
            'staff' => EnsureStaffUser::class,
            'member' => EnsureMemberUser::class,
            'password.changed' => EnsurePasswordChanged::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn ($request, $e) => $request->is('api/mobile/*') || $request->expectsJson());
    })->create();
