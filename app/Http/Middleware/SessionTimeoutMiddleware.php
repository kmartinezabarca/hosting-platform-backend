<?php

namespace App\Http\Middleware;

use App\Domains\Platform\Models\UserSession;
use App\Support\AuthCookie;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class SessionTimeoutMiddleware
{
    // Minutos de inactividad permitidos por rol
    private const TIMEOUTS = [
        'super_admin' => 120,
        'admin'       => 120,
        'support'     => 180,
        'client'      => 360,
    ];

    private const DEFAULT_TIMEOUT = 120;

    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check()) {
            return $next($request);
        }

        $user    = auth()->user();
        $session = $this->resolveSession($request, $user);

        if ($session) {
            $timeout = $this->timeoutFor($user->role, $session, $request);

            if ($session->last_activity && $session->last_activity->diffInMinutes(now()) >= $timeout) {
                // Revocar el token activo
                $user->currentAccessToken()?->delete();

                return AuthCookie::attachForgetCookies(response()->json([
                    'success'    => false,
                    'message'    => 'Tu sesión ha expirado por inactividad. Por favor inicia sesión de nuevo.',
                    'error_code' => 'SESSION_EXPIRED',
                ], 401));
            }
        }

        return $next($request);
    }

    private function resolveSession(Request $request, $user): ?UserSession
    {
        $tokenId = null;

        if ($tokenString = $request->bearerToken()) {
            $pat = PersonalAccessToken::findToken($tokenString);
            if ($pat && (int) $pat->tokenable_id === (int) $user->getKey()) {
                $tokenId = $pat->id;
            }
        }

        return UserSession::where('user_id', $user->id)
            ->when($tokenId, fn($q) => $q->where('sanctum_token_id', $tokenId))
            ->latest('last_activity')
            ->first();
    }

    private function timeoutFor(string $role, UserSession $session, Request $request): int
    {
        if ($role === 'client' && $this->isRememberedSession($session, $request)) {
            return (int) (config('sanctum.expiration') ?: 43200);
        }

        return self::TIMEOUTS[$role] ?? self::DEFAULT_TIMEOUT;
    }

    private function isRememberedSession(UserSession $session, Request $request): bool
    {
        if (($session->meta['remember_me'] ?? false) === true) {
            return true;
        }

        if ($tokenString = $request->bearerToken()) {
            $pat = PersonalAccessToken::findToken($tokenString);
            return $pat && str_contains((string) $pat->name, ':remember');
        }

        return false;
    }
}
