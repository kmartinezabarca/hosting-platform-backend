<?php

namespace App\Http\Middleware;

use App\Models\UserSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Jenssegers\Agent\Agent;
use Laravel\Sanctum\PersonalAccessToken;

class TrackUserSession
{
    public function handle(Request $request, Closure $next)
    {
        if (auth()->check()) {
            $user = $request->user();

            // 1) Resolver token Sanctum si viene un Bearer token
            $sanctumTokenId = null;
            if ($tokenString = $request->bearerToken()) {
                $pat = PersonalAccessToken::findToken($tokenString);
                if ($pat && (int) $pat->tokenable_id === (int) $user->getKey()) {
                    $sanctumTokenId = $pat->id;
                }
            }

            // 2) ID de sesión SOLO si hay sesión (API normalmente no la tiene)
            $sessionId = null;
            if (method_exists($request, 'hasSession') && $request->hasSession()) {
                $sessionId = $request->session()->getId();
            }

            // 3) IP real (considera proxies y Cloudflare)
            $ip = $this->clientIp($request);

            // 4) Parsear User-Agent
            $agent = new Agent();
            $agent->setUserAgent((string) $request->userAgent());

            $deviceType = $agent->isTablet() ? 'tablet' : ($agent->isMobile() ? 'mobile' : 'desktop');
            $platform   = $agent->platform() ?: null; // p.ej. Windows
            $browser    = $agent->browser()  ?: null; // p.ej. Chrome

            // 5) Localización (evita buscar para 127.0.0.1)
            [$country, $region, $city] = $this->extractLocation($ip, $request);

            // 6) Crear/actualizar registro
            $session = UserSession::firstOrNew([
                'user_id'            => $user->id,
                'sanctum_token_id'   => $sanctumTokenId,
                'laravel_session_id' => $sessionId,
            ]);

            if (! $session->exists) {
                $session->uuid     = (string) Str::uuid();
                $session->login_at = now();
            }

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
     * Intenta obtener IP real: CF-Connecting-IP, X-Forwarded-For, RemoteAddr.
     */
    private function clientIp(Request $request): string
    {
        // Cloudflare
        if ($h = $request->header('CF-Connecting-IP')) {
            return trim($h);
        }

        // X-Forwarded-For puede traer varias IPs: "client, proxy1, proxy2"
        if ($xff = $request->header('X-Forwarded-For')) {
            $parts = array_map('trim', explode(',', $xff));
            if (!empty($parts[0])) {
                return $parts[0];
            }
        }

        // Standard
        return $request->ip();
    }

    /**
     * Usa Torann/GeoIP si no es localhost; como fallback, intenta headers de Cloudflare.
     * Devuelve [country, region, city].
     */
    private function extractLocation(string $ip, Request $request): array
    {
        // Evita buscar para localhost o IPs privadas
        if ($this->isLocalOrPrivateIp($ip)) {
            // Permite “spoof” en dev: APP_DEBUG_GEOIP_IP=186.33.XXX.XXX
            $spoof = config('app.debug_geoip_ip');
            if ($spoof) {
                return $this->lookupGeoip($spoof);
            }
            // Fallback nulo
            return [null, null, null];
        }

        // 1) Intento GeoIP por IP
        $loc = $this->lookupGeoip($ip);
        if ($loc[0] || $loc[1] || $loc[2]) {
            return $loc;
        }

        // 2) Fallback Cloudflare (si estás detrás de CF)
        $country = $request->header('CF-IPCountry'); // ej. "MX"
        $city    = $request->header('CF-IPCity');    // depende de setup
        $region  = $request->header('CF-IPRegion');  // depende de setup

        return [
            $country ?: null,
            $region  ?: null,
            $city    ?: null,
        ];
    }

    private function lookupGeoip(string $ip): array
    {
        try {
            $record = app('geoip')->getLocation($ip); // Torann\GeoIP
            // $record->country (código ISO2), $record->state_name, $record->city
            $country = $record->country ?? null;
            $region  = $record->state_name ?? ($record->state ?? null);
            $city    = $record->city ?? null;
            return [$country ?: null, $region ?: null, $city ?: null];
        } catch (\Throwable $e) {
            return [null, null, null];
        }
    }

    private function isLocalOrPrivateIp(string $ip): bool
    {
        if ($ip === '127.0.0.1' || $ip === '::1') return true;

        // rangos privados
        $privateRanges = [
            '10.0.0.0|10.255.255.255',
            '172.16.0.0|172.31.255.255',
            '192.168.0.0|192.168.255.255',
        ];

        $ipLong = ip2long($ip);
        if ($ipLong === false) return false;

        foreach ($privateRanges as $range) {
            [$start, $end] = explode('|', $range);
            if ($ipLong >= ip2long($start) && $ipLong <= ip2long($end)) {
                return true;
            }
        }

        return false;
    }
}
