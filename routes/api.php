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
Route::post("auth/register", [App\Http\Controllers\AuthController::class, "register"]);
Route::post("auth/login", [App\Http\Controllers\AuthController::class, "login"]);
Route::post("auth/google/callback", [App\Http\Controllers\GoogleLoginController::class, "handleGoogleCallback"]);
Route::post("auth/2fa/verify", [App\Http\Controllers\TwoFactorController::class, "verifyLogin"]);

// Stripe webhook (no authentication required)
Route::post("/stripe/webhook", [App\Http\Controllers\StripeWebhookController::class, "handleWebhook"]);

// Product routes (public)
Route::get("/products", [App\Http\Controllers\ProductController::class, "index"]);
Route::get("/products/{uuid}", [App\Http\Controllers\ProductController::class, "show"]);
Route::get("/products/service-type/{serviceType}", [App\Http\Controllers\ProductController::class, "getByServiceType"]);

// Public routes for Categories, Billing Cycles, and Service Plans
Route::prefix("categories")->group(function () {
    Route::get("/", [App\Http\Controllers\CategoryController::class, "index"]);
    Route::get("/with-plans", [App\Http\Controllers\CategoryController::class, "indexWithPlans"]);
    Route::get("/slug/{slug}", [App\Http\Controllers\CategoryController::class, "showBySlug"]);
});

Route::prefix("billing-cycles")->group(function () {
    Route::get("/", [App\Http\Controllers\BillingCycleController::class, "index"]);
});

Route::prefix("service-plans")->group(function () {
    Route::get("/", [App\Http\Controllers\ServicePlanController::class, "index"]);
    Route::get("/add-ons/{AddSlug}", [App\Http\Controllers\ServicePlanController::class, "listAddOns"]);
    Route::get("/category/{categorySlug}", [App\Http\Controllers\ServicePlanController::class, "indexByCategorySlug"]);
    Route::get("/{uuid}", [App\Http\Controllers\ServicePlanController::class, "show"]);
});

// Temporary routes for testing (without authentication) - Consider removing in production
Route::get("/test/dashboard/stats", [App\Http\Controllers\DashboardController::class, "getStats"]);
Route::get("/test/dashboard/services", [App\Http\Controllers\DashboardController::class, "getServices"]);
Route::get("/test/dashboard/activity", [App\Http\Controllers\DashboardController::class, "getActivity"]);


