<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lee el Sanctum token del cookie HttpOnly `auth_token` e inyecta la cabecera
 * `Authorization: Bearer <token>` antes de que el guard `auth:sanctum` evalúe
 * la solicitud.
 *
 * Flujo:
 *   1. El cliente hace login → el backend crea un Personal Access Token y lo
 *      guarda en un cookie HttpOnly llamado `auth_token`.
 *   2. El navegador envía automáticamente el cookie en cada petición posterior.
 *   3. Este middleware extrae el valor del cookie y lo convierte en un header
 *      `Authorization: Bearer` para que el guard `auth:sanctum` pueda validarlo.
 *
 * Seguridad:
 *   - El cookie es HttpOnly, por lo que JavaScript nunca puede leerlo.
 *   - `auth_token` está excluido de EncryptCookies porque su valor debe ser el
 *     Personal Access Token exacto que Sanctum espera como Bearer.
 *   - El middleware solo inyecta el header si NO hay ya un Bearer token presente,
 *     respetando el flujo normal de autenticación por header.
 */
class InjectTokenFromCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        // Si ya viene un Authorization: Bearer header no hacemos nada
        if (! $request->bearerToken() && $request->cookie('auth_token')) {
            $request->headers->set(
                'Authorization',
                'Bearer ' . $request->cookie('auth_token')
            );
        }

        return $next($request);
    }
}
