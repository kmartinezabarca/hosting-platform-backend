<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if user is authenticated
        if (!auth()->check()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Please login first.',
                'error_code' => 'UNAUTHORIZED'
            ], 401);
        }

        $user = auth()->user();

        // Check if user has admin privileges
        if (!in_array($user->role, ['super_admin', 'admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Administrator privileges required.',
                'error_code' => 'INSUFFICIENT_PRIVILEGES'
            ], 403);
        }

        // Check if user account is active
        if ($user->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Account is not active. Please contact support.',
                'error_code' => 'ACCOUNT_INACTIVE'
            ], 403);
        }

        return $next($request);
    }
}

