<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    use ApiResponse;

    public function __invoke(): JsonResponse
    {
        return $this->successResponse('System health retrieved successfully.', [
            'app' => 'Government Complaints Management System',
            'status' => 'ok',
            'environment' => app()->environment(),
            'database' => $this->databaseStatus(),
            'queue' => config('queue.default') ? 'configured' : 'not_configured',
            'time' => now()->toISOString(),
            'version' => config('app.version', env('APP_VERSION', '1.0.0')),
        ]);
    }

    private function databaseStatus(): string
    {
        try {
            DB::select('select 1');

            return 'connected';
        } catch (Throwable) {
            return 'disconnected';
        }
    }
}
