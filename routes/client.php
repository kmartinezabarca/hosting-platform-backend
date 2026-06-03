<?php

use Illuminate\Support\Facades\Route;
use App\Domains\Platform\Http\Controllers\Client\DashboardController;
use App\Domains\Platform\Http\Controllers\Client\ProfileController;
use App\Domains\Platform\Http\Controllers\Client\ServiceController;
use App\Domains\Platform\Http\Controllers\Client\GameServerController;
use App\Domains\Platform\Http\Controllers\Client\FileManagerController;
use App\Domains\Platform\Http\Controllers\Client\PaymentController;
use App\Domains\Platform\Http\Controllers\Client\SubscriptionController;
use App\Domains\Platform\Http\Controllers\Client\TicketController;
use App\Domains\Platform\Http\Controllers\Client\InvoiceController;
use App\Domains\Platform\Http\Controllers\Client\TransactionController;
use App\Domains\Platform\Http\Controllers\Client\DomainController;
use App\Domains\Platform\Http\Controllers\Client\NotificationController;
use App\Domains\Platform\Http\Controllers\Client\SupportChatController;
use App\Domains\Platform\Http\Controllers\Client\FiscalController;
use App\Domains\Platform\Http\Controllers\Client\ClientSearchController;
use App\Domains\Platform\Http\Controllers\Client\InfrastructureController;
use App\Domains\Platform\Http\Controllers\Auth\EmailVerificationController;

Route::middleware(['auth:sanctum', 'session.timeout'])->group(function () {

    // ── Búsqueda global del cliente ───────────────────────────────────────────
    Route::middleware('throttle:30,1')->group(function () {
        Route::get('search',         [ClientSearchController::class, 'search']);
        Route::get('search/popular', [ClientSearchController::class, 'popular']);
    });

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
        Route::post  ('/email/verification-notification', [EmailVerificationController::class, 'sendNotification'])
            ->middleware('throttle:verification-notification');
        Route::get   ('/devices',           [ProfileController::class, 'getSessions']);
        Route::get   ('/security',          [ProfileController::class, 'getSecurityOverview']);
        Route::delete('/account',           [ProfileController::class, 'deleteAccount']);
        Route::delete('/sessions/{uuid}',   [ProfileController::class, 'revokeSession']);
        Route::post  ('/devices/revoke-others', [ProfileController::class, 'revokeOtherSessions']);
    });

    // ── Services ──────────────────────────────────────────────────────────────
    Route::prefix('services')->group(function () {

        // Globales (sin UUID)
        Route::get ('/plans',             [ServiceController::class, 'getServicePlans']);
        Route::post('/contract',          [ServiceController::class, 'contractService']);
        Route::get ('/user',              [ServiceController::class, 'getUserServices']);
        Route::post('/sync-status',       [ServiceController::class, 'syncStatus'])->middleware('throttle:sync-status');
        Route::get ('/upcoming-charges',  [ServiceController::class, 'upcomingCharges']);
        Route::get ('/metrics',           [GameServerController::class, 'getAllServicesMetrics']);
        Route::get ('/game-servers/{nest_id}/eggs', [GameServerController::class, 'listEggs']);

        // Por servicio
        Route::prefix('{uuid}')->group(function () {

            // ── Servicio base ────────────────────────────────────────────────
            Route::get   ('/',              [ServiceController::class, 'getServiceDetails']);
            Route::get   ('/invoices',      [ServiceController::class, 'getServiceInvoices']);
            Route::patch ('/configuration', [ServiceController::class, 'updateConfiguration']);
            Route::put   ('/config',        [ServiceController::class, 'updateServiceConfig']);
            Route::post  ('/cancel',        [ServiceController::class, 'cancelService']);
            Route::post  ('/reactivate-cancellation', [ServiceController::class, 'reactivateCancellation']);
            Route::post  ('/suspend',       [ServiceController::class, 'suspendService']);
            Route::post  ('/reactivate',    [ServiceController::class, 'reactivateService']);

            // ── Plan upgrade / downgrade ─────────────────────────────────────
            Route::get ('/upgrade-options', [ServiceController::class, 'upgradeOptions']);
            Route::post('/upgrade',         [ServiceController::class, 'upgradePlan']);

            // ── Activity log ─────────────────────────────────────────────────
            Route::get ('/activity',        [ServiceController::class, 'activityLog']);

            // ── Backups ──────────────────────────────────────────────────────
            Route::get   ('/backups',                                         [ServiceController::class, 'getServiceBackups']);
            Route::post  ('/backups',                                         [ServiceController::class, 'createServiceBackup']);
            Route::post  ('/backups/{backupId}/restore',                      [ServiceController::class, 'restoreServiceBackup']);
            Route::get   ('/backups/{backupId}/download',                     [ServiceController::class, 'downloadServiceBackup']);
            Route::get   ('/backups/{backupId}/file',                         [ServiceController::class, 'streamServiceBackup']);
            Route::delete('/backups/{backupId}',                              [ServiceController::class, 'deleteServiceBackup']);

            // ── Backup schedules ─────────────────────────────────────────────
            Route::get   ('/backups/schedules',                               [ServiceController::class, 'getBackupSchedules']);
            Route::post  ('/backups/schedules',                               [ServiceController::class, 'createBackupSchedule']);
            Route::put   ('/backups/schedules/{scheduleUuid}',                [ServiceController::class, 'updateBackupSchedule']);
            Route::delete('/backups/schedules/{scheduleUuid}',                [ServiceController::class, 'deleteBackupSchedule']);

            // ── Archivos ─────────────────────────────────────────────────────
            Route::prefix('files')->group(function () {
                Route::get ('/list',             [FileManagerController::class, 'listFiles']);
                Route::get ('/upload',           [FileManagerController::class, 'getUploadUrl']);
                Route::post('/delete',           [FileManagerController::class, 'deleteFiles']);
                Route::get ('/download',         [FileManagerController::class, 'getDownloadUrl']);
                Route::get ('/content',          [FileManagerController::class, 'getFileContent']);
            });

            // ── Game server ──────────────────────────────────────────────────
            Route::prefix('game-server')->group(function () {
                Route::get ('/metrics',                       [GameServerController::class, 'metricsHistory']);
                Route::get ('/ddos',                          [GameServerController::class, 'ddosStatus']);
                Route::post('/ddos/allowlist',                [GameServerController::class, 'ddosAllowlistAdd']);
                Route::delete('/ddos/allowlist/{ip}',         [GameServerController::class, 'ddosAllowlistRemove']);
                Route::get   ('/firewall',                    [GameServerController::class, 'firewallStatus']);
                Route::put   ('/firewall/settings',           [GameServerController::class, 'firewallUpdateSettings']);
                Route::post  ('/firewall/blocked-ips',        [GameServerController::class, 'firewallBlockIp']);
                Route::delete('/firewall/blocked-ips/{ip}',   [GameServerController::class, 'firewallUnblockIp']);
                Route::get ('/startup',           [GameServerController::class, 'getStartupConfig']);
                Route::get ('/usage',             [GameServerController::class, 'getServiceUsage']);
                Route::post('/power',             [GameServerController::class, 'power']);
                Route::get ('/websocket',         [GameServerController::class, 'websocket']);
                Route::post('/command',           [GameServerController::class, 'command']);
                Route::get ('/software-options',  [GameServerController::class, 'softwareOptions']);
                Route::get ('/configuration',     [GameServerController::class, 'configuration']);
                Route::patch('/software',         [GameServerController::class, 'updateSoftware']);
                Route::patch('/server-properties',[GameServerController::class, 'updateServerProperties']);
                Route::post('/restart-required',  [GameServerController::class, 'markRestartRequired']);
                // EULA — solo Minecraft Java Edition
                Route::get ('/eula',              [GameServerController::class, 'eulaStatus']);
                Route::post('/eula/accept',       [GameServerController::class, 'acceptEula']);
                // Java version management
                Route::post('/fix-java',          [GameServerController::class, 'fixJavaVersion']);
                Route::get ('/java-check',        [GameServerController::class, 'checkJavaCompatibility']);
                Route::post('/java-autofix',      [GameServerController::class, 'autoFixJavaCompatibility']);
                Route::get ('/java-requirements', [GameServerController::class, 'javaRequirements']);
                // Logs & lightweight status
                Route::get ('/logs',             [GameServerController::class, 'getLogs']);
                Route::get ('/status',           [GameServerController::class, 'getStatus']);
                // Ping history (last 24 h, sampled every 5 min by scheduler)
                Route::get ('/pings',            [GameServerController::class, 'getPingHistory']);
                // Ping instantáneo — usar solo al montar o al detectar state=running (no polling)
                Route::get ('/ping-now',         [GameServerController::class, 'pingNow']);
            });
        });
    });

    // ── Payments ──────────────────────────────────────────────────────────────
    // ── Payments ──────────────────────────────────────────────────────────────
    Route::prefix('payments')->group(function () {
        Route::get   ('/methods',       [PaymentController::class, 'getPaymentMethods']);
        Route::post  ('/methods',       [PaymentController::class, 'addPaymentMethod']);
        Route::put   ('/methods/{id}',  [PaymentController::class, 'updatePaymentMethod']);
        Route::delete('/methods/{id}',  [PaymentController::class, 'deletePaymentMethod']);
        Route::post  ('/setup-intent',  [PaymentController::class, 'createSetupIntent']);
        Route::post  ('/process',       [PaymentController::class, 'processPayment']);
        Route::post  ('/intent',        [PaymentController::class, 'createPaymentIntentFromQuote']);
        Route::get   ('/stats',         [PaymentController::class, 'getPaymentStats']);
        Route::get   ('/transactions',  [PaymentController::class, 'getTransactions']);
    });

    // ── Billing (banners / estado de facturación) ─────────────────────────────
    Route::get('billing/banners', [\App\Domains\Platform\Http\Controllers\Client\BillingController::class, 'banners']);

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
        Route::get('/cfdi',                 [InvoiceController::class, 'cfdi']);
        Route::get('/stats',                [InvoiceController::class, 'getStats']);
        Route::get('/{uuid}',               [InvoiceController::class, 'show']);
        Route::get('/{uuid}/receipt',       [InvoiceController::class, 'downloadReceipt']);
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
    // NOTA: El sistema NO compra dominios automáticamente. Los clientes importan
    // dominios que ya poseen (desde cualquier registrador) y ROKE gestiona el DNS
    // vía Cloudflare. La verificación de ownership usa un reto TXT.
    Route::prefix('domains')->group(function () {
        Route::get  ('/',                          [DomainController::class, 'index']);
        Route::post ('/',                          [DomainController::class, 'store']);
        Route::get  ('/stats',                     [DomainController::class, 'getStats']);
        Route::get  ('/{uuid}',                    [DomainController::class, 'show']);
        Route::put  ('/{uuid}',                    [DomainController::class, 'update']);
        Route::post ('/{uuid}/renew',              [DomainController::class, 'renew']);
        Route::post ('/{uuid}/verify-ownership',   [DomainController::class, 'initOwnershipVerification']);
        Route::post ('/{uuid}/confirm-ownership',  [DomainController::class, 'confirmOwnershipVerification']);
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

    // ── Infrastructure / Network Topology ────────────────────────────────────
    Route::get('/infrastructure', [InfrastructureController::class, 'index']);

    // ── Support Chat ──────────────────────────────────────────────────────────
    Route::prefix('chat')->name('client.chat.')->group(function () {
        Route::get ('support-room',             [SupportChatController::class, 'getSupportRoom'])->name('support-room');
        Route::get ('unread-count',             [SupportChatController::class, 'getUnreadCount'])->name('unread-count');
        Route::get ('history',                  [SupportChatController::class, 'getHistory'])->name('history');
        Route::get ('/{ticket}/messages',       [SupportChatController::class, 'getMessages'])->name('messages');
        Route::post('/{ticket}/messages',       [SupportChatController::class, 'sendMessage'])->name('send-message');
        Route::post('/{ticket}/typing',         [SupportChatController::class, 'typing'])->name('typing');
        Route::put ('/{ticket}/read',           [SupportChatController::class, 'markAsRead'])->name('mark-as-read');
        Route::put ('/{ticket}/close',          [SupportChatController::class, 'closeRoom'])->name('close');

        // Receipts por reply (WebSocket-driven, idempotentes).
        Route::post('/{ticket}/replies/{reply}/delivered', [SupportChatController::class, 'markReplyDelivered'])->name('reply.delivered');
        Route::post('/{ticket}/replies/{reply}/read',      [SupportChatController::class, 'markReplyRead'])->name('reply.read');
    });
});
