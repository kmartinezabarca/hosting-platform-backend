<?php

use App\Http\Controllers\RokePet\AdminController;
use App\Http\Controllers\RokePet\AuthController;
use App\Http\Controllers\RokePet\BillingController;
use App\Http\Controllers\RokePet\MedicalRecordController;
use App\Http\Controllers\RokePet\OwnerController;
use App\Http\Controllers\RokePet\PetController;
use App\Http\Controllers\RokePet\PublicController;
use App\Http\Controllers\RokePet\PushController;
use App\Http\Controllers\RokePet\ReminderController;
use App\Http\Controllers\RokePet\StripeController;
use App\Http\Controllers\RokePet\VaccineController;
use App\Http\Controllers\RokePet\VetLinkController;
use App\Http\Controllers\RokePet\WeightHistoryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| roke.pet API Routes  —  prefijo: /api/rp
|--------------------------------------------------------------------------
| Completamente aisladas del resto del hosting-platform.
| Auth: Sanctum token (Authorization: Bearer <token>)
*/

// ── Rutas públicas (sin autenticación) ────────────────────────────────────────
Route::get('/pets/{slug}',        [PublicController::class, 'petBySlug']);
Route::post('/pets/{slug}/scan',  [PublicController::class, 'recordScan']);

// Portal veterinario (acceso por token, sin auth de usuario)
Route::get('/vet/{token}',             [VetLinkController::class, 'portal']);
Route::post('/vet/{token}/records',    [VetLinkController::class, 'addRecord']);
Route::post('/vet/{token}/vaccines',   [VetLinkController::class, 'addVaccine']);

// ── Auth ──────────────────────────────────────────────────────────────────────
Route::post('/auth/register',       [AuthController::class, 'register']);
Route::post('/auth/login',          [AuthController::class, 'login']);
Route::get('/auth/google',          [AuthController::class, 'redirectToGoogle']);
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);

// Stripe webhook — sin auth, verificado por firma
Route::post('/stripe/webhook', [StripeController::class, 'webhook'])
    ->withoutMiddleware(['throttle:api']);

// ── Rutas protegidas ──────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);

    // Owner profile
    Route::get('/owner',  [OwnerController::class, 'show']);
    Route::put('/owner',  [OwnerController::class, 'update']);

    // Pets
    Route::get('/my-pets',           [PetController::class, 'index']);
    Route::post('/my-pets',          [PetController::class, 'store']);
    Route::put('/my-pets/{id}',      [PetController::class, 'update']);
    Route::delete('/my-pets/{id}',   [PetController::class, 'destroy']);
    Route::post('/my-pets/{id}/photo', [PetController::class, 'uploadPhoto']);

    // Vet links (gestión: listar, generar, revocar)
    Route::get('/my-pets/{petId}/vet-links',          [VetLinkController::class, 'index']);
    Route::post('/my-pets/{petId}/vet-links',         [VetLinkController::class, 'generate']);
    Route::delete('/my-pets/{petId}/vet-links/{id}',  [VetLinkController::class, 'revoke']);

    // Push notifications
    Route::post('/push/subscribe',   [PushController::class, 'subscribe']);
    Route::post('/push/unsubscribe', [PushController::class, 'unsubscribe']);

    // Vaccines
    Route::post('/my-pets/{petId}/vaccines', [VaccineController::class, 'store']);
    Route::put('/vaccines/{id}',             [VaccineController::class, 'update']);
    Route::delete('/vaccines/{id}',          [VaccineController::class, 'destroy']);

    // Medical records
    Route::post('/my-pets/{petId}/records', [MedicalRecordController::class, 'store']);
    Route::put('/records/{id}',             [MedicalRecordController::class, 'update']);
    Route::delete('/records/{id}',          [MedicalRecordController::class, 'destroy']);

    // Weight history
    Route::get('/my-pets/{petId}/weight',  [WeightHistoryController::class, 'index']);
    Route::post('/my-pets/{petId}/weight', [WeightHistoryController::class, 'store']);
    Route::delete('/weight/{id}',          [WeightHistoryController::class, 'destroy']);

    // Reminders
    Route::get('/reminders', [ReminderController::class, 'show']);
    Route::put('/reminders', [ReminderController::class, 'update']);

    // Billing
    Route::get('/billing/subscription',    [BillingController::class, 'show']);
    Route::put('/billing/subscription',    [BillingController::class, 'upsert']);
    Route::post('/billing/checkout',       [StripeController::class, 'createCheckoutSession']);
    Route::post('/billing/portal',         [StripeController::class, 'billingPortal']);

    // Admin
    Route::get('/admin/check',    [AdminController::class, 'check']);
    Route::get('/admin/overview', [AdminController::class, 'overview']);
});
