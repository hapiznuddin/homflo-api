<?php

use App\Http\Controllers\Api\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    // return view('welcome');
});

// Google OAuth needs the web session middleware for Socialite state
// protection, so these routes live here with an explicit /api path
// instead of routes/api.php. No auth:sanctum, no verified: the signed
// OAuth state is the protection for the callback, which establishes
// the Laravel session itself.
Route::prefix('/api/auth/google')->group(function () {
    Route::get('/redirect', [GoogleAuthController::class, 'redirect']);
    Route::get('/callback', [GoogleAuthController::class, 'callback']);
});
