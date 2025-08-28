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

        // Payment routes
    Route::prefix('payments')->group(function () {
        Route::get('/methods', [App\Http\Controllers\PaymentController::class, 'getPaymentMethods']);
        Route::post('/methods', [App\Http\Controllers\PaymentController::class, 'addPaymentMethod']);
        Route::put('/methods/{id}', [App\Http\Controllers\PaymentController::class, 'updatePaymentMethod']);
        Route::delete('/methods/{id}', [App\Http\Controllers\PaymentController::class, 'deletePaymentMethod']);
        Route::post('/setup-intent', [App\Http\Controllers\PaymentController::class, 'createSetupIntent']);
        Route::post('/process', [App\Http\Controllers\PaymentController::class, 'processPayment']);
        Route::post('/intent', [App\Http\Controllers\PaymentController::class, 'createPaymentIntent']);
        Route::get('/stats', [App\Http\Controllers\PaymentController::class, 'getPaymentStats']);
        Route::get('/transactions', [App\Http\Controllers\PaymentController::class, 'getTransactions']);
    });;

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
    // Dashboard
    Route::get('/dashboard/stats', [App\Http\Controllers\AdminController::class, 'getDashboardStats']);

    // User management
    Route::get('/users', [App\Http\Controllers\AdminController::class, 'getUsers']);
    Route::post('/users', [App\Http\Controllers\AdminController::class, 'createUser']);
    Route::put('/users/{id}', [App\Http\Controllers\AdminController::class, 'updateUser']);
    Route::put('/users/{id}/status', [App\Http\Controllers\AdminController::class, 'updateUserStatus']);
    Route::delete('/users/{id}', [App\Http\Controllers\AdminController::class, 'deleteUser']);

    // Service management
    Route::get('/services', [App\Http\Controllers\AdminController::class, 'getServices']);
    Route::put('/services/{id}/status', [App\Http\Controllers\AdminController::class, 'updateServiceStatus']);

    // Invoice management
    Route::get('/invoices', [App\Http\Controllers\AdminController::class, 'getInvoices']);
    Route::post('/invoices', [App\Http\Controllers\AdminController::class, 'createInvoice']);
    Route::put('/invoices/{id}', [App\Http\Controllers\AdminController::class, 'updateInvoice']);
    Route::put('/invoices/{id}/status', [App\Http\Controllers\AdminController::class, 'updateInvoiceStatus']);
    Route::post('/invoices/{id}/mark-paid', [App\Http\Controllers\AdminController::class, 'markInvoiceAsPaid']);
    Route::post('/invoices/{id}/send-reminder', [App\Http\Controllers\AdminController::class, 'sendInvoiceReminder']);
    Route::post('/invoices/{id}/cancel', [App\Http\Controllers\AdminController::class, 'cancelInvoice']);
    Route::delete('/invoices/{id}', [App\Http\Controllers\AdminController::class, 'deleteInvoice']);

    // Ticket management
    Route::get('/tickets', [App\Http\Controllers\AdminController::class, 'getTickets']);
    Route::post('/tickets', [App\Http\Controllers\AdminController::class, 'createTicket']);
    Route::put('/tickets/{id}', [App\Http\Controllers\AdminController::class, 'updateTicket']);
    Route::put('/tickets/{id}/assign', [App\Http\Controllers\AdminController::class, 'assignTicket']);
    Route::put('/tickets/{id}/status', [App\Http\Controllers\AdminController::class, 'updateTicketStatus']);
    Route::put('/tickets/{id}/priority', [App\Http\Controllers\AdminController::class, 'updateTicketPriority']);
    Route::post('/tickets/{id}/reply', [App\Http\Controllers\AdminController::class, 'addTicketReply']);
    Route::delete('/tickets/{id}', [App\Http\Controllers\AdminController::class, 'deleteTicket']);
    Route::get('/tickets/categories', [App\Http\Controllers\AdminController::class, 'getTicketCategories']);
    Route::get('/tickets/agents', [App\Http\Controllers\AdminController::class, 'getSupportAgents']);

    // Categories management
    Route::prefix("categories")->group(function () {
        Route::post("/", [App\Http\Controllers\CategoryController::class, "store"]);
        Route::put("/{uuid}", [App\Http\Controllers\CategoryController::class, "update"]);
        Route::delete("/{uuid}", [App\Http\Controllers\CategoryController::class, "destroy"]);
    });

    // Billing cycles management
    Route::prefix("billing-cycles")->group(function () {
        Route::post("/", [App\Http\Controllers\BillingCycleController::class, "store"]);
        Route::put("/{uuid}", [App\Http\Controllers\BillingCycleController::class, "update"]);
        Route::delete("/{uuid}", [App\Http\Controllers\BillingCycleController::class, "destroy"]);
    });

    // Service plans management
    Route::prefix("service-plans")->group(function () {
        Route::post("/", [App\Http\Controllers\ServicePlanController::class, "store"]);
        Route::put("/{uuid}", [App\Http\Controllers\ServicePlanController::class, "update"]);
        Route::delete("/{uuid}", [App\Http\Controllers\ServicePlanController::class, "destroy"]);
    });

    // Add-ons management
    Route::prefix("add-ons")->group(function () {
        Route::post("/", [App\Http\Controllers\AddOnController::class, "store"]);
        Route::put("/{uuid}", [App\Http\Controllers\AddOnController::class, "update"]);
        Route::delete("/{uuid}", [App\Http\Controllers\AddOnController::class, "destroy"]);
        Route::post("/{uuid}/attach-plan", [App\Http\Controllers\AddOnController::class, "attachToPlan"]);
        Route::post("/{uuid}/detach-plan", [App\Http\Controllers\AddOnController::class, "detachFromPlan"]);
    });

    // Product management (Admin only)
    Route::prefix('products')->group(function () {
        Route::post('/', [App\Http\Controllers\ProductController::class, 'store']);
        Route::put('/{uuid}', [App\Http\Controllers\ProductController::class, 'update']);
        Route::delete('/{uuid}', [App\Http\Controllers\ProductController::class, 'destroy']);
    });
});


