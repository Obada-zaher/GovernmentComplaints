<?php

use App\Http\Controllers\Api\V1\Admin\ComplaintCategoryController;
use App\Http\Controllers\Api\V1\Admin\DepartmentController;
use App\Http\Controllers\Api\V1\Admin\PriorityController;
use App\Http\Controllers\Api\V1\Admin\SlaRuleController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Citizen\ComplaintController as CitizenComplaintController;
use App\Http\Controllers\Api\V1\LookupController;
use App\Http\Controllers\Api\V1\RolePingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::prefix('lookups')->group(function (): void {
        Route::get('departments', [LookupController::class, 'departments'])->name('lookups.departments');
        Route::get('categories', [LookupController::class, 'categories'])->name('lookups.categories');
        Route::get('priorities', [LookupController::class, 'priorities'])->name('lookups.priorities');
        Route::get('complaint-statuses', [LookupController::class, 'complaintStatuses'])->name('lookups.complaint-statuses');
    });

    Route::prefix('auth')->group(function (): void {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('verify-otp', [AuthController::class, 'verifyOtp']);
        Route::post('resend-otp', [AuthController::class, 'resendOtp'])->middleware('throttle:6,1');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
        });
    });

    Route::prefix('citizen')
        ->middleware(['auth:sanctum', 'role:citizen'])
        ->group(function (): void {
            Route::get('ping', [RolePingController::class, 'citizen']);
            Route::get('complaints', [CitizenComplaintController::class, 'index']);
            Route::post('complaints', [CitizenComplaintController::class, 'store']);
            Route::get('complaints/{complaint}', [CitizenComplaintController::class, 'show']);
            Route::post('complaints/{complaint}/attachments', [CitizenComplaintController::class, 'addAttachments']);
        });
    Route::middleware(['auth:sanctum', 'role:employee'])->get('employee/ping', [RolePingController::class, 'employee']);

    Route::prefix('admin')
        ->middleware(['auth:sanctum', 'role:admin'])
        ->group(function (): void {
            Route::get('ping', [RolePingController::class, 'admin']);
            Route::apiResource('departments', DepartmentController::class);
            Route::apiResource('categories', ComplaintCategoryController::class)
                ->parameters(['categories' => 'category']);
            Route::apiResource('priorities', PriorityController::class);
            Route::apiResource('sla-rules', SlaRuleController::class);
        });
});
