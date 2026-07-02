<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

class RolePingController extends Controller
{
    use ApiResponse;

    public function citizen(): JsonResponse
    {
        return $this->successResponse('Citizen ping successful.', ['role' => 'citizen']);
    }

    public function employee(): JsonResponse
    {
        return $this->successResponse('Employee ping successful.', ['role' => 'employee']);
    }

    public function admin(): JsonResponse
    {
        return $this->successResponse('Admin ping successful.', ['role' => 'admin']);
    }
}
