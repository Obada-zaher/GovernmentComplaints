<?php

use App\Console\Commands\CheckComplaintSlaBreaches;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\SetLocaleFromAcceptLanguage;
use App\Support\LocalizedText;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        CheckComplaintSlaBreaches::class,
    ])
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('complaints:check-sla')
            ->everyMinute()
            ->withoutOverlapping(10);
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            SetLocaleFromAcceptLanguage::class,
        ]);

        $middleware->alias([
            'active.user' => EnsureUserIsActive::class,
            'role' => RoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => LocalizedText::resolve('Validation failed.'),
                'errors' => LocalizedText::errors($exception->errors()),
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $exception) {
            return response()->json([
                'success' => false,
                'message' => LocalizedText::resolve('Unauthenticated.'),
                'errors' => [],
            ], 401);
        });

        $exceptions->render(function (ThrottleRequestsException $exception) {
            return response()->json([
                'success' => false,
                'message' => LocalizedText::resolve('Too many requests. Please try again later.'),
                'errors' => [
                    'retry_after' => [$exception->getHeaders()['Retry-After'] ?? null],
                ],
            ], 429);
        });
    })->create();
