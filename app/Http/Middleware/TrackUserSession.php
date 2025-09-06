<?php

namespace App\Http\Middleware;

use App\Models\UserSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Jenssegers\Agent\Agent;

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
        // El middleware se ejecuta después de que la petición ha sido manejada.
        // Esto nos permite adjuntar la cookie a la respuesta saliente.
        $response = $next($request);

        // Solo se ejecuta si hay un usuario autenticado.
        if (auth()->check()) {
            $user = $request->user();
            $deviceToken = $request->cookie('device_token');

            // --- Lógica de Identificación de Sesión del Dispositivo ---
            $session = null;
            if ($deviceToken) {
                $session = UserSession::where('device_token', $deviceToken)
                                      ->where('user_id', $user->id)
                                      ->first();
            }

            // Si no encontramos una sesión con el token, creamos una nueva.
            if (!$session) {
                $session = new UserSession();
                $session->uuid = (string) Str::uuid();
                $session->user_id = $user->id;
                $session->device_token = Str::random(60);
                $session->login_at = now(); // Se establece solo en la creación

                // Adjuntamos la cookie con el nuevo token a la respuesta.
                $response->cookie('device_token', $session->device_token, 60 * 24 * 365 * 5);
            }

            // --- Actualización de Datos en Cada Petición ---
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
            $session->laravel_session_id = $request->session()->getId();

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
