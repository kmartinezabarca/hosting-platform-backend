<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        // For API routes, always return null to avoid redirect
        if ($request->is('api/*') || $request->expectsJson()) {
            return null;
        }
        
        // For web routes, you could define a login route if needed
        return null;
    }
}
