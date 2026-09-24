<?php

use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\HouseholdController;
use App\Http\Controllers\Api\HouseholdInvitationController;
use App\Http\Controllers\Api\HouseholdMemberController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\PasswordResetController;
use Illuminate\Support\Facades\Route;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', MeController::class);
    Route::post('/password/change', [PasswordController::class, 'change']);
    Route::get('/invitations', [InvitationController::class, 'index']);
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1');

    // Member listing stays available through existing HouseholdPolicy
    // authorization so members can inspect their household without a
    // blanket verification gate.
    Route::get('/households/{household}/members', [HouseholdMemberController::class, 'index']);

    // Deferred mandatory verification: household activation and mutation
    // require a verified email before existing policy/business rules apply.
    Route::middleware('verified')->group(function () {
        Route::post('/households', [HouseholdController::class, 'store']);
        Route::post('/households/{household}/invitations', [HouseholdInvitationController::class, 'store']);
        Route::patch('/households/{household}/members/{member}', [HouseholdMemberController::class, 'update']);
        Route::delete('/households/{household}/members/{member}', [HouseholdMemberController::class, 'destroy']);
        Route::post('/invitations/{invitation}/accept', [InvitationController::class, 'accept']);
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
