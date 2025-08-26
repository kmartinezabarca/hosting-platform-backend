<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Public authentication routes
Route::post("auth/register", [App\Http\Controllers\AuthController::class, "register"]);
Route::post("auth/login", [App\Http\Controllers\AuthController::class, "login"]);
Route::post("auth/logout", [App\Http\Controllers\AuthController::class, "logout"])->middleware("auth:sanctum");
Route::post('auth/google/callback', [App\Http\Controllers\GoogleLoginController::class, 'handleGoogleCallback']);
Route::post('auth/2fa/verify', [App\Http\Controllers\TwoFactorController::class, 'verifyLogin']);

// Protected routes (require authentication)
Route::middleware(['auth:sanctum'])->group(function () {
    // Profile management
    Route::prefix('profile')->group(function () {
        Route::get('/', [App\Http\Controllers\ProfileController::class, 'getProfile']);
        Route::put('/', [App\Http\Controllers\ProfileController::class, 'updateProfile']);
        Route::put('/email', [App\Http\Controllers\ProfileController::class, 'updateEmail']);
        Route::put('/password', [App\Http\Controllers\ProfileController::class, 'updatePassword']);
        Route::get('/sessions', [App\Http\Controllers\ProfileController::class, 'getSessions']);
        Route::get('/security', [App\Http\Controllers\ProfileController::class, 'getSecurityOverview']);
        Route::delete('/account', [App\Http\Controllers\ProfileController::class, 'deleteAccount']);
    });

    // Two-Factor Authentication
    Route::prefix('2fa')->group(function () {
        Route::get('/status', [App\Http\Controllers\TwoFactorController::class, 'getStatus']);
        Route::post('/generate', [App\Http\Controllers\TwoFactorController::class, 'generateSecret']);
        Route::post('/enable', [App\Http\Controllers\TwoFactorController::class, 'enable']);
        Route::post('/disable', [App\Http\Controllers\TwoFactorController::class, 'disable']);
        Route::post('/verify', [App\Http\Controllers\TwoFactorController::class, 'verify']);
    });

    // Payment and Billing
    Route::prefix('payments')->group(function () {
        Route::get('/methods', [App\Http\Controllers\PaymentController::class, 'getPaymentMethods']);
        Route::post('/methods', [App\Http\Controllers\PaymentController::class, 'addPaymentMethod']);
        Route::put('/methods/{id}', [App\Http\Controllers\PaymentController::class, 'updatePaymentMethod']);
        Route::delete('/methods/{id}', [App\Http\Controllers\PaymentController::class, 'deletePaymentMethod']);
        Route::get('/transactions', [App\Http\Controllers\PaymentController::class, 'getTransactions']);
        Route::post('/process', [App\Http\Controllers\PaymentController::class, 'processPayment']);
        Route::get('/stats', [App\Http\Controllers\PaymentController::class, 'getPaymentStats']);
        Route::post('/intent', [App\Http\Controllers\PaymentController::class, 'createPaymentIntent']);
    });

    // Dashboard routes
    Route::get('/dashboard/stats', [App\Http\Controllers\DashboardController::class, 'getStats']);
    Route::get('/dashboard/services', [App\Http\Controllers\DashboardController::class, 'getServices']);
    Route::get('/dashboard/activity', [App\Http\Controllers\DashboardController::class, 'getActivity']);
});

// Admin routes (protected by admin middleware)
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    Route::get('/dashboard/stats', [App\Http\Controllers\AdminController::class, 'getDashboardStats']);

    // User management
    Route::get('/users', [App\Http\Controllers\AdminController::class, 'getUsers']);
    Route::post('/users', [App\Http\Controllers\AdminController::class, 'createUser']);
    Route::put('/users/{id}', [App\Http\Controllers\AdminController::class, 'updateUser']);
    Route::delete('/users/{id}', [App\Http\Controllers\AdminController::class, 'deleteUser']);

    // Service management
    Route::get('/services', [App\Http\Controllers\AdminController::class, 'getServices']);
});

// Temporary routes for testing (without authentication)
Route::get('/test/dashboard/stats', [App\Http\Controllers\DashboardController::class, 'getStats']);
Route::get('/test/dashboard/services', [App\Http\Controllers\DashboardController::class, 'getServices']);
Route::get('/test/dashboard/activity', [App\Http\Controllers\DashboardController::class, 'getActivity']);

