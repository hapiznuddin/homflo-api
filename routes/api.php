<?php

use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\BillController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\HouseholdController;
use App\Http\Controllers\Api\HouseholdInvitationController;
use App\Http\Controllers\Api\HouseholdMemberController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\ListController;
use App\Http\Controllers\Api\ListItemController;
use App\Http\Controllers\Api\MaintenanceController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\ObligationTypeController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\VehicleMaintenanceController;
use App\Http\Controllers\Api\VehicleObligationController;
use App\Http\Controllers\Api\WalletController;
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

    // Wallet reads are available to any household member, like the other
    // finance reads. Wallet metadata and derived balances never expose
    // other households: every row is resolved in server-side context.
    Route::get('/wallets', [WalletController::class, 'index']);
    Route::get('/wallets/{wallet}', [WalletController::class, 'show']);
    Route::get('/wallets/{wallet}/adjustments', [WalletController::class, 'adjustments']);

    // Transaction ledger reads are available to any household member, like
    // /me and /dashboard. Recording, changing, and removing ledger rows
    // mutates shared household data, so writes follow the deferred
    // mandatory verification rule with the other household mutations.
    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::get('/transactions/{transaction}', [TransactionController::class, 'show']);

    // Budget target reads are available to any household member. Setting,
    // changing, and removing targets mutates shared household data, so
    // writes follow the deferred mandatory verification rule.
    Route::get('/budgets', [BudgetController::class, 'index']);
    Route::get('/budgets/{budget}', [BudgetController::class, 'show']);

    // Bill reads are available to any household member, like the other
    // finance reads. Payment status/amounts are derived per request from
    // transaction rows — never stored on the bill — and archived bills
    // stay hidden unless include_archived is set.
    Route::get('/bills', [BillController::class, 'index']);
    Route::get('/bills/{bill}', [BillController::class, 'show']);

    // Asset reads are available to any household member, like the other
    // household catalogue reads. Every row is scoped through the
    // server-side household context; cross-household access is masked
    // as 404.
    Route::get('/assets', [AssetController::class, 'index']);
    Route::get('/assets/{asset}', [AssetController::class, 'show']);

    // Property reads are available to any household member, like the other
    // household catalogue reads. Every row is scoped through the
    // server-side household context; cross-household access is masked
    // as 404.
    Route::get('/properties', [PropertyController::class, 'index']);
    Route::get('/properties/{property}', [PropertyController::class, 'show']);

    // Maintenance reads are available to any household member, like the
    // other household reads. Every row is scoped through the server-side
    // household context; cross-household access is masked as 404.
    Route::get('/maintenance', [MaintenanceController::class, 'index']);
    Route::get('/maintenance/{maintenance}', [MaintenanceController::class, 'show']);

    // Vehicle reads are available to any household member, like the other
    // household catalogue reads. Every row is scoped through the
    // server-side household context; cross-household access is masked
    // as 404. Service records are nested under their vehicle so a record can
    // never be read through a vehicle it does not belong to.
    Route::get('/vehicles', [VehicleController::class, 'index']);
    Route::get('/vehicles/{vehicle}', [VehicleController::class, 'show']);
    Route::get('/vehicles/{vehicle}/maintenance', [VehicleMaintenanceController::class, 'index']);
    Route::get('/vehicles/{vehicle}/maintenance/{maintenance}', [VehicleMaintenanceController::class, 'show']);

    // Obligation types are household configuration: any member may read the
    // system catalogue plus the household's custom types. Obligations are
    // nested under their vehicle so a record cannot be read through a vehicle
    // it does not belong to. Cross-household access is masked as 404.
    Route::get('/obligation-types', [ObligationTypeController::class, 'index']);
    Route::get('/vehicles/{vehicle}/obligations', [VehicleObligationController::class, 'index']);
    Route::get('/vehicles/{vehicle}/obligations/{obligation}', [VehicleObligationController::class, 'show']);

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
        Route::post('/budgets', [BudgetController::class, 'store']);
        Route::patch('/budgets/{budget}', [BudgetController::class, 'update']);
        Route::delete('/budgets/{budget}', [BudgetController::class, 'destroy']);
        Route::post('/wallets', [WalletController::class, 'store']);
        Route::patch('/wallets/{wallet}', [WalletController::class, 'update']);
        Route::post('/wallets/{wallet}/deactivate', [WalletController::class, 'deactivate']);
        Route::delete('/wallets/{wallet}', [WalletController::class, 'destroy']);
        Route::post('/wallets/{wallet}/adjustments', [WalletController::class, 'adjust']);

        // Asset writes. Reads stay member-visible; mutations are owner-only
        // through AssetPolicy and require the verified email gate.
        Route::post('/assets', [AssetController::class, 'store']);
        Route::patch('/assets/{asset}', [AssetController::class, 'update']);
        Route::delete('/assets/{asset}', [AssetController::class, 'destroy']);

        // Bill writes, including lifecycle archive and payment recording.
        // The recurrence endpoint is static-first so /bills/{bill} can never
        // swallow /bills/recurrences/{recurrence}.
        Route::post('/bills', [BillController::class, 'store']);
        Route::patch('/bills/recurrences/{recurrence}', [BillController::class, 'updateRecurrence']);
        Route::patch('/bills/{bill}', [BillController::class, 'update']);
        Route::delete('/bills/{bill}', [BillController::class, 'destroy']);
        Route::post('/bills/{bill}/archive', [BillController::class, 'archive']);
        Route::post('/bills/{bill}/payments', [BillController::class, 'pay']);

        // Maintenance writes and lifecycle transitions.
        Route::post('/maintenance', [MaintenanceController::class, 'store']);
        Route::patch('/maintenance/{maintenance}', [MaintenanceController::class, 'update']);
        Route::delete('/maintenance/{maintenance}', [MaintenanceController::class, 'destroy']);
        Route::post('/maintenance/{maintenance}/complete', [MaintenanceController::class, 'complete']);
        Route::post('/maintenance/{maintenance}/cancel', [MaintenanceController::class, 'cancel']);

        // Property writes. There is no permanent DELETE: archive/restore own
        // the lifecycle, and archived rows stay readable.
        Route::post('/properties', [PropertyController::class, 'store']);
        Route::patch('/properties/{property}', [PropertyController::class, 'update']);
        Route::post('/properties/{property}/archive', [PropertyController::class, 'archive']);
        Route::post('/properties/{property}/restore', [PropertyController::class, 'restore']);

        // Vehicle writes plus nested service and obligation writes. Nested
        // records are always resolved through their vehicle.
        Route::post('/vehicles', [VehicleController::class, 'store']);
        Route::patch('/vehicles/{vehicle}', [VehicleController::class, 'update']);
        Route::delete('/vehicles/{vehicle}', [VehicleController::class, 'destroy']);
        Route::post('/vehicles/{vehicle}/maintenance', [VehicleMaintenanceController::class, 'store']);
        Route::patch('/vehicles/{vehicle}/maintenance/{maintenance}', [VehicleMaintenanceController::class, 'update']);
        Route::delete('/vehicles/{vehicle}/maintenance/{maintenance}', [VehicleMaintenanceController::class, 'destroy']);
        Route::post('/vehicles/{vehicle}/maintenance/{maintenance}/complete', [VehicleMaintenanceController::class, 'complete']);
        Route::post('/vehicles/{vehicle}/obligations', [VehicleObligationController::class, 'store']);
        Route::patch('/vehicles/{vehicle}/obligations/{obligation}', [VehicleObligationController::class, 'update']);
        Route::delete('/vehicles/{vehicle}/obligations/{obligation}', [VehicleObligationController::class, 'destroy']);

        // Obligation types are household configuration; only owners may
        // create, rename, or remove custom types.
        Route::post('/obligation-types', [ObligationTypeController::class, 'store']);
        Route::patch('/obligation-types/{obligationType}', [ObligationTypeController::class, 'update']);
        Route::delete('/obligation-types/{obligationType}', [ObligationTypeController::class, 'destroy']);

        // List routes - MVP Lists domain
        Route::get('/lists', [ListController::class, 'index']);
        Route::get('/lists/default', [ListController::class, 'default']);
        Route::get('/lists/{list}', [ListController::class, 'show']);
        Route::get('/lists/{list}/items', [ListItemController::class, 'index']);

        // List writes behind verified gate
        Route::middleware('verified')->group(function () {
            Route::post('/lists', [ListController::class, 'store']);
            Route::patch('/lists/{list}', [ListController::class, 'update']);
            Route::post('/lists/{list}/archive', [ListController::class, 'archive']);
            Route::post('/lists/{list}/restore', [ListController::class, 'restore']);

            Route::post('/lists/{list}/items', [ListItemController::class, 'store']);
            Route::patch('/lists/{list}/items/{item}', [ListItemController::class, 'update']);
            Route::delete('/lists/{list}/items/{item}', [ListItemController::class, 'destroy']);
            Route::post('/lists/{list}/items/{item}/complete', [ListItemController::class, 'complete']);
            Route::post('/lists/{list}/items/{item}/uncomplete', [ListItemController::class, 'uncomplete']);
            Route::post('/lists/{list}/items/{item}/expense', [ListItemController::class, 'expense']);
        });
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