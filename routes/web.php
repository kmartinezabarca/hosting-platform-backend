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

// --- GRUPO DE RUTAS /api ---
Route::prefix("api")->group(function () {
    // Incluir rutas de autenticación
    require __DIR__.'/auth.php';

    // Incluir rutas del módulo Cliente
    require __DIR__.'/client.php';

    // Incluir rutas del módulo Administrador
    require __DIR__.'/admin.php';
});

