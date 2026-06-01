<?php

namespace App\Http\Middleware;

use App\Domains\Platform\Models\UserSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Jenssegers\Agent\Agent;
use Laravel\Sanctum\PersonalAccessToken;

class TrackUserSession
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (auth()->check()) {
            $user = $request->user();
            $deviceToken = $request->cookie('device_token');
            $currentTokenId = null;

            // Intentar obtener el ID del token actual de Sanctum
            if ($tokenString = $request->bearerToken()) {
                $pat = PersonalAccessToken::findToken($tokenString);
                if ($pat && (int) $pat->tokenable_id === (int) $user->getKey()) {
                    $currentTokenId = $pat->id;
                }
            }

            $session = null;

            // 1. Intentar encontrar por device_token (cookie)
            if ($deviceToken) {
                $session = UserSession::where('device_token', $deviceToken)
                                      ->where('user_id', $user->id)
                                      ->first();
            }

            // 2. Si no hay por cookie, intentar por sanctum_token_id
            if (!$session && $currentTokenId) {
                $session = UserSession::where('sanctum_token_id', $currentTokenId)
                                      ->where('user_id', $user->id)
                                      ->first();
            }

            // 3. Si aún no hay sesión, crear una nueva
            if (!$session) {
                $session = new UserSession();
                $session->uuid = (string) Str::uuid();
                $session->user_id = $user->id;
                $session->login_at = now();
                
                if (!$deviceToken) {
                    $session->device_token = Str::random(60);
                    $response->cookie('device_token', $session->device_token, 60 * 24 * 365 * 5);
                } else {
                    $session->device_token = $deviceToken;
                }
            }

            // Actualizar siempre el sanctum_token_id si lo tenemos
            if ($currentTokenId) {
                $session->sanctum_token_id = $currentTokenId;
            }

            // Actualización de Datos
            $ip = $this->clientIp($request);
            $agent = new Agent();
            $agent->setUserAgent((string) $request->userAgent());
            [$country, $region, $city] = $this->extractLocation($ip, $request);

            $session->ip_address = $ip;
            $session->user_agent = (string) $request->userAgent();
            $session->device = $agent->isTablet() ? 'tablet' : ($agent->isMobile() ? 'mobile' : 'desktop');
            $session->platform = $agent->platform() ?: 'Unknown';
            $session->browser = $agent->browser() ?: 'Unknown';
            $session->country = $country;
            $session->region = $region;
            $session->city = $city;
            $session->last_activity = now();
            $session->laravel_session_id = $request->hasSession() ? $request->session()->getId() : null;

            $session->save();
        }

        return $response;
    }

    /**
     * Intenta obtener la IP real del cliente.
     */
    private function clientIp(Request $request): string
    {
        if ($h = $request->header('CF-Connecting-IP')) return trim($h);
        if ($xff = $request->header('X-Forwarded-For')) {
            $parts = array_map('trim', explode(',', $xff));
            if (!empty($parts[0])) return $parts[0];
        }
        return $request->ip();
    }

    /**
     * Obtiene la localización usando GeoIP o fallback a cabeceras de Cloudflare.
     */
    private function extractLocation(string $ip, Request $request): array
    {
        if ($this->isLocalOrPrivateIp($ip)) {
            $spoof = config('app.debug_geoip_ip');
            return $spoof ? $this->lookupGeoip($spoof) : [null, null, null];
        }

        $loc = $this->lookupGeoip($ip);
        if ($loc[0] || $loc[1] || $loc[2]) return $loc;

        return [
            $request->header('CF-IPCountry') ?: null,
            $request->header('CF-IPRegion')  ?: null,
            $request->header('CF-IPCity')    ?: null,
        ];
    }

    /**
     * Realiza la búsqueda en la base de datos de GeoIP.
     */
    private function lookupGeoip(string $ip): array
    {
        try {
            $record = app('geoip')->getLocation($ip);
            return [
                $record->country ?? null,
                $record->state_name ?? $record->state ?? null,
                $record->city ?? null,
            ];
        } catch (\Throwable $e) {
            return [null, null, null];
        }
    }

    /**
     * Verifica si una IP es local o privada.
     */
    private function isLocalOrPrivateIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
