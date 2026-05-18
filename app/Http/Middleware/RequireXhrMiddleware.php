<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea acceso directo desde el navegador a rutas de API autenticadas por cookie.
 *
 * Cuando un usuario pega una URL de la API en el navegador, el browser envía
 * automáticamente las cookies pero NO incluye el header X-Requested-With.
 * Axios (el cliente SPA) siempre envía X-Requested-With: XMLHttpRequest.
 *
 * Esto previene que alguien copie una URL de la API y la abra directamente.
 * Las integraciones externas que usan Bearer token (sin cookie) no se ven afectadas.
 */
class RequireXhrMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Solo aplica cuando la solicitud usa autenticación por cookie.
        // Peticiones con Bearer token explícito (integraciones externas) pasan libre.
        if (
            $request->cookie('auth_token') &&
            $request->header('X-Requested-With') !== 'XMLHttpRequest'
        ) {
            return response()->json([
                'success'    => false,
                'message'    => 'Acceso directo no permitido. Usa la aplicación.',
                'error_code' => 'DIRECT_ACCESS_FORBIDDEN',
            ], 403);
        }

        return $next($request);
    }
}
