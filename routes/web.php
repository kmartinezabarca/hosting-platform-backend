<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

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

// API routes are loaded under the 'api' middleware group in RouteServiceProvider.
// Do NOT require auth.php / client.php / admin.php here — they must run under
// EnsureFrontendRequestsAreStateful (api group) for Sanctum cookie auth to work.

