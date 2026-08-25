<?php

use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\ApplicationController as AdminApplicationController;
use App\Http\Controllers\Api\Admin\ContractController as AdminContractController;
use App\Http\Controllers\Api\Admin\EquipmentUnitController as AdminEquipmentUnitController;
use App\Http\Controllers\Api\Admin\LeaseAgreementController as AdminLeaseAgreementController;
use App\Http\Controllers\Api\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\Admin\RiskProfileController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\MeController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Customer\ApplicationController;
use App\Http\Controllers\Api\Customer\ContractController;
use App\Http\Controllers\Api\Customer\LeaseAgreementController;
use App\Http\Controllers\Api\Customer\PaymentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public auth routes
|--------------------------------------------------------------------------
*/
Route::post('/auth/register', RegisterController::class);
Route::post('/auth/login', LoginController::class);

/*
|--------------------------------------------------------------------------
| Authenticated routes, split by role
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', LogoutController::class);
    Route::get('/auth/me', MeController::class);

    // Customer portal
    Route::middleware('role:customer')->prefix('customer')->name('customer.')->group(function () {
        Route::apiResource('applications', ApplicationController::class)->only(['index', 'store', 'show']);
        Route::apiResource('lease-agreements', LeaseAgreementController::class)
            ->only(['index', 'show'])
            ->parameters(['lease-agreements' => 'leaseAgreement']);
        Route::apiResource('contracts', ContractController::class)->only(['index', 'show']);
        Route::apiResource('payments', PaymentController::class)->only(['index', 'show']);
    });

    // Admin dashboard — role gate first, then per-module permission gate.
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::apiResource('admin-users', AdminUserController::class)
            ->parameters(['admin-users' => 'adminUser']);

        Route::middleware('permission:application_review')->group(function () {
            Route::apiResource('applications', AdminApplicationController::class);
        });

        Route::middleware('permission:risk_assessment')->group(function () {
            Route::apiResource('risk-profiles', RiskProfileController::class)
                ->parameters(['risk-profiles' => 'riskProfile']);
        });

        Route::middleware('permission:contract_generation')->group(function () {
            Route::apiResource('contracts', AdminContractController::class);
            Route::apiResource('lease-agreements', AdminLeaseAgreementController::class)
                ->parameters(['lease-agreements' => 'leaseAgreement']);
        });

        Route::middleware('permission:equipment_tracking')->group(function () {
            Route::apiResource('equipment-units', AdminEquipmentUnitController::class)
                ->parameters(['equipment-units' => 'equipmentUnit']);
        });

        Route::middleware('permission:payment_tracking')->group(function () {
            Route::apiResource('payments', AdminPaymentController::class);
        });
    });
});
