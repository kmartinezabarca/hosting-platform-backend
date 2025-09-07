
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\GoogleLoginController;
use App\Http\Controllers\Auth\TwoFactorController;

/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
|
| Rutas de autenticación compartidas entre módulos de Cliente y Administrador.
| Estas rutas manejan el login, registro, autenticación de dos factores y
| funcionalidades relacionadas con Google OAuth.
|
*/

// Public authentication routes (initial login/registration, no session required yet)
Route::post("auth/register", [AuthController::class, "register"]);
Route::post("auth/login", [AuthController::class, "login"]);
Route::post("auth/google/callback", [GoogleLoginController::class, "handleGoogleCallback"]);
Route::post("auth/2fa/verify", [TwoFactorController::class, "verifyLogin"]);

// Protected authentication routes (require active session)
Route::middleware("auth")->group(function () {
    // Authentication management
    Route::post("auth/logout", [AuthController::class, "logout"]);
    Route::get("/auth/me", [AuthController::class, "me"]);
    Route::get("/user", function (Illuminate\Http\Request $request) {
        return $request->user();
    });

    // Two-Factor Authentication management
    Route::prefix("2fa")->group(function () {
        Route::get("/status", [TwoFactorController::class, "getStatus"]);
        Route::post("/generate", [TwoFactorController::class, "generateSecret"]);
        Route::post("/enable", [TwoFactorController::class, "enable"]);
        Route::post("/disable", [TwoFactorController::class, "disable"]);
        Route::post("/verify", [TwoFactorController::class, "verify"]);
    });
});

