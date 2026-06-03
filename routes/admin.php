
<?php

use Illuminate\Support\Facades\Route;
use App\Domains\Platform\Http\Controllers\Admin\AdminController;
use App\Domains\Platform\Http\Controllers\Admin\AgentController;
use App\Domains\Platform\Http\Controllers\Admin\ProductController;
use App\Domains\Platform\Http\Controllers\Admin\CategoryController;
use App\Domains\Platform\Http\Controllers\Admin\BillingCycleController;
use App\Domains\Platform\Http\Controllers\Admin\ServicePlanController;
use App\Domains\Platform\Http\Controllers\Admin\AddOnController;
use App\Domains\Platform\Http\Controllers\Admin\NotificationController;
use App\Domains\Platform\Http\Controllers\Admin\ChatController;
use App\Domains\Platform\Http\Controllers\Admin\BlogCategoryController;
use App\Domains\Platform\Http\Controllers\Admin\BlogPostController;
use App\Domains\Platform\Http\Controllers\Admin\BlogSubscriptionController;
use App\Domains\Platform\Http\Controllers\Admin\DocumentationController;
use App\Domains\Platform\Http\Controllers\Admin\ApiDocumentationController;
use App\Domains\Platform\Http\Controllers\Admin\SystemStatusController;
use App\Domains\Platform\Http\Controllers\Admin\BackupController;
use App\Domains\Platform\Http\Controllers\Admin\DocumentationRequestController;
use App\Domains\Platform\Http\Controllers\Admin\FiscalController;
use App\Domains\Platform\Http\Controllers\Admin\CfdiController;
use App\Domains\Platform\Http\Controllers\Admin\GameServerController;
use App\Domains\Platform\Http\Controllers\Admin\QuotationController;
use App\Domains\Platform\Http\Controllers\Admin\PterodactylEggController;
use App\Domains\Platform\Http\Controllers\Admin\GlobalSearchController;
use App\Domains\Platform\Http\Controllers\Admin\GameSoftwareVersionController;
use App\Domains\Platform\Http\Controllers\Admin\PetNotificationController;
use App\Domains\Platform\Http\Controllers\Admin\PetSearchController;
use App\Domains\Platform\Http\Controllers\Admin\AdminDomainController;

/*
|--------------------------------------------------------------------------
| Admin Module Routes
|--------------------------------------------------------------------------
|
| Rutas específicas para el módulo de Administrador. Todas estas rutas
| requieren autenticación y el middleware 'admin' para verificar permisos
| de administrador. Incluyen gestión de usuarios, servicios, productos, etc.
|
*/

Route::middleware(["auth:sanctum", "session.timeout", "admin"])->prefix("admin")->group(function () {
    // Global search — popular must be before /search to avoid route collision
    Route::middleware('throttle:search')->group(function () {
        Route::get("/search/popular", [GlobalSearchController::class, "popular"]);
        Route::get("/search",         [GlobalSearchController::class, "search"]);
    });

    // Dashboard routes
    Route::get("/dashboard/stats", [AdminController::class, "getDashboardStats"]);

    // ── Versiones de software de servidores de juego ──────────────────────
    Route::prefix("game-versions")->group(function () {
        Route::get    ('/',              [GameSoftwareVersionController::class, 'index']);
        Route::post   ('/',              [GameSoftwareVersionController::class, 'store']);
        Route::post   ('/bulk/{action}', [GameSoftwareVersionController::class, 'bulk']);
        Route::put    ('/{id}',          [GameSoftwareVersionController::class, 'update']);
        Route::delete ('/{id}',          [GameSoftwareVersionController::class, 'destroy']);
    });

    // ── Catálogo de juegos Pterodactyl ─────────────────────────────────────
    Route::prefix("pterodactyl")->group(function () {
        Route::get   ("/eggs",          [PterodactylEggController::class, "index"]);
        Route::patch ("/eggs/{id}",     [PterodactylEggController::class, "update"]);
        Route::post  ("/eggs/{id}/toggle", [PterodactylEggController::class, "toggle"]);
        Route::post  ("/eggs/sync",     [PterodactylEggController::class, "sync"]);
    });

    // Users management
    Route::get("/users", [AdminController::class, "getUsers"]);
    Route::post("/users", [AdminController::class, "createUser"]);
    Route::put("/users/{id}", [AdminController::class, "updateUser"]);
    Route::delete("/users/{id}", [AdminController::class, "deleteUser"]);
    Route::put("/users/{id}/status", [AdminController::class, "updateUserStatus"]);

    // Services management
    Route::get("/services",                          [AdminController::class, "getServices"]);
    Route::post("/services",                         [AdminController::class, "createService"]);
    // Rutas con segmentos estáticos deben ir ANTES de /{uuid} para evitar colisiones
    Route::get("/services/{uuid}/support-overview",  [AdminController::class, "getServiceSupportOverview"]);
    Route::post("/services/{uuid}/reprovision",      [AdminController::class, "reprovision"]);
    Route::get("/services/{uuid}",                   [AdminController::class, "getService"]);
    Route::put("/services/{uuid}",                   [AdminController::class, "updateService"]);
    Route::delete("/services/{uuid}",                [AdminController::class, "deleteService"]);
    Route::put("/services/{uuid}/status",            [AdminController::class, "updateServiceStatus"]);

    // Invoices management
    Route::get("/invoices/stats", [AdminController::class, "getInvoiceStats"]);
    Route::get("/invoices", [AdminController::class, "getInvoices"]);
    Route::post("/invoices", [AdminController::class, "createInvoice"]);
    Route::put("/invoices/{id}", [AdminController::class, "updateInvoice"]);
    Route::delete("/invoices/{id}", [AdminController::class, "deleteInvoice"]);
    Route::put("/invoices/{id}/status", [AdminController::class, "updateInvoiceStatus"]);
    Route::post("/invoices/{id}/mark-paid", [AdminController::class, "markInvoiceAsPaid"]);
    Route::post("/invoices/{id}/send-reminder", [AdminController::class, "sendInvoiceReminder"]);
    Route::post("/invoices/{id}/cancel", [AdminController::class, "cancelInvoice"]);
    Route::get("/invoices/{uuid}/receipt", [AdminController::class, "downloadReceipt"]);
    Route::get('/invoices/{serviceId}',[AdminController::class, 'getInvoicesByService']);

    // Tickets management
    // NOTE: static routes must be declared BEFORE dynamic routes ({id}) so Laravel doesn't
    // swallow them as an {id} value. The agents prefix group must also be before /{id}.
    Route::get("/tickets/stats",              [AdminController::class, "getTicketStats"]);
    Route::get("/tickets/categories",         [AdminController::class, "getTicketCategories"]);
    Route::get("/support-agents",             [AdminController::class, "getSupportAgents"]);

    // Agents management — prefix must come BEFORE /tickets/{id}
    Route::prefix("tickets/agents")->group(function () {
        Route::get("/",             [AgentController::class, "index"]);
        Route::post("/",            [AgentController::class, "store"]);
        Route::get("/statistics",   [AgentController::class, "statistics"]);
        Route::get("/recommended",  [AgentController::class, "getRecommendedAgent"]);
        Route::get("/{uuid}",       [AgentController::class, "show"]);
        Route::put("/{uuid}",       [AgentController::class, "update"]);
        Route::delete("/{uuid}",    [AgentController::class, "destroy"]);
        Route::post("/{uuid}/assign-ticket", [AgentController::class, "assignTicket"]);
        Route::get("/{uuid}/tickets",        [AgentController::class, "tickets"]);
    });

    Route::get("/tickets",                    [AdminController::class, "getTickets"]);
    Route::post("/tickets",                   [AdminController::class, "createTicket"]);
    Route::get("/tickets/{id}",               [AdminController::class, "showTicket"]);
    Route::put("/tickets/{id}",               [AdminController::class, "updateTicket"]);
    Route::delete("/tickets/{id}",            [AdminController::class, "deleteTicket"]);
    Route::put("/tickets/{id}/status",        [AdminController::class, "updateTicketStatus"]);
    Route::put("/tickets/{id}/priority",      [AdminController::class, "updateTicketPriority"]);
    Route::post("/tickets/{id}/assign",       [AdminController::class, "assignTicket"]);
    Route::post("/tickets/{id}/reply",        [AdminController::class, "addTicketReply"]);

    // Products management
    Route::prefix("products")->group(function () {
        Route::post("/", [ProductController::class, "store"]);
        Route::put("/{uuid}", [ProductController::class, "update"]);
        Route::delete("/{uuid}", [ProductController::class, "destroy"]);
    });

    // Add-ons management
    Route::prefix('add-ons')->group(function () {
        Route::get('/', [AddOnController::class, 'index']);   // lista con filtros
        Route::post('/', [AddOnController::class, 'store']);   // crear
        Route::get('/{uuid}', [AddOnController::class, 'show']);    // detalle (opcional)
        Route::put('/{uuid}', [AddOnController::class, 'update']);  // actualizar
        Route::delete('/{uuid}', [AddOnController::class, 'destroy']); // eliminar

        // relaciones con planes
        Route::post('/{uuid}/attach-to-plan', [AddOnController::class, 'attachToPlan']);
        Route::post('/{uuid}/detach-from-plan', [AddOnController::class, 'detachFromPlan']);
    });

    // Categories management
    Route::prefix("categories")->group(function () {
        Route::get("/", [CategoryController::class, "index"]);
        Route::post("/", [CategoryController::class, "store"]);
        Route::put("/{uuid}", [CategoryController::class, "update"]);
        Route::delete("/{uuid}", [CategoryController::class, "destroy"]);
    });

    // Billing cycles management
    Route::prefix("billing-cycles")->group(function () {
        Route::get("/", [BillingCycleController::class, "index"]);
        Route::post("/", [BillingCycleController::class, "store"]);
        Route::put("/{uuid}", [BillingCycleController::class, "update"]);
        Route::delete("/{uuid}", [BillingCycleController::class, "destroy"]);
    });

    // Service plans management
    Route::prefix("service-plans")->group(function () {
        Route::get("/", [ServicePlanController::class, "index"]);
        Route::post("/", [ServicePlanController::class, "store"]);
        Route::post("/bulk/{action}", [ServicePlanController::class, "bulk"]);
        Route::get("/{uuid}", [ServicePlanController::class, "show"]);
        Route::put("/{uuid}", [ServicePlanController::class, "update"]);
        Route::delete("/{uuid}", [ServicePlanController::class, "destroy"]);
    });

    // Rutas de Notificaciones para Admin
    Route::prefix('notifications')->name('admin.notifications.')->group(function () {
        Route::get('/dashboard', [NotificationController::class, 'dashboard'])->name('dashboard');
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('/stats', [NotificationController::class, 'getStats'])->name('stats');
        Route::post('/broadcast', [NotificationController::class, 'broadcastToUsers'])->name('broadcast');
        Route::post('/send-to-user/{user}', [NotificationController::class, 'sendToUser'])->name('send-to-user');
        Route::put('/mark-all-read', [NotificationController::class, 'markAllAsRead'])->name('mark-all-read');
        Route::put('/archive-all-read', [NotificationController::class, 'archiveAllRead'])->name('archive-all-read');
        Route::delete('/archived', [NotificationController::class, 'destroyAllArchived'])->name('destroy-all-archived');
        Route::put('/{notification}/read', [NotificationController::class, 'markAsRead'])->name('mark-as-read');
        Route::put('/{notification}/archive', [NotificationController::class, 'archive'])->name('archive');
        Route::put('/{notification}/unarchive', [NotificationController::class, 'unarchive'])->name('unarchive');
        Route::delete('/{notification}', [NotificationController::class, 'destroy'])->name('destroy');
    });

    // Rutas de Chat para Admin
    Route::prefix('chat')->name('admin.chat.')->group(function () {
        Route::get('/active-rooms', [ChatController::class, 'getActiveRooms'])->name('active-rooms');
        Route::get('/all-rooms', [ChatController::class, 'getAllRooms'])->name('all-rooms');
        Route::get('/stats', [ChatController::class, 'getStats'])->name('stats');
        Route::get('/unread-count', [ChatController::class, 'getUnreadCount'])->name('unread-count');
        Route::get('/{ticket}/messages', [ChatController::class, 'getMessages'])->name('messages');
        Route::post('/{ticket}/messages', [ChatController::class, 'sendMessage'])->name('send-message');
        Route::post('/{ticket}/typing', [ChatController::class, 'typing'])->name('typing');
        Route::put('/{ticket}/read', [ChatController::class, 'markAsRead'])->name('mark-as-read');
        Route::put('/{ticket}/assign', [ChatController::class, 'assignToAgent'])->name('assign');
        Route::put('/{ticket}/close', [ChatController::class, 'closeRoom'])->name('close');
        Route::put('/{ticket}/reopen', [ChatController::class, 'reopenRoom'])->name('reopen');
    });

    // Rutas de Backups / Respaldos (NAS)
    Route::prefix('backups')->name('admin.backups.')->group(function () {
        Route::get('/',        [BackupController::class, 'index'])->name('index');
        Route::get('/stats',   [BackupController::class, 'stats'])->name('stats');
        Route::post('/',       [BackupController::class, 'store'])->name('store');
        Route::post('/bulk-delete', [BackupController::class, 'bulkDestroy'])->name('bulk-delete');
        Route::post('/scan-nas',   [BackupController::class, 'scanNas'])->name('scan-nas');

        // Programaciones (antes de {backup} para no chocar con el binding)
        Route::get('/schedules',  [BackupController::class, 'schedules'])->name('schedules.index');
        Route::post('/schedules', [BackupController::class, 'storeSchedule'])->name('schedules.store');
        Route::put('/schedules/{schedule}', [BackupController::class, 'updateSchedule'])->name('schedules.update');
        Route::delete('/schedules/{schedule}', [BackupController::class, 'destroySchedule'])->name('schedules.destroy');
        Route::post('/schedules/{schedule}/run', [BackupController::class, 'runSchedule'])->name('schedules.run');

        Route::get('/{backup}/download', [BackupController::class, 'download'])->name('download');
        Route::delete('/{backup}',       [BackupController::class, 'destroy'])->name('destroy');
    });

    // Blog Categories Routes
    Route::apiResource('blog-categories', BlogCategoryController::class);

        // Blog Subscriptions Routes
    Route::prefix("blog-subscriptions")->group(function () {
        Route::get("/", [BlogSubscriptionController::class, "index"]);
        Route::get("/{uuid}", [BlogSubscriptionController::class, "show"]);
        Route::delete("/{uuid}", [BlogSubscriptionController::class, "destroy"]);
    });

    // Documentation Routes
    Route::prefix("documentation")->group(function () {
        Route::get("/", [DocumentationController::class, "index"]);
        Route::post("/", [DocumentationController::class, "store"]);
        Route::get("/{uuid}", [DocumentationController::class, "show"]);
        Route::put("/{uuid}", [DocumentationController::class, "update"]);
        Route::delete("/{uuid}", [DocumentationController::class, "destroy"]);
    });

    // API Documentation Routes
    Route::prefix("api-documentation")->group(function () {
        Route::get("/", [ApiDocumentationController::class, "index"]);
        Route::post("/", [ApiDocumentationController::class, "store"]);
        Route::get("/{uuid}", [ApiDocumentationController::class, "show"]);
        Route::put("/{uuid}", [ApiDocumentationController::class, "update"]);
        Route::delete("/{uuid}", [ApiDocumentationController::class, "destroy"]);
    });

    // System Status Routes
    Route::prefix("system-status")->group(function () {
        Route::get("/", [SystemStatusController::class, "index"]);
        Route::post("/", [SystemStatusController::class, "store"]);
        Route::get("/{uuid}", [SystemStatusController::class, "show"]);
        Route::put("/{uuid}", [SystemStatusController::class, "update"]);
        Route::delete("/{uuid}", [SystemStatusController::class, "destroy"]);
    });

    // Blog Posts Routes
    Route::post("blog/upload-image", [BlogPostController::class, "uploadImage"]);
    Route::apiResource("blog-posts", BlogPostController::class);

    // ── Cotizaciones ─────────────────────────────────────────────────────────
    Route::prefix('quotations')->group(function () {
        Route::get('/',    [QuotationController::class, 'index']);
        Route::post('/',   [QuotationController::class, 'store']);

        Route::prefix('/{quotation}')->group(function () {
            Route::get('/',                [QuotationController::class, 'show']);
            Route::put('/',                [QuotationController::class, 'update']);
            Route::delete('/',             [QuotationController::class, 'destroy']);

            // Status transitions
            Route::post('/send',           [QuotationController::class, 'send']);
            Route::post('/accept',         [QuotationController::class, 'accept']);
            Route::post('/reject',         [QuotationController::class, 'reject']);
            Route::post('/reopen',         [QuotationController::class, 'reopen']);

            // Versioning
            Route::post('/revision',       [QuotationController::class, 'createRevision']);

            // Link management
            Route::post('/regenerate-link', [QuotationController::class, 'regenerateLink']);
        });
    });

    // ── Servidores de Juego (Pterodactyl) ────────────────────────────────────
    Route::prefix('game-servers')->group(function () {
        Route::get('/',                          [GameServerController::class, 'index']);
        Route::get('/{uuid}',                      [GameServerController::class, 'show']);
        // Acciones de administración
        Route::post('/{id}/provision',           [GameServerController::class, 'provision']);
        Route::post('/{id}/suspend',             [GameServerController::class, 'suspend']);
        Route::post('/{id}/unsuspend',           [GameServerController::class, 'unsuspend']);
        Route::post('/{id}/reinstall',           [GameServerController::class, 'reinstall']);
        Route::delete('/{id}',                   [GameServerController::class, 'terminate']);
        // Consola y runtime (admin bypass)
        Route::get('/{id}/websocket',            [GameServerController::class, 'websocket']);
        Route::get('/{id}/usage',                [GameServerController::class, 'usage']);
        Route::post('/{id}/power',               [GameServerController::class, 'power']);
        Route::post('/{id}/command',             [GameServerController::class, 'command']);
        // Gestor de archivos (admin bypass)
        Route::get('/{id}/files/list',           [GameServerController::class, 'listFiles']);
        Route::get('/{id}/files/upload',         [GameServerController::class, 'uploadUrl']);
        Route::post('/{id}/files/delete',        [GameServerController::class, 'deleteFiles']);
        Route::get('/{id}/files/download',       [GameServerController::class, 'downloadUrl']);
    });

    // ── Gestión de dominios (admin) ───────────────────────────────────────────
    Route::get('/domains',                   [AdminDomainController::class, 'index']);
    Route::post('/domains/{uuid}/renew',     [AdminDomainController::class, 'renew']);

    // ── Gestión de CFDIs ──────────────────────────────────────────────────────
    Route::prefix('cfdi')->group(function () {
        Route::get('/',                            [CfdiController::class, 'index']);
        Route::get('/stats',                       [CfdiController::class, 'stats']);
        Route::get('/{id}',                        [CfdiController::class, 'show']);
        Route::post('/{id}/retry',                 [CfdiController::class, 'retry']);
        Route::post('/{id}/cancel',                [CfdiController::class, 'cancel']);
        Route::get('/{id}/download/{format}',      [CfdiController::class, 'download']);
    });

    // ── Fiscal / CFDI ─────────────────────────────────────────────────────────
    Route::prefix('fiscal')->group(function () {
        // Catálogos SAT
        Route::get('/regimes',                     [FiscalController::class, 'regimes']);
        Route::put('/regimes/{code}/toggle',       [FiscalController::class, 'toggleRegime']);
        Route::get('/cfdi-uses',                   [FiscalController::class, 'cfdiUses']);
        Route::put('/cfdi-uses/{code}/toggle',     [FiscalController::class, 'toggleCfdiUse']);

        // Perfiles fiscales de clientes (solo lectura)
        Route::get('/profiles',                    [FiscalController::class, 'profiles']);
        Route::get('/profiles/{uuid}',             [FiscalController::class, 'showProfile']);
    });

    // ── ROKE Pet — Platform Admin Routes ─────────────────────────────────────
    Route::prefix('pet')->group(function () {
        // notifications/stats must be declared BEFORE notifications/{id} to avoid route collision
        Route::get('/notifications/stats', [PetNotificationController::class, 'stats']);
        Route::get('/notifications',       [PetNotificationController::class, 'index']);
        Route::get('/search/popular',      [PetSearchController::class, 'popular']);
        Route::get('/search',              [PetSearchController::class, 'search']);
    });

    // Documentation Requests Routes
    Route::prefix("documentation-requests")->group(function () {
        Route::get("/", [DocumentationRequestController::class, "index"]);
        Route::get("/{id}", [DocumentationRequestController::class, "show"]);
        Route::put("/{id}/mark-resolved", [DocumentationRequestController::class, "markResolved"]);
        Route::delete("/{id}", [DocumentationRequestController::class, "destroy"]);
    });
});
