<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica las llamadas service-to-service provenientes de n8n.
 * n8n debe enviar el secreto compartido en `Authorization: Bearer <secret>`
 * (o en el header `X-N8N-Token`). Comparación en tiempo constante.
 */
class VerifyN8nToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('n8n.secret');

        if ($expected === '') {
            return response()->json([
                'success' => false,
                'message' => 'Integración n8n no configurada (N8N_WEBHOOK_SECRET vacío).',
            ], 503);
        }

        $provided = $request->bearerToken() ?: (string) $request->header('X-N8N-Token');

        if (! is_string($provided) || ! hash_equals($expected, $provided)) {
            return response()->json([
                'success' => false,
                'message' => 'No autorizado.',
            ], 401);
        }

        return $next($request);
    }
}
