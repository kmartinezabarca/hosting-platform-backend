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
| ESTE ARCHIVO CONTIENE ÚNICAMENTE RUTAS PÚBLICAS QUE NO REQUIEREN AUTENTICACIÓN.
| Las rutas que requieren autenticación (stateful, con cookies) están en web.php.
|
*/

// Public authentication routes (initial login/registration, no session required yet)
Route::middleware('throttle:10,1')->group(function () {
    Route::post("auth/register", [App\Http\Controllers\Auth\AuthController::class, "register"]);
    Route::post("auth/google/callback", [App\Http\Controllers\Auth\GoogleLoginController::class, "handleGoogleCallback"]);
});

Route::middleware('throttle:5,1')->group(function () {
    Route::post("auth/login", [App\Http\Controllers\Auth\AuthController::class, "login"]);
    Route::post("auth/2fa/verify", [App\Http\Controllers\Auth\TwoFactorController::class, "verifyLogin"]);
});

// Stripe webhook (no authentication required)
Route::post("/stripe/webhook", [App\Http\Controllers\Common\StripeWebhookController::class, "handleWebhook"]);

// Product routes (public)
Route::get("/products", [App\Http\Controllers\Client\ProductController::class, "index"]);
Route::get("/products/{uuid}", [App\Http\Controllers\Client\ProductController::class, "show"]);
Route::get("/products/service-type/{serviceType}", [App\Http\Controllers\Client\ProductController::class, "getByServiceType"]);

// Public routes for Categories, Billing Cycles, and Service Plans
Route::prefix("categories")->group(function () {
    Route::get("/", [App\Http\Controllers\Client\CategoryController::class, "index"]);
    Route::get("/with-plans", [App\Http\Controllers\Client\CategoryController::class, "indexWithPlans"]);
    Route::get("/slug/{slug}", [App\Http\Controllers\Client\CategoryController::class, "showBySlug"]);
});

Route::prefix("billing-cycles")->group(function () {
    Route::get("/", [App\Http\Controllers\Client\BillingCycleController::class, "index"]);
});

Route::prefix("service-plans")->group(function () {
    Route::get("/", [App\Http\Controllers\Client\ServicePlanController::class, "index"]);
    Route::get("/add-ons/{AddSlug}", [App\Http\Controllers\Client\ServicePlanController::class, "listAddOns"]);
    Route::get("/category/{categorySlug}", [App\Http\Controllers\Client\ServicePlanController::class, "indexByCategorySlug"]);
    Route::get("/{uuid}", [App\Http\Controllers\Client\ServicePlanController::class, "show"]);
});

// Temporary routes for testing (without authentication) - Consider removing in production
// Route::get("/test/dashboard/stats", [App\Http\Controllers\DashboardController::class, "getStats"]);
// Route::get("/test/dashboard/services", [App\Http\Controllers\DashboardController::class, "getServices"]);
// Route::get("/test/dashboard/activity", [App\Http\Controllers\DashboardController::class, "getActivity"]);




// Marketing Services (public)
Route::get("/marketing-services", [App\Http\Controllers\Client\MarketingServiceController::class, "index"]);


use App\Http\Controllers\Client\BlogSubscriptionController;

Route::post("/blog/subscribe", [BlogSubscriptionController::class, "subscribe"]);
Route::post("/blog/unsubscribe/{uuid}", [BlogSubscriptionController::class, "unsubscribe"]);

use App\Http\Controllers\Client\DocumentationController;
use App\Http\Controllers\Client\ApiDocumentationController;
use App\Http\Controllers\Client\SystemStatusController;

Route::prefix("documentation")->group(function () {
    Route::get("/", [DocumentationController::class, "index"]);
    Route::get("/{slug}", [DocumentationController::class, "show"]);
});

Route::prefix("api-documentation")->group(function () {
    Route::get("/", [ApiDocumentationController::class, "index"]);
    Route::get("/{slug}", [ApiDocumentationController::class, "show"]);
});

Route::prefix("system-status")->group(function () {
    Route::get("/", [SystemStatusController::class, "index"]);
});

Route::post("/documentation-requests", [App\Http\Controllers\Api\DocumentationRequestController::class, "store"]);

// Blog routes (public)
Route::prefix("blog")->group(function () {
    Route::get("/posts", [App\Http\Controllers\Client\BlogController::class, "index"]);
    Route::get("/posts/featured", [App\Http\Controllers\Client\BlogController::class, "featuredPosts"]);
    Route::get("/posts/{slug}", [App\Http\Controllers\Client\BlogController::class, "show"]);
    Route::get("/categories", [App\Http\Controllers\Client\BlogController::class, "categories"]);
    Route::get("/categories/{categorySlug}/posts", [App\Http\Controllers\Client\BlogController::class, "postsByCategory"]);
});
