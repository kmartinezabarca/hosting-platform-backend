<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Client\DashboardController;
use App\Http\Controllers\Client\ProfileController;
use App\Http\Controllers\Client\ServiceController;
use App\Http\Controllers\Client\PaymentController;
use App\Http\Controllers\Client\SubscriptionController;
use App\Http\Controllers\Client\TicketController;
use App\Http\Controllers\Client\InvoiceController;
use App\Http\Controllers\Client\TransactionController;
use App\Http\Controllers\Client\DomainController;
use App\Http\Controllers\Client\ProductController;
use App\Http\Controllers\Client\CategoryController;
use App\Http\Controllers\Client\BillingCycleController;
use App\Http\Controllers\Client\ServicePlanController;

/*
|--------------------------------------------------------------------------
| Client Module Routes
|--------------------------------------------------------------------------
|
| Rutas específicas para el módulo de Cliente. Todas estas rutas requieren
| autenticación y están dirigidas a funcionalidades que los clientes
| utilizan para gestionar sus servicios, pagos, tickets, etc.
|
*/

Route::middleware("auth")->group(function () {
    // Dashboard routes
    Route::get("/dashboard/stats", [DashboardController::class, "getStats"]);
    Route::get("/dashboard/services", [DashboardController::class, "getServices"]);
    Route::get("/dashboard/activity", [DashboardController::class, "getActivity"]);

    // Profile management
    Route::prefix("profile")->group(function () {
        Route::get("/", [ProfileController::class, "getProfile"]);
        Route::put("/", [ProfileController::class, "updateProfile"]);
        Route::post("/avatar", [ProfileController::class, "updateAvatar"]);
        Route::put("/email", [ProfileController::class, "updateEmail"]);
        Route::put("/password", [ProfileController::class, "updatePassword"]);
        Route::get("/devices", [ProfileController::class, "getSessions"]);
        Route::get("/security", [ProfileController::class, "getSecurityOverview"]);
        Route::delete("/account", [ProfileController::class, "deleteAccount"]);
        Route::delete("/sessions/{uuid}", [ProfileController::class, "revokeSession"]);
        Route::post('/devices/revoke-others', [ProfileController::class, 'revokeOtherSessions']);
    });

    // Services management
    Route::prefix("services")->group(function () {
        Route::get("/plans", [ServiceController::class, "getServicePlans"]);
        Route::post("/contract", [ServiceController::class, "contractService"]);
        Route::get("/user", [ServiceController::class, "getUserServices"]);
        Route::get("/{uuid}", [ServiceController::class, "getServiceDetails"]);
        Route::get('/{uuid}/invoices', [ServiceController::class, 'getServiceInvoices']);
        Route::patch('/{uuid}/configuration', [ServiceController::class, 'updateConfiguration']);
        Route::put("/{serviceId}/config", [ServiceController::class, "updateServiceConfig"]);
        Route::post("/{serviceId}/cancel", [ServiceController::class, "cancelService"]);
        Route::post("/{serviceId}/suspend", [ServiceController::class, "suspendService"]);
        Route::post("/{serviceId}/reactivate", [ServiceController::class, "reactivateService"]);
        Route::get("/{serviceId}/usage", [ServiceController::class, "getServiceUsage"]);
        Route::get("/{serviceId}/backups", [ServiceController::class, "getServiceBackups"]);
        Route::post("/{serviceId}/backups", [ServiceController::class, "createServiceBackup"]);
        Route::post("/{serviceId}/backups/{backupId}/restore", [ServiceController::class, "restoreServiceBackup"]);
    });

    // Payment routes
    Route::prefix("payments")->group(function () {
        Route::get("/methods", [PaymentController::class, "getPaymentMethods"]);
        Route::post("/methods", [PaymentController::class, "addPaymentMethod"]);
        Route::put("/methods/{id}", [PaymentController::class, "updatePaymentMethod"]);
        Route::delete("/methods/{id}", [PaymentController::class, "deletePaymentMethod"]);
        Route::post("/setup-intent", [PaymentController::class, "createSetupIntent"]);
        Route::post("/process", [PaymentController::class, "processPayment"]);
        Route::post("/intent", [PaymentController::class, "createSetupIntent"]);
        Route::get("/stats", [PaymentController::class, "getPaymentStats"]);
        Route::get("/transactions", [PaymentController::class, "getTransactions"]);
    });

    // Subscriptions management
    Route::prefix("subscriptions")->group(function () {
        Route::get("/", [SubscriptionController::class, "getUserSubscriptions"]);
        Route::post("/", [SubscriptionController::class, "createSubscription"]);
        Route::get("/{subscriptionId}", [SubscriptionController::class, "getSubscriptionDetails"]);
        Route::post("/{subscriptionId}/cancel", [SubscriptionController::class, "cancelSubscription"]);
        Route::post("/{subscriptionId}/resume", [SubscriptionController::class, "resumeSubscription"]);
    });

    // Ticket management
    Route::prefix("tickets")->group(function () {
        Route::get("/", [TicketController::class, "index"]);
        Route::post("/", [TicketController::class, "store"]);
        Route::get("/stats", [TicketController::class, "getStats"]);
        Route::get("/{uuid}", [TicketController::class, "show"]);
        Route::post("/{uuid}/reply", [TicketController::class, "addReply"]);
        Route::put("/{uuid}/close", [TicketController::class, "close"]);
    });

    // Invoice management
    Route::prefix("invoices")->group(function () {
        Route::get("/", [InvoiceController::class, "index"]);
        Route::get("/stats", [InvoiceController::class, "getStats"]);
        Route::get("/{uuid}", [InvoiceController::class, "show"]);
        Route::get("/{uuid}/pdf", [InvoiceController::class, "downloadPdf"]);
        Route::get("/{uuid}/xml", [InvoiceController::class, "downloadXml"]);
    });

    // Transaction management
    Route::prefix("transactions")->group(function () {
        Route::get("/", [TransactionController::class, "index"]);
        Route::get("/stats", [TransactionController::class, "getStats"]);
        Route::get("/recent", [TransactionController::class, "getRecent"]);
        Route::get("/{uuid}", [TransactionController::class, "show"]);
    });

    // Domain management
    Route::prefix("domains")->group(function () {
        Route::get("/", [DomainController::class, "index"]);
        Route::post("/", [DomainController::class, "store"]);
        Route::get("/stats", [DomainController::class, "getStats"]);
        Route::post("/check-availability", [DomainController::class, "checkAvailability"]);
        Route::get("/{uuid}", [DomainController::class, "show"]);
        Route::put("/{uuid}", [DomainController::class, "update"]);
        Route::post("/{uuid}/renew", [DomainController::class, "renew"]);
    });

    // Rutas de Notificaciones para Cliente
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('/unread-count', [NotificationController::class, 'getUnreadCount'])->name('unread-count');
        Route::get('/preferences', [NotificationController::class, 'getPreferences'])->name('preferences');
        Route::put('/preferences', [NotificationController::class, 'updatePreferences'])->name('update-preferences');
        Route::put('/{notification}/read', [NotificationController::class, 'markAsRead'])->name('mark-as-read');
        Route::put('/mark-all-read', [NotificationController::class, 'markAllAsRead'])->name('mark-all-read');
        Route::delete('/{notification}', [NotificationController::class, 'destroy'])->name('destroy');
    });

    // Rutas de Chat para Cliente
    Route::prefix('chat')->name('chat.')->group(function () {
        Route::get('/support-room', [ChatController::class, 'getSupportRoom'])->name('support-room');
        Route::get('/unread-count', [ChatController::class, 'getUnreadCount'])->name('unread-count');
        Route::get('/history', [ChatController::class, 'getHistory'])->name('history');
        Route::get('/{chatRoom}/messages', [ChatController::class, 'getMessages'])->name('messages');
        Route::post('/{chatRoom}/messages', [ChatController::class, 'sendMessage'])->name('send-message');
        Route::put('/{chatRoom}/read', [ChatController::class, 'markAsRead'])->name('mark-as-read');
        Route::put('/{chatRoom}/close', [ChatController::class, 'closeRoom'])->name('close');
    });
});

