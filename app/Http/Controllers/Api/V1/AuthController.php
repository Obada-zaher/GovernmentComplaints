<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ChangePasswordRequest;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Auth\ResendOtpRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Http\Requests\Api\V1\Auth\VerifyOtpRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Auth\AuthEventService;
use App\Services\AuthService;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AuthService $authService,
        private readonly OtpService $otpService,
        private readonly AuthEventService $authEventService,
    ) {
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->authService->register($request->validated());
        $this->authEventService->record('registered', $user, $request);
        $this->authEventService->record('otp_sent', $user, $request, ['purpose' => 'register']);

        return $this->successResponse(
            'Registration successful. A verification code has been sent to your email.',
            [
                'user_id' => $user->id,
                'requires_otp' => true,
            ],
            201,
        );
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $this->authService->findLoginUser($data['login']);

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            $this->authEventService->record('login_failed', $user, $request, ['login' => $data['login']]);

            return $this->errorResponse('Invalid login credentials.', [], 401);
        }

        if (! $user->is_active) {
            return $this->errorResponse('User account is inactive.', [], 403);
        }

        $this->authEventService->record('login_credentials_valid', $user, $request);

        $purpose = $user->email_verified_at ? 'login' : 'verify_email';
        $this->otpService->createForUser($user, $purpose);
        $this->authEventService->record('otp_sent', $user, $request, ['purpose' => $purpose]);

        return $this->successResponse(
            $purpose === 'login'
                ? 'Login verification code has been sent to your email.'
                : 'Email verification code has been sent to your email.',
            [
                'user_id' => $user->id,
                'requires_otp' => true,
            ],
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
            $this->authEventService->record('otp_failed', $user, $request, ['purpose' => $data['purpose']]);

            return $this->errorResponse($verification['message'], [
                'otp' => [$verification['message']],
            ], 422);
        }

        if (in_array($data['purpose'], ['register', 'verify_email'], true)) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $this->authEventService->record('otp_verified', $user, $request, ['purpose' => $data['purpose']]);

        return $this->successResponse('Verification successful.', [
            'token' => $this->authService->issueToken($user, $data['device_name'] ?? null),
            'token_type' => 'Bearer',
            'user' => new UserResource($user->fresh('department')),
        ]);
    }

    public function resendOtp(ResendOtpRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = User::query()->findOrFail($data['user_id']);

        if (! $user->is_active) {
            return $this->errorResponse('User account is inactive.', [], 403);
        }

        $this->otpService->createForUser($user, $data['purpose']);
        $this->authEventService->record('otp_resent', $user, $request, ['purpose' => $data['purpose']]);

        return $this->successResponse('A new verification code has been sent to your email.', [
            'requires_otp' => true,
        ]);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = User::query()->where('email', $data['email'])->first();

        if ($user && $user->is_active) {
            Password::sendResetLink(['email' => $data['email']]);
        }

        $this->authEventService->record('password_reset_requested', $user, $request);

        return $this->successResponse('If this email exists, a password reset link has been sent.');
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();
        $status = $this->authService->resetPassword($data['email'], $data['token'], $data['password']);

        if ($status !== Password::PASSWORD_RESET) {
            return $this->errorResponse('Invalid or expired password reset token.', [
                'token' => ['Invalid or expired password reset token.'],
            ], 422);
        }

        $user = User::query()->where('email', $data['email'])->first();
        $this->authEventService->record('password_reset_completed', $user, $request);

        return $this->successResponse('Password reset successfully.');
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            return $this->errorResponse('Current password is incorrect.', [
                'current_password' => ['Current password is incorrect.'],
            ], 422);
        }

        $user->forceFill(['password' => Hash::make($data['password'])])->save();

        $currentToken = $user->currentAccessToken();
        if ($currentToken) {
            $user->tokens()->where('id', '!=', $currentToken->id)->delete();
        }

        $this->authEventService->record('password_changed', $user, $request);

        return $this->successResponse('Password changed successfully.');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->successResponse('Current user retrieved successfully.', [
            'user' => new UserResource($request->user()->load('department')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authEventService->record('logout', $request->user(), $request);
        $request->user()->currentAccessToken()?->delete();

        return $this->successResponse('Logged out successfully.');
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $this->authEventService->record('logout_all', $request->user(), $request);
        $request->user()->tokens()->delete();

        return $this->successResponse('Logged out from all devices successfully.');
    }
}
