<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Domains\Platform\Http\Controllers\Auth\EmailVerificationController;
use App\Domains\Platform\SiteBuilder\Http\Controllers\PublicPageController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Aquí van las rutas base del sistema. Las rutas específicas de cada módulo
| se han movido a archivos separados para una mejor organización.
| TODAS LAS RESPUESTAS SON JSON - NO HAY FRONTEND EN ESTE BACKEND
|
*/

// Ruta raíz - Información del API
Route::get("/", function () {
    return response()->json([
        "message" => "ROKE Industries Backend API. Access via authorized clients only.",
        "status" => "active"
    ], 200, [
        "Content-Type" => "application/json",
        "X-API-Version" => "1.0.0",
    ]);
});

// CSRF Cookie endpoint - CRÍTICO para autenticación con cookies
Route::get("/sanctum/csrf-cookie", function (Request $request) {
    return response()->noContent();
});

Route::get('/api/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

// SiteBuilder — página publicada servida por el backend (Opción A). Pública y
// sin estado; solo entrega páginas con published_at. Exponer en un dominio
// separado y sin cookies (rokeindustries.app), nunca en el del api/app.
Route::get('/p/{uuid}', [PublicPageController::class, 'serve'])
    ->where('uuid', '[0-9a-fA-F-]{36}')
    ->name('site-builder.public');

// API routes are loaded under the 'api' middleware group in RouteServiceProvider.
// Do NOT require auth.php / client.php / admin.php here — they must run under
// EnsureFrontendRequestsAreStateful (api group) for Sanctum cookie auth to work.
