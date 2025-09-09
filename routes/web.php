<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Events\ServiceNotificationSent;

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

Route::get('/test-reverb', function () {
    try {
        // --- CAMBIO 2: Prepara los datos que el constructor de tu evento pueda necesitar ---
        // Viendo el nombre del evento, es probable que necesite un mensaje, un tipo, etc.
        // Vamos a enviarle datos de prueba simples.
        $testData = [
            'title' => 'Notificación de Prueba',
            'message' => 'Si ves esto, Reverb está funcionando correctamente.',
            'type' => 'info',
        ];

        echo "<h1>Disparando evento existente: ServiceNotificationSent</h1>";
        echo "<pre>" . json_encode($testData, JSON_PRETTY_PRINT) . "</pre>";

        // --- CAMBIO 3: Dispara el evento correcto ---
        event(new ServiceNotificationSent($testData));

        echo "<h2>¡Evento disparado!</h2>";
        echo "<p>Revisa tu consola de Reverb y la consola de tu frontend de React.</p>";

    } catch (\Exception $e) {
        return "<h2>Error al disparar el evento:</h2><p>" . $e->getMessage() . "</p>";
    }
});

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

