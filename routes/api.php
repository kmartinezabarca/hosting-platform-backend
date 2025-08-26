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

// Stripe webhook (no authentication required)
Route::post('/stripe/webhook', [App\Http\Controllers\StripeWebhookController::class, 'handleWebhook']);

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

    // Services management
    Route::prefix('services')->group(function () {
        Route::get('/plans', [App\Http\Controllers\ServiceController::class, 'getServicePlans']);
        Route::post('/contract', [App\Http\Controllers\ServiceController::class, 'contractService']);
        Route::get('/user', [App\Http\Controllers\ServiceController::class, 'getUserServices']);
        Route::get('/{serviceId}', [App\Http\Controllers\ServiceController::class, 'getServiceDetails']);
        Route::put('/{serviceId}/config', [App\Http\Controllers\ServiceController::class, 'updateServiceConfig']);
        Route::post('/{serviceId}/cancel', [App\Http\Controllers\ServiceController::class, 'cancelService']);
        Route::post('/{serviceId}/suspend', [App\Http\Controllers\ServiceController::class, 'suspendService']);
        Route::post('/{serviceId}/reactivate', [App\Http\Controllers\ServiceController::class, 'reactivateService']);
        Route::get('/{serviceId}/usage', [App\Http\Controllers\ServiceController::class, 'getServiceUsage']);
        Route::get('/{serviceId}/backups', [App\Http\Controllers\ServiceController::class, 'getServiceBackups']);
        Route::post('/{serviceId}/backups', [App\Http\Controllers\ServiceController::class, 'createServiceBackup']);
        Route::post('/{serviceId}/backups/{backupId}/restore', [App\Http\Controllers\ServiceController::class, 'restoreServiceBackup']);
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

    // Subscriptions management
    Route::prefix('subscriptions')->group(function () {
        Route::get('/', [App\Http\Controllers\SubscriptionController::class, 'getUserSubscriptions']);
        Route::post('/', [App\Http\Controllers\SubscriptionController::class, 'createSubscription']);
        Route::get('/{subscriptionId}', [App\Http\Controllers\SubscriptionController::class, 'getSubscriptionDetails']);
        Route::post('/{subscriptionId}/cancel', [App\Http\Controllers\SubscriptionController::class, 'cancelSubscription']);
        Route::post('/{subscriptionId}/resume', [App\Http\Controllers\SubscriptionController::class, 'resumeSubscription']);
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


// Product routes (public)
Route::get('/products', [App\Http\Controllers\ProductController::class, 'index']);
Route::get('/products/{uuid}', [App\Http\Controllers\ProductController::class, 'show']);
Route::get('/products/service-type/{serviceType}', [App\Http\Controllers\ProductController::class, 'getByServiceType']);

// Protected routes (require authentication)
Route::middleware(['auth:sanctum'])->group(function () {
    // Invoice management
    Route::prefix('invoices')->group(function () {
        Route::get('/', [App\Http\Controllers\InvoiceController::class, 'index']);
        Route::get('/stats', [App\Http\Controllers\InvoiceController::class, 'getStats']);
        Route::get('/{uuid}', [App\Http\Controllers\InvoiceController::class, 'show']);
    });

    // Ticket management
    Route::prefix('tickets')->group(function () {
        Route::get('/', [App\Http\Controllers\TicketController::class, 'index']);
        Route::post('/', [App\Http\Controllers\TicketController::class, 'store']);
        Route::get('/stats', [App\Http\Controllers\TicketController::class, 'getStats']);
        Route::get('/{uuid}', [App\Http\Controllers\TicketController::class, 'show']);
        Route::post('/{uuid}/reply', [App\Http\Controllers\TicketController::class, 'addReply']);
        Route::put('/{uuid}/close', [App\Http\Controllers\TicketController::class, 'close']);
    });

    // Domain management
    Route::prefix('domains')->group(function () {
        Route::get('/', [App\Http\Controllers\DomainController::class, 'index']);
        Route::post('/', [App\Http\Controllers\DomainController::class, 'store']);
        Route::get('/stats', [App\Http\Controllers\DomainController::class, 'getStats']);
        Route::post('/check-availability', [App\Http\Controllers\DomainController::class, 'checkAvailability']);
        Route::get('/{uuid}', [App\Http\Controllers\DomainController::class, 'show']);
        Route::put('/{uuid}', [App\Http\Controllers\DomainController::class, 'update']);
        Route::post('/{uuid}/renew', [App\Http\Controllers\DomainController::class, 'renew']);
    });
});

// Admin routes (protected by admin middleware)
Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    // Product management (Admin only)
    Route::prefix('products')->group(function () {
        Route::post('/', [App\Http\Controllers\ProductController::class, 'store']);
        Route::put('/{uuid}', [App\Http\Controllers\ProductController::class, 'update']);
        Route::delete('/{uuid}', [App\Http\Controllers\ProductController::class, 'destroy']);
    });

    // Invoice management (Admin only)
    Route::prefix('invoices')->group(function () {
        Route::post('/', [App\Http\Controllers\InvoiceController::class, 'store']);
        Route::put('/{uuid}/status', [App\Http\Controllers\InvoiceController::class, 'updateStatus']);
    });
});

