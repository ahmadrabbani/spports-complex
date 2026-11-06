<?php

use App\Http\Controllers\AccountingConfigurationController;
use App\Http\Controllers\AdminAppointmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankPaymentController;
use App\Http\Controllers\ChallanController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FamilyMemberController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\MemberPhotoController;
use App\Http\Controllers\MembershipCardController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentTestingController;
use App\Http\Controllers\PortalAccountController;
use App\Http\Controllers\PortalAppointmentController;
use App\Http\Controllers\PortalAuthController;
use App\Http\Controllers\PublicPortalController;
use App\Http\Controllers\ReferenceDataController;
use App\Http\Controllers\SportsComplexImageController;
use Illuminate\Support\Facades\Route;

Route::get('/family-members/verify/{familyMember}', [FamilyMemberController::class, 'verify'])->name('family.verify');
Route::middleware(['auth', 'password.changed'])->group(function () {
    Route::get('/family-members/{familyMember}/photo', [FamilyMemberController::class, 'photo'])->name('family.photo');
    Route::post('/family-members/{familyMember}/photo', [FamilyMemberController::class, 'uploadPhoto'])->middleware('throttle:20,1');
    Route::get('/family-members/{familyMember}/card', [FamilyMemberController::class, 'card'])->name('family.card');
    Route::get('/family-members/{familyMember}/card.pdf', [FamilyMemberController::class, 'card'])->name('family.card.pdf');
});

Route::view('/', 'app')->name('app');
Route::get('/reset-password/{token}', fn (string $token) => view('app'))->middleware('guest')->name('password.reset');
Route::post('/login', [AuthController::class, 'login'])->middleware(['guest','throttle:5,1,staff-login']);
Route::get('/membership/verify/{member}', [MembershipCardController::class, 'verify'])->name('membership.verify');
Route::get('/member-photos/{member}', [MemberPhotoController::class, 'show'])->middleware(['auth','password.changed'])->name('members.photo');
Route::prefix('api/public')->group(function () {
    Route::get('/gallery/three.module.js', fn () => response()->file(
        public_path('vendor/three/three.module.js'),
        ['Content-Type' => 'application/javascript; charset=utf-8', 'Access-Control-Allow-Origin' => '*']
    ));
    Route::get('/gallery/three.core.js', fn () => response()->file(
        public_path('vendor/three/three.core.js'),
        ['Content-Type' => 'application/javascript; charset=utf-8', 'Access-Control-Allow-Origin' => '*']
    ));
    Route::get('/portal', [PublicPortalController::class, 'index']);
    Route::get('/sports-complexes/{sportsComplex:slug}', [PublicPortalController::class, 'show']);
    Route::post('/register', [PortalAuthController::class, 'register'])->middleware('guest');
    Route::post('/login', [PortalAuthController::class, 'login']);
    Route::post('/forgot-password', [PortalAuthController::class, 'forgot'])->middleware('guest');
    Route::post('/reset-password', [PortalAuthController::class, 'reset'])->middleware('guest');
});
Route::middleware(['auth', 'member'])->prefix('api/portal')->group(function () {
    Route::get('/me', fn () => request()->user());
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/password', [PortalAccountController::class, 'password']);
    Route::middleware('password.changed')->group(function () {
        Route::get('/members/{member}/family-members', [FamilyMemberController::class, 'index']);
        Route::post('/members/{member}/family-members', [FamilyMemberController::class, 'store']);
        Route::get('/account', [PortalAccountController::class, 'show']);
        Route::put('/account', [PortalAccountController::class, 'update']);
        Route::get('/dashboard', [PortalAppointmentController::class, 'dashboard']);
        Route::get('/ledger', [PortalAppointmentController::class, 'ledger']);
        Route::get('/facilities', [PortalAccountController::class, 'facilities']);
        Route::get('/challans/{challan}/print', [PortalAccountController::class, 'printChallan']);
        Route::post('/challans/{challan}/bank/initiate', [BankPaymentController::class, 'portalInitiate']);
        Route::post('/bank-transactions/{transaction:public_id}/verify', [BankPaymentController::class, 'portalVerify']);
        Route::get('/bank-transactions/{transaction:public_id}/receipt', [BankPaymentController::class, 'receipt'])->name('bank.receipt');
        Route::get('/appointment-slots', [PortalAppointmentController::class, 'slots']);
        Route::post('/appointments', [PortalAppointmentController::class, 'store']);
        Route::get('/appointments/{appointment:public_id}', [PortalAppointmentController::class, 'show']);
        Route::post('/appointments/{appointment:public_id}/cancel', [PortalAppointmentController::class, 'cancel']);
    });
});
Route::middleware(['auth', 'staff'])->prefix('api')->group(function () {
    Route::get('/members/{member}/documents', [\App\Http\Controllers\MemberDocumentController::class,'index']);
    Route::post('/members/{member}/documents', [\App\Http\Controllers\MemberDocumentController::class,'store'])->middleware('throttle:20,1');
    Route::post('/member-documents/{document}/extract-family', [\App\Http\Controllers\MemberDocumentController::class,'extractFamily'])->middleware('throttle:5,1');
    Route::post('/member-documents/{document}/confirm-family', [\App\Http\Controllers\MemberDocumentController::class,'confirmFamily'])->middleware('throttle:10,1');
    Route::get('/member-documents/{document}/download', [\App\Http\Controllers\MemberDocumentController::class,'download'])->name('member-documents.download');

    Route::post('/challans/{challan}/mock-payment', [PaymentTestingController::class, 'mock']);
    Route::post('/mock-transactions/{transaction}/confirm', [PaymentTestingController::class, 'confirm']);
    Route::get('/mock-transactions/{transaction}/receipt', [PaymentTestingController::class, 'receipt']);
    Route::post('/challans/{challan}/verify-payment', [PaymentTestingController::class, 'verify'])->middleware('throttle:10,1');
    Route::get('/challans/{challan}/payment-history', [PaymentTestingController::class, 'history']);

    Route::get('/me', fn () => request()->user()->load('sportsComplex:id,name,code'));
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/staff/password', [\App\Http\Controllers\StaffAccessController::class,'password'])->middleware('throttle:5,1,staff-password');
    Route::get('/staff-users', [\App\Http\Controllers\StaffAccessController::class,'index']);
    Route::post('/staff-users', [\App\Http\Controllers\StaffAccessController::class,'store']);
    Route::put('/staff-users/{staffUser}', [\App\Http\Controllers\StaffAccessController::class,'update']);
    Route::get('/dashboard', DashboardController::class);
    Route::get('/reference-data', ReferenceDataController::class);
    Route::get('/accounting/reconciliation', [\App\Http\Controllers\ReconciliationController::class,'index']);
    Route::post('/accounting/reconciliation-accounts', [\App\Http\Controllers\ReconciliationController::class,'storeAccount']);
    Route::put('/accounting/reconciliation-accounts/{account}', [\App\Http\Controllers\ReconciliationController::class,'updateAccount']);
    Route::put('/accounting/membership-categories/{category}', [\App\Http\Controllers\ReconciliationController::class,'updateCategory']);
    Route::put('/accounting/membership-plans/{plan}', [\App\Http\Controllers\ReconciliationController::class,'updatePlan']);
    Route::put('/members/{member}/category', [\App\Http\Controllers\ReconciliationController::class,'assign']);
    Route::post('/members/{member}/freeze', [\App\Http\Controllers\ReconciliationController::class,'freeze']);
    Route::post('/members/{member}/unfreeze', [\App\Http\Controllers\ReconciliationController::class,'unfreeze']);
    Route::post('/members/{member}/freeze-fee-approvals', [\App\Http\Controllers\ReconciliationController::class,'approveFee']);
    Route::post('/challans/{challan}/adjust', [ChallanController::class,'adjust']);
    Route::get('/challans/{challan}/adjustments', [ChallanController::class,'adjustments']);
    Route::get('/members/{member}/family-members', [FamilyMemberController::class, 'index']);
    Route::post('/members/{member}/family-members', [FamilyMemberController::class, 'store']);
    Route::get('/members/{member}/ledger', [MemberController::class, 'ledger']);
    Route::apiResource('members', MemberController::class);
    Route::post('/members/{member}/photo', [MemberPhotoController::class, 'update']);
    Route::post('/members/{member}/memberships', [MembershipController::class, 'store']);
    Route::put('/memberships/{membership}', [MembershipController::class, 'update']);
    Route::post('/members/{member}/payments', [PaymentController::class, 'store']);
    Route::get('/challans', [ChallanController::class, 'index']);
    Route::post('/members/{member}/challan-preview', [ChallanController::class, 'preview']);
    Route::post('/challans', [ChallanController::class, 'store']);
    Route::post('/challans/{challan}/retry', [ChallanController::class, 'retry']);
    Route::put('/challans/{challan}', [ChallanController::class, 'update']);
    Route::put('/accounting/fee-types/{feeType}', [AccountingConfigurationController::class, 'updateFeeType']);
    Route::put('/accounting/sports-complexes/{sportsComplex}', [AccountingConfigurationController::class, 'updateComplex']);
    Route::get('/bank/transactions', [BankPaymentController::class, 'index']);
    Route::post('/bank/diagnostics/test-connection', [BankPaymentController::class, 'diagnostic']);
    Route::post('/bank/transactions/{transaction}/verify', [BankPaymentController::class, 'staffVerify']);
    Route::post('/accounting/sports-complexes/{sportsComplex}/images', [SportsComplexImageController::class, 'store']);
    Route::put('/accounting/sports-complexes/{sportsComplex}/images/{sportsComplexImage}', [SportsComplexImageController::class, 'update']);
    Route::delete('/accounting/sports-complexes/{sportsComplex}/images/{sportsComplexImage}', [SportsComplexImageController::class, 'destroy']);
    Route::get('/admin/appointments', [AdminAppointmentController::class, 'index']);
    Route::get('/admin/appointments/{appointment}', [AdminAppointmentController::class, 'show']);
    Route::post('/admin/appointments/{appointment}/transition', [AdminAppointmentController::class, 'transition']);
    Route::post('/admin/appointments/{appointment}/file-entry', [AdminAppointmentController::class, 'fileEntry']);
    Route::post('/admin/appointments/{appointment}/assign-form', [AdminAppointmentController::class, 'assignForm']);
    Route::post('/admin/appointment-purposes', [AdminAppointmentController::class, 'updatePurpose']);
    Route::put('/admin/appointment-purposes/{appointmentPurpose}', [AdminAppointmentController::class, 'updatePurpose']);
    Route::put('/admin/sports-complexes/{sportsComplex}/appointment-schedule', [AdminAppointmentController::class, 'updateSchedule']);
    Route::post('/admin/sports-complexes/{sportsComplex}/facilities', [AdminAppointmentController::class, 'storeFacility']);
    Route::post('/admin/facilities/bulk', [AdminAppointmentController::class, 'storeFacilitiesBulk']);
    Route::put('/admin/facilities/{facility}', [AdminAppointmentController::class, 'updateFacility']);
    Route::delete('/admin/facilities/{facility}', [AdminAppointmentController::class, 'destroyFacility']);
    Route::post('/admin/sports-complexes/{sportsComplex}/blocked-dates', [AdminAppointmentController::class, 'blockDate']);
    Route::delete('/admin/sports-complexes/{sportsComplex}/blocked-dates/{date}', [AdminAppointmentController::class, 'unblockDate']);
});
Route::middleware(['auth', 'staff'])->group(function () {
    Route::get('/members/{member}/membership-card', [MembershipCardController::class, 'show'])->name('members.card');
    Route::get('/members/{member}/membership-card.pdf', [MembershipCardController::class, 'pdf'])->name('members.card.pdf');
});
Route::middleware(['auth', 'member', 'password.changed'])->group(function () {
    Route::get('/my-membership-card', [MembershipCardController::class, 'portalShow'])->name('portal.card');
    Route::get('/my-membership-card.pdf', [MembershipCardController::class, 'portalPdf'])->name('portal.card.pdf');
});
Route::get('/challans/{challan}/print', [ChallanController::class, 'print'])->middleware(['auth', 'staff'])->name('challans.print');
Route::middleware(['auth', 'member', 'password.changed'])->group(function () {
    Route::get('/bank/checkout/{transaction:public_id}', [BankPaymentController::class, 'checkout'])->name('bank.checkout');
    Route::get('/bank/return/{transaction:public_id}', [BankPaymentController::class, 'returned'])->name('bank.return');
});
Route::view('/{path}', 'app')->where('path', '^(?!api|login).*$');
