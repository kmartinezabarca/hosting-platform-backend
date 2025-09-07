
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AgentController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\BillingCycleController;
use App\Http\Controllers\Admin\ServicePlanController;
use App\Http\Controllers\Admin\AddOnController;
use App\Http\Controllers\Client\InvoiceController;

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

Route::middleware(["auth", "admin"])->prefix("admin")->group(function () {
    // Dashboard routes
    Route::get("/dashboard/stats", [AdminController::class, "getDashboardStats"]);

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

    // Additional invoice routes
    Route::prefix("invoices")->group(function () {
        Route::post("/", [InvoiceController::class, "store"]);
        Route::put("/{uuid}/status", [InvoiceController::class, "updateStatus"]);
    });

    // Tickets management
    Route::get("/tickets", [AdminController::class, "getTickets"]);
    Route::put("/tickets/{id}/status", [AdminController::class, "updateTicketStatus"]);
    Route::put("/tickets/{id}/priority", [AdminController::class, "updateTicketPriority"]);
    Route::post("/tickets/{id}/assign", [AdminController::class, "assignTicket"]);
    Route::post("/tickets/{id}/reply", [AdminController::class, "addTicketReply"]);
    Route::get("/tickets/categories", [AdminController::class, "getTicketCategories"]);
    Route::get("/support-agents", [AdminController::class, "getSupportAgents"]);

    // Agents management - API completa para agentes
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
        Route::put("/{uuid}", [ServicePlanController::class, "update"]);
        Route::delete("/{uuid}", [ServicePlanController::class, "destroy"]);
    });
});

