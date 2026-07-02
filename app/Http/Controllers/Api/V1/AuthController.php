<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Auth\ResendOtpRequest;
use App\Http\Requests\Api\V1\Auth\VerifyOtpRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\AuthService;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AuthService $authService,
        private readonly OtpService $otpService,
    ) {
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register($request->validated());

        $data = [
            'user_id' => $result['user']->id,
            'requires_otp' => true,
        ];

        if (app()->environment('local')) {
            $data['otp'] = $result['otp'];
        }

        return $this->successResponse(
            'Registration successful. OTP verification required.',
            $data,
            201,
        );
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $this->authService->findLoginUser($data['login']);

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return $this->errorResponse('Invalid login credentials.', [], 401);
        }

        if (! $user->is_active) {
            return $this->errorResponse('User account is inactive.', [], 403);
        }

        $otp = $this->otpService->createForUser($user, 'login');

        $responseData = [
            'user_id' => $user->id,
            'requires_otp' => true,
        ];

        if (app()->environment('local')) {
            $responseData['otp'] = $otp['plain'];
        }

        return $this->successResponse(
            'Login successful. OTP verification required.',
            $responseData,
        );
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = User::query()->with('department')->findOrFail($data['user_id']);

        if (! $user->is_active) {
            return $this->errorResponse('User account is inactive.', [], 403);
        }

        $verification = $this->otpService->verify($user, $data['otp'], $data['purpose']);

        if (! $verification['success']) {
            return $this->errorResponse($verification['message'], [
                'otp' => [$verification['message']],
            ], 422);
        }

        if (in_array($data['purpose'], ['register', 'verify_phone'], true)) {
            $user->forceFill(['phone_verified_at' => now()])->save();
        }

        $responseData = [
            'user' => new UserResource($user->fresh('department')),
        ];

        if (in_array($data['purpose'], ['register', 'login'], true)) {
            $responseData['token'] = $this->authService->issueToken($user);
            $responseData['token_type'] = 'Bearer';
            $responseData['user'] = new UserResource($user->fresh('department'));
        }

        return $this->successResponse('OTP verified successfully.', $responseData);
    }

    public function resendOtp(ResendOtpRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = User::query()->findOrFail($data['user_id']);

        if (! $user->is_active) {
            return $this->errorResponse('User account is inactive.', [], 403);
        }

        $otp = $this->otpService->createForUser($user, $data['purpose']);

        $responseData = [
            'user_id' => $user->id,
            'requires_otp' => true,
        ];

        if (app()->environment('local')) {
            $responseData['otp'] = $otp['plain'];
        }

        return $this->successResponse('OTP resent successfully.', $responseData);
    }

    public function me(Request $request): JsonResponse
    {
        return $this->successResponse('Current user retrieved successfully.', [
            'user' => new UserResource($request->user()->load('department')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->successResponse('Logged out successfully.');
    }
}
