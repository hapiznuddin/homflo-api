<?php

use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\HouseholdController;
use App\Http\Controllers\Api\HouseholdInvitationController;
use App\Http\Controllers\Api\HouseholdMemberController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\TransactionController;
use Illuminate\Support\Facades\Route;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', MeController::class);

    // Household catalogue reads are available to any household member,
    // like /me and /dashboard. Catalogue mutations follow the deferred
    // mandatory verification rule with the other household mutations.
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/{category}', [CategoryController::class, 'show']);

    // Read-only household aggregate. Like /me, it needs no `verified` gate;
    // missing households surface as 404 so the app can route to onboarding.
    Route::get('/dashboard', DashboardController::class);

    // Transaction ledger reads are available to any household member, like
    // /me and /dashboard. Recording, changing, and removing ledger rows
    // mutates shared household data, so writes follow the deferred
    // mandatory verification rule with the other household mutations.
    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::get('/transactions/{transaction}', [TransactionController::class, 'show']);
    Route::post('/password/change', [PasswordController::class, 'change']);
    Route::get('/invitations', [InvitationController::class, 'index']);
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1');

    // Member listing stays available through existing HouseholdPolicy
    // authorization so members can inspect their household without a
    // blanket verification gate.
    Route::get('/households/{household}/members', [HouseholdMemberController::class, 'index']);

    // First-household creation is part of onboarding: an authenticated user
    // may create it before verifying email. Verification remains optional
    // as an account/profile feature.
    Route::post('/households', [HouseholdController::class, 'store']);

    // Deferred mandatory verification: household mutation (other than
    // first-household creation) requires a verified email before existing
    // policy/business rules apply.
    Route::middleware('verified')->group(function () {
        Route::post('/households/{household}/invitations', [HouseholdInvitationController::class, 'store']);
        Route::patch('/households/{household}/members/{member}', [HouseholdMemberController::class, 'update']);
        Route::delete('/households/{household}/members/{member}', [HouseholdMemberController::class, 'destroy']);
        Route::post('/invitations/{invitation}/accept', [InvitationController::class, 'accept']);
        Route::post('/transactions', [TransactionController::class, 'store']);
        Route::patch('/transactions/{transaction}', [TransactionController::class, 'update']);
        Route::delete('/transactions/{transaction}', [TransactionController::class, 'destroy']);
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::patch('/categories/{category}', [CategoryController::class, 'update']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);
    });
});

// Email verification links are opened from an email client without a Homflo
// session, so verification relies on the signed URL itself (integrity +
// expiry) instead of Sanctum authentication.
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('api.verification.verify');

// Password recovery must work for users who cannot log in, so these routes
// stay outside auth:sanctum and email verification. Token security is fully
// owned by Laravel's password broker.
Route::post('/password/forgot', [PasswordResetController::class, 'forgot'])
    ->middleware('throttle:6,1');
Route::post('/password/reset', [PasswordResetController::class, 'reset'])
    ->middleware('throttle:6,1');
