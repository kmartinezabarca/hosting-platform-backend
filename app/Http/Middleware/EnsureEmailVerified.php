<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige email verificado para acciones de compra/contratación.
 *
 * Los usuarios que entran con Google OAuth quedan verificados al registrarse,
 * así que esto solo afecta a quien se registró con correo y aún no confirmó.
 * Devuelve un error_code claro (EMAIL_NOT_VERIFIED) para que el frontend
 * muestre el aviso de "verifica tu correo" en vez de un 403 genérico.
 *
 * Se aplica de forma puntual a los endpoints de contratación/pago, NO a todo el
 * portal (decisión de producto): el cliente puede navegar y ver su cuenta, pero
 * no concretar una compra hasta verificar.
 */
class EnsureEmailVerified
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

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'success'    => false,
                'message'    => 'Verifica tu correo electrónico antes de contratar o pagar un servicio.',
                'error_code' => 'EMAIL_NOT_VERIFIED',
            ], 403);
        }

        return $next($request);
    }
}
