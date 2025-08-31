<?php

namespace App\Listeners;

use App\Models\UserSession;
use Illuminate\Auth\Events\Logout;
use Laravel\Sanctum\PersonalAccessToken;

class MarkUserSessionLoggedOut
{
    public function handle(Logout $event): void
    {
        $user = $event->user;
        if (! $user) return;

        $req = request();

        // Resolver token Sanctum si aplica
        $tokenId = null;
        if ($req && $tokenString = $req->bearerToken()) {
            $pat = PersonalAccessToken::findToken($tokenString);
            if ($pat && (int) $pat->tokenable_id === (int) $user->getKey()) {
                $tokenId = $pat->id;
            }
        }

        // Solo leer session si existe
        $sessionId = null;
        if ($req && method_exists($req, 'hasSession') && $req->hasSession()) {
            $sessionId = $req->session()->getId();
        }

        $q = UserSession::where('user_id', $user->id);

        if ($tokenId) {
            $q->where('sanctum_token_id', $tokenId);
        } elseif ($sessionId) {
            $q->where('laravel_session_id', $sessionId);
        }

        $q->whereNull('logout_at')->update(['logout_at' => now()]);
    }
}
