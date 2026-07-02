<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    use ApiResponse;

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->errorResponse('Unauthenticated.', [], 401);
        }

        if (! in_array($user->role, $roles, true)) {
            return $this->errorResponse('Forbidden.', [], 403);
        }

        return $next($request);
    }
}
