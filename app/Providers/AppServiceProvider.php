<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('auth-register', fn (Request $request) => Limit::perMinute(3)->by($request->ip()));
        RateLimiter::for('auth-login', fn (Request $request) => Limit::perMinute(5)->by($request->input('login', '').'|'.$request->ip()));
        RateLimiter::for('auth-verify-otp', fn (Request $request) => Limit::perMinute(5)->by($request->input('user_id', '').'|'.$request->ip()));
        RateLimiter::for('auth-resend-otp', fn (Request $request) => Limit::perMinutes(10, 3)->by($request->input('user_id', '').'|'.$request->ip()));
        RateLimiter::for('auth-forgot-password', fn (Request $request) => Limit::perMinutes(10, 3)->by($request->input('email', '').'|'.$request->ip()));
        RateLimiter::for('auth-reset-password', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('auth-change-password', fn (Request $request) => Limit::perMinute(5)->by(($request->user()?->id ?? 'guest').'|'.$request->ip()));
    }
}
