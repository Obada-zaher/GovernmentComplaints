<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\RolePingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
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

    Route::middleware(['auth:sanctum', 'role:citizen'])->get('citizen/ping', [RolePingController::class, 'citizen']);
    Route::middleware(['auth:sanctum', 'role:employee'])->get('employee/ping', [RolePingController::class, 'employee']);
    Route::middleware(['auth:sanctum', 'role:admin'])->get('admin/ping', [RolePingController::class, 'admin']);
});
