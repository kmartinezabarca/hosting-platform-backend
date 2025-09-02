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
        // Solo se ejecuta si hay un usuario autenticado en la sesión.
        if (auth()->check()) {
            $user = $request->user();
            $sessionId = $request->session()->getId();

            // Obtener IP real del cliente (considerando proxies como Cloudflare).
            $ip = $this->clientIp($request);

            // Parsear el User-Agent para obtener detalles del dispositivo.
            $agent = new Agent();
            $agent->setUserAgent((string) $request->userAgent());

            $deviceType = $agent->isTablet() ? 'tablet' : ($agent->isMobile() ? 'mobile' : 'desktop');
            $platform   = $agent->platform() ?: 'Unknown';
            $browser    = $agent->browser()  ?: 'Unknown';

            // Obtener localización geográfica.
            [$country, $region, $city] = $this->extractLocation($ip, $request);

            // Busca la sesión actual por ID de usuario y de sesión, o crea una nueva si no existe.
            // Esto es más eficiente y preciso que la versión anterior.
            $session = UserSession::firstOrNew([
                'user_id'            => $user->id,
                'laravel_session_id' => $sessionId,
            ]);

            // Si la sesión es nueva, establece los datos iniciales.
            if (! $session->exists) {
                $session->uuid     = (string) Str::uuid();
                $session->login_at = now();
            }

            // Actualiza los datos en cada petición.
            $session->ip_address    = $ip;
            $session->user_agent    = (string) $request->userAgent();
            $session->device        = $deviceType;
            $session->platform      = $platform;
            $session->browser       = $browser;
            $session->country       = $country;
            $session->region        = $region;
            $session->city          = $city;
            $session->last_activity = now();
            $session->save();
        }

        return $next($request);
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
