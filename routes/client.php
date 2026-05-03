<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Client\DashboardController;
use App\Http\Controllers\Client\ProfileController;
use App\Http\Controllers\Client\ServiceController;
use App\Http\Controllers\Client\GameServerController;
use App\Http\Controllers\Client\FileManagerController;
use App\Http\Controllers\Client\PaymentController;
use App\Http\Controllers\Client\SubscriptionController;
use App\Http\Controllers\Client\TicketController;
use App\Http\Controllers\Client\InvoiceController;
use App\Http\Controllers\Client\TransactionController;
use App\Http\Controllers\Client\DomainController;
use App\Http\Controllers\Client\NotificationController;
use App\Http\Controllers\Client\SupportChatController;
use App\Http\Controllers\Client\FiscalController;

Route::middleware('auth')->group(function () {

    // ── Dashboard ─────────────────────────────────────────────────────────────
    Route::prefix('dashboard')->group(function () {
        Route::get('/stats',    [DashboardController::class, 'getStats']);
        Route::get('/services', [DashboardController::class, 'getServices']);
        Route::get('/activity', [DashboardController::class, 'getActivity']);
    });

    // ── Profile ───────────────────────────────────────────────────────────────
    Route::prefix('profile')->group(function () {
        Route::get   ('/',                  [ProfileController::class, 'getProfile']);
        Route::put   ('/',                  [ProfileController::class, 'updateProfile']);
        Route::post  ('/avatar',            [ProfileController::class, 'updateAvatar']);
        Route::put   ('/email',             [ProfileController::class, 'updateEmail']);
        Route::put   ('/password',          [ProfileController::class, 'updatePassword']);
        Route::get   ('/devices',           [ProfileController::class, 'getSessions']);
        Route::get   ('/security',          [ProfileController::class, 'getSecurityOverview']);
        Route::delete('/account',           [ProfileController::class, 'deleteAccount']);
        Route::delete('/sessions/{uuid}',   [ProfileController::class, 'revokeSession']);
        Route::post  ('/devices/revoke-others', [ProfileController::class, 'revokeOtherSessions']);
    });

    // ── Services ──────────────────────────────────────────────────────────────
    Route::prefix('services')->group(function () {

        // Globales (sin UUID)
        Route::get ('/plans',   [ServiceController::class, 'getServicePlans']);
        Route::post('/contract',[ServiceController::class, 'contractService']);
        Route::get ('/user',    [ServiceController::class, 'getUserServices']);
        Route::get ('/metrics', [GameServerController::class, 'getAllServicesMetrics']);
        Route::get ('/game-servers/{nest_id}/eggs', [GameServerController::class, 'listEggs']);

        // Por servicio
        Route::prefix('{uuid}')->group(function () {

            // ── Servicio base ────────────────────────────────────────────────
            Route::get   ('/',              [ServiceController::class, 'getServiceDetails']);
            Route::get   ('/invoices',      [ServiceController::class, 'getServiceInvoices']);
            Route::patch ('/configuration', [ServiceController::class, 'updateConfiguration']);
            Route::put   ('/config',        [ServiceController::class, 'updateServiceConfig']);
            Route::post  ('/cancel',        [ServiceController::class, 'cancelService']);
            Route::post  ('/suspend',       [ServiceController::class, 'suspendService']);
            Route::post  ('/reactivate',    [ServiceController::class, 'reactivateService']);

            // ── Backups ──────────────────────────────────────────────────────
            Route::get ('/backups',                      [ServiceController::class, 'getServiceBackups']);
            Route::post('/backups',                      [ServiceController::class, 'createServiceBackup']);
            Route::post('/backups/{backupId}/restore',   [ServiceController::class, 'restoreServiceBackup']);

            // ── Archivos ─────────────────────────────────────────────────────
            Route::prefix('files')->group(function () {
                Route::get ('/list',             [FileManagerController::class, 'listFiles']);
                Route::get ('/upload',           [FileManagerController::class, 'getUploadUrl']);
                Route::post('/delete',           [FileManagerController::class, 'deleteFiles']);
                Route::get ('/download',         [FileManagerController::class, 'getDownloadUrl']);
            });

            // ── Game server ──────────────────────────────────────────────────
            Route::prefix('game-server')->group(function () {
                Route::get ('/usage',             [GameServerController::class, 'getServiceUsage']);
                Route::post('/power',             [GameServerController::class, 'power']);
                Route::get ('/websocket',         [GameServerController::class, 'websocket']);
                Route::post('/command',           [GameServerController::class, 'command']);
                Route::get ('/software-options',  [GameServerController::class, 'softwareOptions']);
                Route::get ('/configuration',     [GameServerController::class, 'configuration']);
                Route::patch('/software',         [GameServerController::class, 'updateSoftware']);
                Route::patch('/server-properties',[GameServerController::class, 'updateServerProperties']);
                Route::post('/restart-required',  [GameServerController::class, 'markRestartRequired']);
            });
        });
    });

    // ── Payments ──────────────────────────────────────────────────────────────
    Route::prefix('payments')->group(function () {
        Route::get   ('/methods',       [PaymentController::class, 'getPaymentMethods']);
        Route::post  ('/methods',       [PaymentController::class, 'addPaymentMethod']);
        Route::put   ('/methods/{id}',  [PaymentController::class, 'updatePaymentMethod']);
        Route::delete('/methods/{id}',  [PaymentController::class, 'deletePaymentMethod']);
        Route::post  ('/setup-intent',  [PaymentController::class, 'createSetupIntent']);
        Route::post  ('/process',       [PaymentController::class, 'processPayment']);
        Route::post  ('/intent',        [PaymentController::class, 'createSetupIntent']);
        Route::get   ('/stats',         [PaymentController::class, 'getPaymentStats']);
        Route::get   ('/transactions',  [PaymentController::class, 'getTransactions']);
    });

    // ── Subscriptions ─────────────────────────────────────────────────────────
    Route::prefix('subscriptions')->group(function () {
        Route::get ('/',                        [SubscriptionController::class, 'getUserSubscriptions']);
        Route::post('/',                        [SubscriptionController::class, 'createSubscription']);
        Route::get ('/{subscriptionId}',        [SubscriptionController::class, 'getSubscriptionDetails']);
        Route::post('/{subscriptionId}/cancel', [SubscriptionController::class, 'cancelSubscription']);
        Route::post('/{subscriptionId}/resume', [SubscriptionController::class, 'resumeSubscription']);
    });

    // ── Tickets ───────────────────────────────────────────────────────────────
    Route::prefix('tickets')->group(function () {
        Route::get ('/',            [TicketController::class, 'index']);
        Route::post('/',            [TicketController::class, 'store']);
        Route::get ('/stats',       [TicketController::class, 'getStats']);
        Route::get ('/{uuid}',      [TicketController::class, 'show']);
        Route::post('/{uuid}/reply',[TicketController::class, 'addReply']);
        Route::put ('/{uuid}/close',[TicketController::class, 'close']);
    });

    // ── Invoices ──────────────────────────────────────────────────────────────
    Route::prefix('invoices')->group(function () {
        Route::get('/',                     [InvoiceController::class, 'index']);
        Route::get('/stats',                [InvoiceController::class, 'getStats']);
        Route::get('/{uuid}',               [InvoiceController::class, 'show']);
        Route::get('/{uuid}/pdf',           [InvoiceController::class, 'downloadPdf']);
        Route::get('/{uuid}/xml',           [InvoiceController::class, 'downloadXml']);
        Route::put('/{uuid}/fiscal-data',   [InvoiceController::class, 'updateFiscalData']);
    });

    // ── Transactions ──────────────────────────────────────────────────────────
    Route::prefix('transactions')->group(function () {
        Route::get('/',         [TransactionController::class, 'index']);
        Route::get('/stats',    [TransactionController::class, 'getStats']);
        Route::get('/recent',   [TransactionController::class, 'getRecent']);
        Route::get('/{uuid}',   [TransactionController::class, 'show']);
    });

    // ── Domains ───────────────────────────────────────────────────────────────
    Route::prefix('domains')->group(function () {
        Route::get  ('/',                       [DomainController::class, 'index']);
        Route::post ('/',                       [DomainController::class, 'store']);
        Route::get  ('/stats',                  [DomainController::class, 'getStats']);
        Route::post ('/check-availability',     [DomainController::class, 'checkAvailability']);
        Route::get  ('/{uuid}',                 [DomainController::class, 'show']);
        Route::put  ('/{uuid}',                 [DomainController::class, 'update']);
        Route::post ('/{uuid}/renew',           [DomainController::class, 'renew']);
    });

    // ── Notifications ─────────────────────────────────────────────────────────
    Route::prefix('notifications')->name('client.notifications.')->group(function () {
        Route::get ('/',                        [NotificationController::class, 'index'])->name('index');
        Route::get ('/unread-count',            [NotificationController::class, 'getUnreadCount'])->name('unread-count');
        Route::get ('/preferences',             [NotificationController::class, 'getPreferences'])->name('preferences');
        Route::put ('/preferences',             [NotificationController::class, 'updatePreferences'])->name('update-preferences');
        Route::put ('/{notification}/read',     [NotificationController::class, 'markAsRead'])->name('mark-as-read');
        Route::put ('/mark-all-read',           [NotificationController::class, 'markAllAsRead'])->name('mark-all-read');
        Route::delete('/{notification}',        [NotificationController::class, 'destroy'])->name('destroy');
    });

    // ── Fiscal / CFDI ─────────────────────────────────────────────────────────
    Route::prefix('fiscal')->group(function () {
        Route::get('/regimes',   [FiscalController::class, 'regimes']);
        Route::get('/cfdi-uses', [FiscalController::class, 'cfdiUses']);

        Route::prefix('profiles')->group(function () {
            Route::get   ('/',            [FiscalController::class, 'index']);
            Route::post  ('/',            [FiscalController::class, 'store']);
            Route::get   ('/{uuid}',      [FiscalController::class, 'show']);
            Route::put   ('/{uuid}',      [FiscalController::class, 'update']);
            Route::delete('/{uuid}',      [FiscalController::class, 'destroy']);
            Route::put   ('/{uuid}/set-default', [FiscalController::class, 'setDefault']);
        });
    });

    // ── Support Chat ──────────────────────────────────────────────────────────
    Route::prefix('chat')->name('client.chat.')->group(function () {
        Route::get ('support-room',             [SupportChatController::class, 'getSupportRoom'])->name('support-room');
        Route::get ('unread-count',             [SupportChatController::class, 'getUnreadCount'])->name('unread-count');
        Route::get ('history',                  [SupportChatController::class, 'getHistory'])->name('history');
        Route::get ('/{ticket}/messages',       [SupportChatController::class, 'getMessages'])->name('messages');
        Route::post('/{ticket}/messages',       [SupportChatController::class, 'sendMessage'])->name('send-message');
        Route::put ('/{ticket}/read',           [SupportChatController::class, 'markAsRead'])->name('mark-as-read');
        Route::put ('/{ticket}/close',          [SupportChatController::class, 'closeRoom'])->name('close');
    });
});
