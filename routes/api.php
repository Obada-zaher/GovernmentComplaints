<?php

use App\Http\Controllers\Api\V1\Admin\ClassificationRuleController;
use App\Http\Controllers\Api\V1\Admin\ComplaintCategoryController;
use App\Http\Controllers\Api\V1\Admin\ComplaintController as AdminComplaintController;
use App\Http\Controllers\Api\V1\Admin\DepartmentController;
use App\Http\Controllers\Api\V1\Admin\NotificationDeliveryLogController;
use App\Http\Controllers\Api\V1\Admin\PriorityController;
use App\Http\Controllers\Api\V1\Admin\ReportController;
use App\Http\Controllers\Api\V1\Admin\SlaRuleController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Citizen\ComplaintController as CitizenComplaintController;
use App\Http\Controllers\Api\V1\Citizen\OfflineComplaintSyncController;
use App\Http\Controllers\Api\V1\Classification\ComplaintClassificationController;
use App\Http\Controllers\Api\V1\Employee\ComplaintController as EmployeeComplaintController;
use App\Http\Controllers\Api\V1\LookupController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\RolePingController;
use App\Http\Controllers\Api\V1\UserDeviceTokenController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::prefix('lookups')->group(function (): void {
        Route::get('departments', [LookupController::class, 'departments'])->name('lookups.departments');
        Route::get('categories', [LookupController::class, 'categories'])->name('lookups.categories');
        Route::get('priorities', [LookupController::class, 'priorities'])->name('lookups.priorities');
        Route::get('complaint-statuses', [LookupController::class, 'complaintStatuses'])->name('lookups.complaint-statuses');
    });

    Route::prefix('auth')->group(function (): void {
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
        Route::post('verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:auth-verify-otp');
        Route::post('resend-otp', [AuthController::class, 'resendOtp'])->middleware('throttle:auth-resend-otp');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth-forgot-password');
        Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:auth-reset-password');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('change-password', [AuthController::class, 'changePassword'])->middleware('throttle:auth-change-password');
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('logout-all', [AuthController::class, 'logoutAll']);
        });
    });

    Route::prefix('notifications')
        ->middleware('auth:sanctum')
        ->group(function (): void {
            Route::get('/', [NotificationController::class, 'index']);
            Route::get('unread-count', [NotificationController::class, 'unreadCount']);
            Route::patch('read-all', [NotificationController::class, 'readAll']);
            Route::patch('{notification}/read', [NotificationController::class, 'read']);
            Route::delete('{notification}', [NotificationController::class, 'destroy']);
        });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('device-tokens', [UserDeviceTokenController::class, 'index']);
        Route::post('device-tokens', [UserDeviceTokenController::class, 'store']);
        Route::delete('device-tokens/{deviceToken}', [UserDeviceTokenController::class, 'destroy']);

        Route::get('notification-preferences', [NotificationPreferenceController::class, 'show']);
        Route::patch('notification-preferences', [NotificationPreferenceController::class, 'update']);
    });

    Route::prefix('classification')
        ->middleware('auth:sanctum')
        ->group(function (): void {
            Route::post('complaints/preview', [ComplaintClassificationController::class, 'preview']);
        });

    Route::prefix('citizen')
        ->middleware(['auth:sanctum', 'role:citizen'])
        ->group(function (): void {
            Route::get('ping', [RolePingController::class, 'citizen']);
            Route::get('complaints', [CitizenComplaintController::class, 'index']);
            Route::post('complaints', [CitizenComplaintController::class, 'store']);
            Route::get('complaints/{complaint}', [CitizenComplaintController::class, 'show']);
            Route::post('complaints/{complaint}/attachments', [CitizenComplaintController::class, 'addAttachments']);
            Route::post('offline/complaints/sync', [OfflineComplaintSyncController::class, 'sync']);
            Route::get('offline/submissions', [OfflineComplaintSyncController::class, 'index']);
            Route::get('offline/submissions/{offlineSubmission}', [OfflineComplaintSyncController::class, 'show']);
        });

    Route::prefix('employee')
        ->middleware(['auth:sanctum', 'role:employee'])
        ->group(function (): void {
            Route::get('ping', [RolePingController::class, 'employee']);
            Route::get('complaints', [EmployeeComplaintController::class, 'index']);
            Route::get('complaints/{complaint}', [EmployeeComplaintController::class, 'show']);
            Route::patch('complaints/{complaint}/status', [EmployeeComplaintController::class, 'updateStatus']);
        });

    Route::prefix('admin')
        ->middleware(['auth:sanctum', 'role:admin'])
        ->group(function (): void {
            Route::get('ping', [RolePingController::class, 'admin']);
            Route::get('complaints', [AdminComplaintController::class, 'index']);
            Route::get('complaints/{complaint}', [AdminComplaintController::class, 'show']);
            Route::patch('complaints/{complaint}/assign', [AdminComplaintController::class, 'assign']);
            Route::patch('complaints/{complaint}/department', [AdminComplaintController::class, 'changeDepartment']);
            Route::patch('complaints/{complaint}/priority', [AdminComplaintController::class, 'changePriority']);
            Route::patch('complaints/{complaint}/status', [AdminComplaintController::class, 'updateStatus']);
            Route::get('notification-delivery-logs', [NotificationDeliveryLogController::class, 'index']);
            Route::get('notification-delivery-logs/{notificationDeliveryLog}', [NotificationDeliveryLogController::class, 'show']);
            Route::prefix('reports')->group(function (): void {
                Route::get('overview', [ReportController::class, 'overview']);
                Route::get('complaints-by-status', [ReportController::class, 'complaintsByStatus']);
                Route::get('complaints-by-department', [ReportController::class, 'complaintsByDepartment']);
                Route::get('complaints-by-priority', [ReportController::class, 'complaintsByPriority']);
                Route::get('sla-performance', [ReportController::class, 'slaPerformance']);
                Route::get('employee-performance', [ReportController::class, 'employeePerformance']);
                Route::get('complaint-trends', [ReportController::class, 'complaintTrends']);
                Route::get('sla-breaches', [ReportController::class, 'slaBreaches']);
                Route::post('snapshots', [ReportController::class, 'storeSnapshot']);
                Route::get('snapshots', [ReportController::class, 'snapshots']);
                Route::get('snapshots/{reportSnapshot}', [ReportController::class, 'showSnapshot']);
            });
            Route::apiResource('departments', DepartmentController::class);
            Route::apiResource('categories', ComplaintCategoryController::class)
                ->parameters(['categories' => 'category']);
            Route::apiResource('priorities', PriorityController::class);
            Route::apiResource('sla-rules', SlaRuleController::class);
            Route::apiResource('classification-rules', ClassificationRuleController::class);
        });
});
