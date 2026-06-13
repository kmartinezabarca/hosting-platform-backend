<?php

use App\Domains\Platform\Http\Controllers\Api\CheckoutController;
use App\Domains\Platform\Http\Controllers\Api\ContactController;
use App\Domains\Platform\Http\Controllers\Api\DocumentationRequestController;
use App\Domains\Platform\Http\Controllers\Api\NewsletterSubscriptionController;
use App\Domains\Platform\Http\Controllers\Api\QuotationPublicController;
use App\Domains\Platform\Http\Controllers\Api\SoftwareController;
use App\Domains\Platform\Http\Controllers\Client\ApiDocsController;
use App\Domains\Platform\Http\Controllers\Client\ApiDocumentationController;
use App\Domains\Platform\Http\Controllers\Client\BillingCycleController;
use App\Domains\Platform\Http\Controllers\Client\BlogController;
use App\Domains\Platform\Http\Controllers\Client\BlogCommentController;
use App\Domains\Platform\Http\Controllers\Client\BlogSubscriptionController;
use App\Domains\Platform\Http\Controllers\Client\CategoryController;
use App\Domains\Platform\Http\Controllers\Client\DocumentationController;
use App\Domains\Platform\Http\Controllers\Client\MarketingServiceController;
use App\Domains\Platform\Http\Controllers\Client\ServicePlanController;
use App\Domains\Platform\Http\Controllers\Client\SystemStatusController;
use App\Domains\Platform\Http\Controllers\Client\GameEggController;
use App\Domains\Platform\Http\Controllers\Client\HostingController;
use App\Domains\Platform\Http\Controllers\Common\StripeWebhookController;
use App\Domains\Platform\Http\Controllers\Api\N8nIntegrationController;
use App\Http\Controllers\AppVersionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application.
|
*/

// Auth routes are defined in routes/auth.php (loaded from RouteServiceProvider).

// ── App version — public, no auth required ────────────────────────────────────
Route::get('/app/version', [AppVersionController::class, 'show']);

// Checkout autoritativo
Route::get('/checkout/catalog', [CheckoutController::class, 'catalog']);
Route::middleware(['auth:sanctum', 'session.timeout'])->group(function () {
    Route::post('/checkout/quote', [CheckoutController::class, 'quote']);
});

// Stripe webhook (no authentication required)
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handleWebhook']);

// ── Integración n8n (soporte / WhatsApp) — auth por token compartido ──────────
Route::prefix('integrations/n8n')
    ->middleware(['verify.n8n', 'throttle:120,1'])
    ->group(function () {
        Route::get('/health',              [N8nIntegrationController::class, 'health']);
        Route::get('/knowledge',           [N8nIntegrationController::class, 'knowledge']);
        Route::post('/whatsapp/inbound',   [N8nIntegrationController::class, 'inbound']);
        Route::post('/whatsapp/reply',     [N8nIntegrationController::class, 'reply']);
        Route::post('/whatsapp/handoff',   [N8nIntegrationController::class, 'handoff']);
    });

// Catálogo de juegos disponibles — público (el usuario lo ve antes de pagar)
Route::prefix('game-eggs')->group(function () {
    Route::get('/',     [GameEggController::class, 'index']);   // ?plan_uuid=xxx
    Route::get('/{id}', [GameEggController::class, 'show']);
});

// Swagger/OpenAPI Documentation
Route::get('/docs', [ApiDocsController::class, 'json'])->name('api.docs.json');
Route::get('/swagger', [ApiDocsController::class, 'ui'])->name('api.docs.ui');

// Categories, Billing Cycles, and Service Plans
Route::prefix('categories')->group(function () {
    Route::get('/', [CategoryController::class, 'index']);
    Route::get('/with-plans', [CategoryController::class, 'withPlans']);
    Route::get('/slug/{slug}', [CategoryController::class, 'getBySlug']);
});

Route::prefix('billing-cycles')->group(function () {
    Route::get('/', [BillingCycleController::class, 'index']);
    Route::get('/{uuid}', [BillingCycleController::class, 'show']);
});

Route::prefix('service-plans')->group(function () {
    Route::get('/', [ServicePlanController::class, 'index']);
    Route::get('/add-ons/{AddSlug}', [ServicePlanController::class, 'listAddOns']);
    Route::get('/category/{categorySlug}', [ServicePlanController::class, 'getByCategorySlug']);
    Route::get('/{uuid}', [ServicePlanController::class, 'show']);
});

// Marketing Services
Route::get('/marketing-services', [MarketingServiceController::class, 'index']);

Route::middleware('throttle:10,1')->group(function () {
    Route::post('/contact', [ContactController::class, 'store']);
    Route::post('/newsletter/subscribe', [NewsletterSubscriptionController::class, 'subscribe']);
});

// Blog
Route::prefix('blog')->group(function () {
    Route::get('/posts', [BlogController::class, 'index']);
    Route::get('/posts/featured', [BlogController::class, 'featuredPosts']);
    Route::get('/posts/{slug}', [BlogController::class, 'show']);
    Route::get('/categories', [BlogController::class, 'categories']);
    Route::get('/categories/{categorySlug}/posts', [BlogController::class, 'postsByCategory']);

    // Comentarios (lectura pública de los aprobados)
    Route::get('/posts/{slug}/comments', [BlogCommentController::class, 'index']);

    // Acciones con anti-spam / abuso (throttle)
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('/posts/{slug}/like', [BlogController::class, 'like']);
        Route::post('/posts/{slug}/unlike', [BlogController::class, 'unlike']);
    });

    Route::middleware('throttle:5,1')->group(function () {
        Route::post('/posts/{slug}/comments', [BlogCommentController::class, 'store']);
    });
});

// Suscripción al blog — throttle anti-spam (endpoint público que escribe en BD
// y dispara correo de confirmación).
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/blog/subscribe', [BlogSubscriptionController::class, 'subscribe']);
    Route::post('/blog/unsubscribe/{uuid}', [BlogSubscriptionController::class, 'unsubscribe']);
});

// Documentation
Route::prefix('documentation')->group(function () {
    Route::get('/', [DocumentationController::class, 'index']);
    Route::get('/{slug}', [DocumentationController::class, 'show']);
});

Route::prefix('api-documentation')->group(function () {
    Route::get('/', [ApiDocumentationController::class, 'index']);
    Route::get('/{slug}', [ApiDocumentationController::class, 'show']);
});

Route::prefix('system-status')->group(function () {
    Route::get('/', [SystemStatusController::class, 'index']);
});

Route::middleware('throttle:5,1')->group(function () {
    Route::post('/documentation-requests', [DocumentationRequestController::class, 'store']);
});

// Versiones de software de servidores de Minecraft (Pterodactyl Eggs)
Route::get('/software/{identifier}/versions', [SoftwareController::class, 'getVersions']);

// Cotizaciones públicas (sin autenticación)
Route::prefix('quotations/public')->group(function () {
    Route::get('/{token}',         [QuotationPublicController::class, 'show']);
    Route::post('/{token}/viewed', [QuotationPublicController::class, 'markViewed']);
});

// Postal Codes API
Route::get('/postal-codes/{code}', [App\Domains\Platform\Http\Controllers\Api\PostalCodeController::class, 'search']);

// Hosting — cliente autenticado
Route::middleware(['auth:sanctum', 'session.timeout'])->prefix('hosting')->group(function () {
    Route::get('/{uuid}/info',       [HostingController::class, 'info']);
    Route::get('/{uuid}/files',      [HostingController::class, 'files']);
    Route::get('/{uuid}/databases',  [HostingController::class, 'databases']);
    Route::post('/{uuid}/databases', [HostingController::class, 'createDatabase']);
    Route::delete('/{uuid}/databases/{db}', [HostingController::class, 'deleteDatabase']);
    // Gestor de base de datos NATIVO (dentro del portal de ROKE)
    Route::get ('/{uuid}/db/tables', [HostingController::class, 'dbTables']);
    Route::get ('/{uuid}/db/rows',   [HostingController::class, 'dbRows']);
    Route::post('/{uuid}/db/query',  [HostingController::class, 'dbQuery']);
    // NOTE: Email hosting (Mailcow) has been removed. ROKE guides clients to
    // Google Workspace / Microsoft 365 / Zoho Mail via the DNS records panel.
    Route::get ('/{uuid}/wordpress',          [HostingController::class, 'wordpress']);
    Route::post('/{uuid}/wordpress/restart',  [HostingController::class, 'wordpressRestart']);
    Route::post('/{uuid}/wordpress/deploy',   [HostingController::class, 'wordpressDeploy']);
    // Acciones genéricas de hosting (cualquier servicio Coolify, no solo WordPress)
    Route::post('/{uuid}/restart',            [HostingController::class, 'restart']);
    Route::post('/{uuid}/redeploy',           [HostingController::class, 'redeploy']);
    Route::get('/{uuid}/domains',    [HostingController::class, 'domains']);
    Route::post('/{uuid}/domains',   [HostingController::class, 'createDomain']);
    Route::delete('/{uuid}/domains/{domain}', [HostingController::class, 'deleteDomain']);
    Route::get('/{uuid}/stats',      [HostingController::class, 'stats']);
    Route::get('/{uuid}/ssl',                         [HostingController::class, 'ssl']);
    Route::post('/{uuid}/ssl/toggle-https',           [HostingController::class, 'toggleForceHttps']);
    Route::get('/{uuid}/dns',                         [HostingController::class, 'dnsRecords']);
    Route::post('/{uuid}/dns',                        [HostingController::class, 'createDnsRecord']);
    Route::put('/{uuid}/dns/{recordId}',              [HostingController::class, 'updateDnsRecord']);
    Route::delete('/{uuid}/dns/{recordId}',           [HostingController::class, 'deleteDnsRecord']);
});
