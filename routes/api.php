<?php

use App\Http\Controllers\FamilyMemberController;
use App\Http\Controllers\MemberPhotoController;
use App\Http\Controllers\MembershipCardController;
use App\Http\Controllers\Mobile\AuthController;
use App\Http\Controllers\Mobile\BankController;
use App\Http\Controllers\Mobile\MemberController;
use App\Http\Controllers\PortalAccountController;
use App\Http\Controllers\PortalAppointmentController;
use App\Http\Controllers\PublicPortalController;
use App\Http\Middleware\EnsureMobileToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile/v1')->middleware('throttle:120,1,mobile-api')->group(function () {
    Route::get('portal', [PublicPortalController::class, 'index']);
    Route::get('sports-complexes/{sportsComplex:slug}', [PublicPortalController::class, 'show']);
    Route::middleware('throttle:5,1,mobile-auth')->group(function () {
        Route::post('auth/login', [AuthController::class, 'login']);
        Route::post('auth/register', [AuthController::class, 'register']);
        Route::post('auth/forgot-password', [AuthController::class, 'forgot']);
        Route::post('auth/reset-password', [AuthController::class, 'reset']);
    });
    Route::get('bank/return', fn () => response('Payment page closed. Return to the mobile app and verify payment. A browser return is not proof of payment.', 200)->header('Content-Type', 'text/plain')->header('Cache-Control', 'no-store'))->name('mobile.bank.return');
    Route::get('bank/checkout/{transaction:public_id}', [BankController::class, 'checkout'])->middleware('signed')->name('mobile.bank.checkout');
    Route::middleware(['auth:sanctum', EnsureMobileToken::class])->group(function () {
        Route::get('auth/me', fn (Request $request) => ['user' => $request->user()->only(['id', 'name', 'email', 'cnic', 'phone', 'member_id', 'must_change_password'])]);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::put('auth/password', [AuthController::class, 'password'])->middleware('throttle:5,1,mobile-write');
        Route::middleware('password.changed')->group(function () {
            Route::get('me', [MemberController::class, 'account']);
            Route::put('me', [MemberController::class, 'update']);
            Route::get('dashboard', [MemberController::class, 'dashboard']);
            Route::get('ledger', [MemberController::class, 'ledger']);
            Route::get('facilities', [MemberController::class, 'facilities']);
            Route::get('notifications', [MemberController::class, 'notifications']);
            Route::put('notifications/{notification}/read', [MemberController::class, 'readNotification']);
            Route::get('challans', [MemberController::class, 'challans']);
            Route::get('challans/{challan}', [MemberController::class, 'challan']);
            Route::get('challans/{challan}/print', [PortalAccountController::class, 'printChallan']);
            Route::get('me/card.pdf', [MembershipCardController::class, 'portalPdf']);
            Route::get('me/photo', fn (Request $r, MemberPhotoController $c) => $r->user()->member ? $c->show($r, $r->user()->member) : abort(404));
            Route::get('family-members', fn (Request $r, FamilyMemberController $c) => $r->user()->member ? $c->index($r, $r->user()->member) : abort(404));
            Route::post('family-members', fn (Request $r, FamilyMemberController $c) => $r->user()->member ? $c->store($r, $r->user()->member) : abort(404));
            Route::get('appointment-slots', [PortalAppointmentController::class, 'slots']);
            Route::post('appointments', [PortalAppointmentController::class, 'store']);
            Route::get('appointments/{appointment:public_id}', [MemberController::class, 'appointment']);
            Route::post('appointments/{appointment:public_id}/cancel', [PortalAppointmentController::class, 'cancel']);
            Route::post('challans/{challan}/bank/initiate', [BankController::class, 'initiate'])->middleware('throttle:5,1,mobile-write');
            Route::get('bank-transactions/{transaction:public_id}', [BankController::class, 'show']);
            Route::post('bank-transactions/{transaction:public_id}/verify', [BankController::class, 'verify'])->middleware('throttle:10,1,mobile-verify');
            Route::get('bank-transactions/{transaction:public_id}/receipt', [BankController::class, 'receipt']);
        });
    });
});
