<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiResponseMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Asegurar que todas las respuestas tengan headers JSON apropiados
        if ($response instanceof \Illuminate\Http\JsonResponse) {
            $response->headers->set('Content-Type', 'application/json');
            $response->headers->set('X-API-Version', '1.0.0');
            $response->headers->set('X-Powered-By', 'Laravel API Backend');
            
            // Para rutas de autenticación, agregar headers de seguridad
            if ($request->is('api/auth/*') || $request->is('sanctum/*')) {
                $response->headers->set('X-Auth-Type', 'cookie-based');
                $response->headers->set('X-CSRF-Protection', 'enabled');
            }
        }

        // Si la respuesta no es JSON pero debería serlo (para rutas API o web)
        if (($request->is('api/*') || $request->is('/')) && !$response instanceof \Illuminate\Http\JsonResponse) {
            // Convertir respuestas no-JSON a JSON para rutas API
            $content = $response->getContent();
            
            if (empty($content)) {
                return response()->json([
                    'message' => 'Success',
                    'status_code' => $response->getStatusCode()
                ], $response->getStatusCode());
            }
            
            // Si el contenido no es JSON válido, envolverlo
            if (!$this->isJson($content)) {
                return response()->json([
                    'message' => $content ?: 'Success',
                    'status_code' => $response->getStatusCode()
                ], $response->getStatusCode());
            }
        }

        // Para la ruta CSRF cookie, mantener la respuesta original (204 No Content)
        if ($request->is('sanctum/csrf-cookie')) {
            return $response;
        }

        return $response;
    }

    /**
     * Verificar si una cadena es JSON válido
     */
    private function isJson($string): bool
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
}

