<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea a usuarios cuya cuenta no está activa (suspendida, inactiva, etc.)
 * en las rutas que OPERAN infraestructura. Espeja el chequeo de status que
 * AdminMiddleware ya aplica al panel admin, para que la sesión viva de un
 * cliente suspendido no pueda seguir ejecutando acciones sobre sus servicios.
 *
 * NOTA de diseño: se aplica solo a rutas operativas (servicios, dominios). Las
 * rutas de facturación/pagos/soporte/perfil quedan FUERA a propósito, para que
 * un cliente suspendido todavía pueda pagar y reactivar su cuenta. No bloquear
 * todo el portal evita atrapar al usuario sin vía de regularización.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success'    => false,
                'message'    => 'Unauthorized. Please login first.',
                'error_code' => 'UNAUTHORIZED',
            ], 401);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'success'    => false,
                'message'    => 'Tu cuenta no está activa. Regulariza tu facturación o contacta a soporte para reactivarla.',
                'error_code' => 'ACCOUNT_INACTIVE',
            ], 403);
        }

        return $next($request);
    }
}
