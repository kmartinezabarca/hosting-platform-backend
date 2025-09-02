<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Render an exception into an HTTP response.
     */
    public function render($request, Throwable $e)
    {
        // Para rutas API, siempre devolver respuestas JSON
        if ($request->is('api/*') || $request->expectsJson()) {
            return $this->handleApiException($request, $e);
        }

        return parent::render($request, $e);
    }

    /**
     * Handle API exceptions with professional JSON responses
     */
    protected function handleApiException(Request $request, Throwable $e)
    {
        // Autenticación requerida
        if ($e instanceof AuthenticationException) {
            return response()->json([
                'error' => 'Acceso no autorizado',
                'message' => 'Este servicio API es de uso exclusivo para clientes autorizados de ROKE Industries. Acceso denegado.',
                'status_code' => 403,
                'type' => 'unauthorized_access'
            ], 403);
        }

        // Errores de validación
        if ($e instanceof ValidationException) {
            return response()->json([
                'error' => 'Validation failed',
                'message' => 'The provided data is invalid.',
                'status_code' => 422,
                'type' => 'validation_error',
                'errors' => $e->errors()
            ], 422);
        }

        // Modelo no encontrado
        if ($e instanceof ModelNotFoundException) {
            return response()->json([
                'error' => 'Resource not found',
                'message' => 'The requested resource could not be found.',
                'status_code' => 404,
                'type' => 'not_found_error'
            ], 404);
        }

        // Ruta no encontrada
        if ($e instanceof NotFoundHttpException) {
            return response()->json([
                'error' => 'Recurso no encontrado',
                'message' => 'El recurso solicitado no existe o no está disponible para este tipo de acceso.',
                'status_code' => 404,
                'type' => 'not_found'
            ], 404);
        }

        // Método no permitido
        if ($e instanceof MethodNotAllowedHttpException) {
            return response()->json([
                'error' => 'Method not allowed',
                'message' => 'The HTTP method used is not allowed for this endpoint.',
                'status_code' => 405,
                'type' => 'method_not_allowed_error'
            ], 405);
        }

        // Errores HTTP generales
        if ($e instanceof HttpException) {
            return response()->json([
                'error' => 'HTTP error',
                'message' => $e->getMessage() ?: 'An HTTP error occurred.',
                'status_code' => $e->getStatusCode(),
                'type' => 'http_error'
            ], $e->getStatusCode());
        }

        // Error interno del servidor
        $statusCode = 500;
        $message = 'An internal server error occurred.';
        
        // En desarrollo, mostrar más detalles
        if (config('app.debug')) {
            $message = $e->getMessage();
        }

        return response()->json([
            'error' => 'Internal server error',
            'message' => $message,
            'status_code' => $statusCode,
            'type' => 'server_error'
        ], $statusCode);
    }

    /**
     * Convert an authentication exception into a response.
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'error' => 'Authentication required',
                'message' => 'You must be authenticated to access this resource.',
                'status_code' => 401,
                'type' => 'authentication_error'
            ], 401);
        }

        return redirect()->guest($exception->redirectTo() ?? route('login'));
    }
}

