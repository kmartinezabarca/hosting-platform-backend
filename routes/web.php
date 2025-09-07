<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

// --- Importa todos los controladores que usarás aquí ---
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\GoogleLoginController;
use App\Http\Controllers\ProductController as AdminProductController;
use App\Http\Controllers\CategoryController as AdminCategoryController;
use App\Http\Controllers\BillingCycleController as AdminBillingCycleController;
use App\Http\Controllers\ServicePlanController as AdminServicePlanController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\AdminAddOnController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Aquí van las rutas que requieren una sesión de usuario activa (STATEFUL).
| Ideal para SPAs que se autentican con cookies.
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
    // --- RUTAS DE AUTENTICACIÓN (Públicas, pero necesitan sesión) ---
    Route::post("auth/register", [AuthController::class, "register"]);
    Route::post("auth/login", [AuthController::class, "login"]);
    Route::post("auth/google/callback", [
        GoogleLoginController::class,
        "handleGoogleCallback",
    ]);
    Route::post("auth/2fa/verify", [TwoFactorController::class, "verifyLogin"]);

    // --- RUTAS PROTEGIDAS (Requieren que la sesión ya esté iniciada) ---
    Route::middleware("auth")->group(function () {
        // Rutas de autenticación que requieren sesión
        Route::post("auth/logout", [AuthController::class, "logout"]);
        Route::get("/auth/me", [AuthController::class, "me"]);
        Route::get("/user", function (Request $request) { // Esta es la misma que /auth/me
            return $request->user();
        });

        // Dashboard routes
        Route::get("/dashboard/stats", [DashboardController::class, "getStats"]);
        Route::get("/dashboard/services", [
            DashboardController::class,
            "getServices",
        ]);
        Route::get("/dashboard/activity", [
            DashboardController::class,
            "getActivity",
        ]);

        // Profile management
        Route::prefix("profile")->group(function () {
            Route::get("/", [ProfileController::class, "getProfile"]);
            Route::put("/", [ProfileController::class, "updateProfile"]);
            Route::post("/avatar", [ProfileController::class, "updateAvatar"]);
            Route::put("/email", [ProfileController::class, "updateEmail"]);
            Route::put("/password", [ProfileController::class, "updatePassword"]);
            Route::get("/devices", [ProfileController::class, "getSessions"]);
            Route::get("/security", [
                ProfileController::class,
                "getSecurityOverview",
            ]);
            Route::delete("/account", [ProfileController::class, "deleteAccount"]);
            Route::delete("/sessions/{uuid}", [
                ProfileController::class,
                "revokeSession",
            ]);
            Route::post('/devices/revoke-others', [ProfileController::class, 'revokeOtherSessions']);
        });

        // Two-Factor Authentication (gestión)
        Route::prefix("2fa")->group(function () {
            Route::get("/status", [TwoFactorController::class, "getStatus"]);
            Route::post("/generate", [TwoFactorController::class, "generateSecret"]);
            Route::post("/enable", [TwoFactorController::class, "enable"]);
            Route::post("/disable", [TwoFactorController::class, "disable"]);
            Route::post("/verify", [TwoFactorController::class, "verify"]);
        });

        // Services management
        Route::prefix("services")->group(function () {
            Route::get("/plans", [ServiceController::class, "getServicePlans"]);
            Route::post("/contract", [
                ServiceController::class,
                "contractService",
            ]);
            Route::get("/user", [ServiceController::class, "getUserServices"]);
            Route::get("/{uuid}", [
                ServiceController::class,
                "getServiceDetails",
            ]);
            Route::get('/{uuid}/invoices', [ServiceController::class, 'getServiceInvoices']);
            Route::patch('/{uuid}/configuration', [ServiceController::class, 'updateConfiguration']);
            Route::put("/{serviceId}/config", [
                ServiceController::class,
                "updateServiceConfig",
            ]);
            Route::post("/{serviceId}/cancel", [
                ServiceController::class,
                "cancelService",
            ]);
            Route::post("/{serviceId}/suspend", [
                ServiceController::class,
                "suspendService",
            ]);
            Route::post("/{serviceId}/reactivate", [
                ServiceController::class,
                "reactivateService",
            ]);
            Route::get("/{serviceId}/usage", [
                ServiceController::class,
                "getServiceUsage",
            ]);
            Route::get("/{serviceId}/backups", [
                ServiceController::class,
                "getServiceBackups",
            ]);
            Route::post("/{serviceId}/backups", [
                ServiceController::class,
                "createServiceBackup",
            ]);
            Route::post("/{serviceId}/backups/{backupId}/restore", [
                ServiceController::class,
                "restoreServiceBackup",
            ]);
        });

        // Payment routes
        Route::prefix("payments")->group(function () {
            Route::get("/methods", [PaymentController::class, "getPaymentMethods"]);
            Route::post("/methods", [PaymentController::class, "addPaymentMethod"]);
            Route::put("/methods/{id}", [
                PaymentController::class,
                "updatePaymentMethod",
            ]);
            Route::delete("/methods/{id}", [
                PaymentController::class,
                "deletePaymentMethod",
            ]);
            Route::post("/setup-intent", [
                PaymentController::class,
                "createSetupIntent",
            ]);
            Route::post("/process", [PaymentController::class, "processPayment"]);
            Route::post("/intent", [PaymentController::class, "createSetupIntent"]);
            Route::get("/stats", [PaymentController::class, "getPaymentStats"]);
            Route::get("/transactions", [
                PaymentController::class,
                "getTransactions",
            ]);
        });

        // Subscriptions management
        Route::prefix("subscriptions")->group(function () {
            Route::get("/", [
                SubscriptionController::class,
                "getUserSubscriptions",
            ]);
            Route::post("/", [
                SubscriptionController::class,
                "createSubscription",
            ]);
            Route::get("/{subscriptionId}", [
                SubscriptionController::class,
                "getSubscriptionDetails",
            ]);
            Route::post("/{subscriptionId}/cancel", [
                SubscriptionController::class,
                "cancelSubscription",
            ]);
            Route::post("/{subscriptionId}/resume", [
                SubscriptionController::class,
                "resumeSubscription",
            ]);
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
            Route::post("/check-availability", [
                DomainController::class,
                "checkAvailability",
            ]);
            Route::get("/{uuid}", [DomainController::class, "show"]);
            Route::put("/{uuid}", [DomainController::class, "update"]);
            Route::post("/{uuid}/renew", [DomainController::class, "renew"]);
        });

        // --- RUTAS DE ADMINISTRADOR (Protegidas por middleware 'admin') ---
        Route::middleware("admin")->prefix("admin")->group(function () {
            // Dashboard routes
            Route::get("/dashboard/stats", [
                AdminController::class,
                "getDashboardStats",
            ]);

            // Users management
            Route::get("/users", [AdminController::class, "getUsers"]);
            Route::post("/users", [AdminController::class, "createUser"]);
            Route::put("/users/{id}", [AdminController::class, "updateUser"]);
            Route::delete("/users/{id}", [AdminController::class, "deleteUser"]);
            Route::put("/users/{id}/status", [AdminController::class, "updateUserStatus"]);

            // Services management
            Route::get("/services", [AdminController::class, "getServices"]);
            Route::put("/services/{id}/status", [AdminController::class, "updateServiceStatus"]);

            // Invoices management
            Route::get("/invoices", [AdminController::class, "getInvoices"]);
            Route::post("/invoices", [AdminController::class, "createInvoice"]);
            Route::put("/invoices/{id}", [AdminController::class, "updateInvoice"]);
            Route::delete("/invoices/{id}", [AdminController::class, "deleteInvoice"]);
            Route::put("/invoices/{id}/status", [AdminController::class, "updateInvoiceStatus"]);
            Route::post("/invoices/{id}/mark-paid", [AdminController::class, "markInvoiceAsPaid"]);
            Route::post("/invoices/{id}/send-reminder", [AdminController::class, "sendInvoiceReminder"]);
            Route::post("/invoices/{id}/cancel", [AdminController::class, "cancelInvoice"]);

            // Tickets management
            Route::get("/tickets", [AdminController::class, "getTickets"]);
            Route::put("/tickets/{id}/status", [AdminController::class, "updateTicketStatus"]);
            Route::put("/tickets/{id}/priority", [AdminController::class, "updateTicketPriority"]);
            Route::post("/tickets/{id}/assign", [AdminController::class, "assignTicket"]);
            Route::post("/tickets/{id}/reply", [AdminController::class, "addTicketReply"]);
            Route::get("/tickets/categories", [AdminController::class, "getTicketCategories"]);
            Route::get("/support-agents", [AdminController::class, "getSupportAgents"]);

            // Agents management - Nueva API completa para agentes
            Route::prefix("tickets/agents")->group(function () {
                Route::get("/", [AgentController::class, "index"]); // Listar agentes con filtros
                Route::post("/", [AgentController::class, "store"]); // Crear nuevo agente
                Route::get("/statistics", [AgentController::class, "statistics"]); // Estadísticas de agentes
                Route::get("/recommended", [AgentController::class, "getRecommendedAgent"]); // Agente recomendado para asignación
                Route::get("/{uuid}", [AgentController::class, "show"]); // Mostrar agente específico
                Route::put("/{uuid}", [AgentController::class, "update"]); // Actualizar agente
                Route::delete("/{uuid}", [AgentController::class, "destroy"]); // Eliminar agente
                Route::post("/{uuid}/assign-ticket", [AgentController::class, "assignTicket"]); // Asignar ticket a agente
                Route::get("/{uuid}/tickets", [AgentController::class, "tickets"]); // Tickets del agente
            });

            Route::prefix("products")->group(function () {
                Route::post("/", [AdminProductController::class, "store"]);
                Route::put("/{uuid}", [AdminProductController::class, "update"]);
                Route::delete("/{uuid}", [
                    AdminProductController::class,
                    "destroy",
                ]);
            });

            Route::prefix('add-ons')->group(function () {
                Route::get('/',               [AdminAddOnController::class, 'index']);   // lista con filtros
                Route::post('/',              [AdminAddOnController::class, 'store']);   // crear
                Route::get('/{uuid}',         [AdminAddOnController::class, 'show']);    // detalle (opcional)
                Route::put('/{uuid}',         [AdminAddOnController::class, 'update']);  // actualizar
                Route::delete('/{uuid}',      [AdminAddOnController::class, 'destroy']); // eliminar

                // relaciones con planes
                Route::post('/{uuid}/attach-to-plan',  [AdminAddOnController::class, 'attachToPlan']);
                Route::post('/{uuid}/detach-from-plan', [AdminAddOnController::class, 'detachFromPlan']);
            });

            Route::prefix("invoices")->group(function () {
                Route::post("/", [InvoiceController::class, "store"]);
                Route::put("/{uuid}/status", [
                    InvoiceController::class,
                    "updateStatus",
                ]);
            });

            Route::prefix("categories")->group(function () {
                Route::get("/", [AdminCategoryController::class, "index"]);
                Route::post("/", [AdminCategoryController::class, "store"]);
                Route::put("/{uuid}", [AdminCategoryController::class, "update"]);
                Route::delete("/{uuid}", [
                    AdminCategoryController::class,
                    "destroy",
                ]);
            });

            Route::prefix("billing-cycles")->group(function () {
                Route::get("/", [AdminBillingCycleController::class, "index"]);
                Route::post("/", [AdminBillingCycleController::class, "store"]);
                Route::put("/{uuid}", [
                    AdminBillingCycleController::class,
                    "update",
                ]);
                Route::delete("/{uuid}", [
                    AdminBillingCycleController::class,
                    "destroy",
                ]);
            });

            Route::prefix("service-plans")->group(function () {
                Route::get("/", [AdminServicePlanController::class, "index"]);
                Route::get("/categories", [AdminCategoryController::class, "index"]);
                Route::post("/", [AdminServicePlanController::class, "store"]);
                Route::put("/{uuid}", [
                    AdminServicePlanController::class,
                    "update",
                ]);
                Route::delete("/{uuid}", [
                    AdminServicePlanController::class,
                    "destroy",
                ]);
            });
        });
    });
});

// Capturar cualquier otra ruta y devolver respuesta JSON profesional
Route::fallback(function () {
    return response()->json([
        "error" => "Recurso no encontrado",
        "message" => "El recurso solicitado no existe o no está disponible para este tipo de acceso.",
        "status_code" => 404
    ], 404, [
        "Content-Type" => "application/json"
    ]);
});




            Route::get("/services/{id}", [AdminController::class, "getService"]);



            // Add-ons management
            Route::prefix("add-ons")->group(function () {
                Route::get("/", [AddOnController::class, "index"]);
                Route::post("/", [AddOnController::class, "store"]);
                Route::get("/{uuid}", [AddOnController::class, "show"]);
                Route::put("/{uuid}", [AddOnController::class, "update"]);
                Route::delete("/{uuid}", [AddOnController::class, "destroy"]);
                Route::post("/{uuid}/attach-to-plan", [AddOnController::class, "attachToPlan"]);
                Route::post("/{uuid}/detach-from-plan", [AddOnController::class, "detachFromPlan"]);
            });



use App\Http\Controllers\AddOnController;

