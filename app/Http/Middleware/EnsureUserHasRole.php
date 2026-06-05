<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role gate for admin-area routes.
 *
 * Usage in routes:  ->middleware('role:super_admin,admin,support')
 *
 * Mirrors AdminMiddleware's JSON contract ({ success:false, message, error_code })
 * but accepts an explicit allow-list of roles, so each route group can be scoped
 * to exactly the roles that should reach it (super_admin / admin / support).
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success'    => false,
                'message'    => 'Unauthorized. Please login first.',
                'error_code' => 'UNAUTHORIZED',
            ], 401);
        }

        if (empty($roles) || !in_array($user->role, $roles, true)) {
            return response()->json([
                'success'    => false,
                'message'    => 'Access denied. You do not have the required role for this action.',
                'error_code' => 'INSUFFICIENT_PRIVILEGES',
            ], 403);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'success'    => false,
                'message'    => 'Account is not active. Please contact support.',
                'error_code' => 'ACCOUNT_INACTIVE',
            ], 403);
        }

        return $next($request);
    }
}
