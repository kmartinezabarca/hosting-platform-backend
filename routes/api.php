<?php

use App\Http\Controllers\Api\DocumentationRequestController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\GoogleLoginController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Client\ApiDocsController;
use App\Http\Controllers\Client\ApiDocumentationController;
use App\Http\Controllers\Client\BillingCycleController;
use App\Http\Controllers\Client\BlogController;
use App\Http\Controllers\Client\BlogSubscriptionController;
use App\Http\Controllers\Client\CategoryController;
use App\Http\Controllers\Client\DocumentationController;
use App\Http\Controllers\Client\MarketingServiceController;
use App\Http\Controllers\Client\ProductController;
use App\Http\Controllers\Client\ServicePlanController;
use App\Http\Controllers\Client\SystemStatusController;
use App\Http\Controllers\Common\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application.
|
*/

// Public authentication routes
Route::middleware('throttle:10,1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/google/callback', [GoogleLoginController::class, 'handleGoogleCallback']);
});

Route::middleware('throttle:5,1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/2fa/verify', [TwoFactorController::class, 'verifyLogin']);
});

// Stripe webhook (no authentication required)
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handleWebhook']);

// Swagger/OpenAPI Documentation
Route::get('/docs', [ApiDocsController::class, 'json']);

// Product routes (public)
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{uuid}', [ProductController::class, 'show']);
Route::get('/products/service-type/{serviceType}', [ProductController::class, 'getByServiceType']);

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

// Blog
Route::prefix('blog')->group(function () {
    Route::get('/posts', [BlogController::class, 'index']);
    Route::get('/posts/featured', [BlogController::class, 'featuredPosts']);
    Route::get('/posts/{slug}', [BlogController::class, 'show']);
    Route::get('/categories', [BlogController::class, 'categories']);
    Route::get('/categories/{categorySlug}/posts', [BlogController::class, 'postsByCategory']);
});

Route::post('/blog/subscribe', [BlogSubscriptionController::class, 'subscribe']);
Route::post('/blog/unsubscribe/{uuid}', [BlogSubscriptionController::class, 'unsubscribe']);

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

Route::post('/documentation-requests', [DocumentationRequestController::class, 'store']);
