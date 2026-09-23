<?php

use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\HouseholdController;
use App\Http\Controllers\Api\HouseholdInvitationController;
use App\Http\Controllers\Api\HouseholdMemberController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\MeController;
use Illuminate\Support\Facades\Route;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', MeController::class);
    Route::post('/households', [HouseholdController::class, 'store']);
    Route::get('/households/{household}/members', [HouseholdMemberController::class, 'index']);
    Route::patch('/households/{household}/members/{member}', [HouseholdMemberController::class, 'update']);
    Route::delete('/households/{household}/members/{member}', [HouseholdMemberController::class, 'destroy']);
    Route::post('/households/{household}/invitations', [HouseholdInvitationController::class, 'store']);
    Route::get('/invitations', [InvitationController::class, 'index']);
    Route::post('/invitations/{invitation}/accept', [InvitationController::class, 'accept']);
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('api.verification.verify');
});
