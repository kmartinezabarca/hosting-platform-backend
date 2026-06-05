<?php

use App\Domains\Pet\Http\Controllers\AdminController;
use App\Domains\Pet\Http\Controllers\AuthController;
use App\Domains\Pet\Http\Controllers\BillingController;
use App\Domains\Pet\Http\Controllers\InboxController;
use App\Domains\Pet\Http\Controllers\LostController;
use App\Domains\Pet\Http\Controllers\MedicalRecordController;
use App\Domains\Pet\Http\Controllers\OwnerController;
use App\Domains\Pet\Http\Controllers\PasswordResetController;
use App\Domains\Pet\Http\Controllers\PetController;
use App\Domains\Pet\Http\Controllers\PlanController;
use App\Domains\Pet\Http\Controllers\PublicController;
use App\Domains\Pet\Http\Controllers\PushController;
use App\Domains\Pet\Http\Controllers\ReminderController;
use App\Domains\Pet\Http\Controllers\StripeController;
use App\Domains\Pet\Http\Controllers\VaccineController;
use App\Domains\Pet\Http\Controllers\VetContactController;
use App\Domains\Pet\Http\Controllers\VetLinkController;
use App\Domains\Pet\Http\Controllers\WeightHistoryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| roke.pet API Routes  —  prefijo: /api/rp
|--------------------------------------------------------------------------
| Completamente aisladas del resto del hosting-platform.
| Auth: Sanctum token (Authorization: Bearer <token>)
*/

// ── Rutas públicas (sin autenticación) ────────────────────────────────────────
Route::get('/pets/{slug}',              [PublicController::class, 'petBySlug']);
Route::middleware('throttle:30,1')->group(function () {
    Route::post('/pets/{slug}/scan',    [PublicController::class, 'recordScan']);
});
// "Encontré a esta mascota" — relay anónimo al dueño. Rate-limit estricto
// (5/min por IP) para evitar spam/acoso al dueño.
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/pets/{slug}/found',   [PublicController::class, 'reportFound']);
});
Route::get('/pets/{slug}/lost-poster',  [LostController::class, 'publicLostPoster']);

// Planes (público — para la página de pricing)
Route::get('/plans',        [PlanController::class, 'index']);
Route::get('/plans/{slug}', [PlanController::class, 'show']);

// Portal veterinario (acceso por token, sin auth de usuario)
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/vet/{token}',        [VetLinkController::class, 'portal']);
    Route::get('/vet/{token}/weight', [VetLinkController::class, 'weight']);
});
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/vet/{token}/records',  [VetLinkController::class, 'addRecord']);
    Route::post('/vet/{token}/vaccines', [VetLinkController::class, 'addVaccine']);
});

// ── Auth ──────────────────────────────────────────────────────────────────────
Route::post('/auth/register',          [AuthController::class, 'register']);
Route::post('/auth/login',             [AuthController::class, 'login']);
Route::post('/auth/google/callback',   [AuthController::class, 'handleGoogleCallback']);

// Recuperación de contraseña (público, throttle estricto)
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/auth/forgot-password', [PasswordResetController::class, 'sendResetLink']);
    Route::post('/auth/reset-password',  [PasswordResetController::class, 'reset']);
});

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
    Route::get('/my-pets',             [PetController::class, 'index']);
    Route::post('/my-pets',            [PetController::class, 'store']);
    Route::put('/my-pets/{id}',        [PetController::class, 'update']);
    Route::delete('/my-pets/{id}',     [PetController::class, 'destroy']);
    Route::post('/my-pets/{id}/photo', [PetController::class, 'uploadPhoto']);
    Route::post('/my-pets/{id}/cover', [PetController::class, 'uploadCover']);

    // Mascota perdida
    Route::post('/my-pets/{id}/lost',              [LostController::class, 'markLost']);
    Route::delete('/my-pets/{id}/lost',            [LostController::class, 'markFound']);
    Route::get('/my-pets/{id}/scan-history',       [LostController::class, 'scanHistory']);
    Route::get('/my-pets/{id}/scan-analytics',     [LostController::class, 'scanAnalytics']);
    Route::get('/my-pets/{id}/lost-poster',        [LostController::class, 'lostPoster']);

    // Vet links
    Route::get('/my-pets/{petId}/vet-links',         [VetLinkController::class, 'index']);
    Route::post('/my-pets/{petId}/vet-links',        [VetLinkController::class, 'generate']);
    Route::delete('/my-pets/{petId}/vet-links/{id}', [VetLinkController::class, 'revoke']);

    // Push notifications
    Route::post('/push/subscribe',   [PushController::class, 'subscribe']);
    Route::post('/push/unsubscribe', [PushController::class, 'unsubscribe']);

    // Inbox de notificaciones push
    Route::get('/inbox',                  [InboxController::class, 'index']);
    Route::post('/inbox/read-all',        [InboxController::class, 'markAllRead']);
    Route::post('/inbox/{id}/read',       [InboxController::class, 'markRead']);
    Route::post('/inbox/{id}/archive',    [InboxController::class, 'archive']);
    Route::delete('/inbox/{id}',          [InboxController::class, 'destroy']);

    // Vaccines
    Route::post('/my-pets/{petId}/vaccines', [VaccineController::class, 'store']);
    Route::put('/vaccines/{id}',             [VaccineController::class, 'update']);
    Route::post('/vaccines/{id}/photo',      [VaccineController::class, 'uploadPhoto']);
    Route::delete('/vaccines/{id}',          [VaccineController::class, 'destroy']);

    // Medical records
    Route::post('/my-pets/{petId}/records', [MedicalRecordController::class, 'store']);
    Route::put('/records/{id}',             [MedicalRecordController::class, 'update']);
    Route::post('/records/{id}/photo',      [MedicalRecordController::class, 'uploadPhoto']);
    Route::delete('/records/{id}',          [MedicalRecordController::class, 'destroy']);

    // Weight history
    Route::get('/my-pets/{petId}/weight',  [WeightHistoryController::class, 'index']);
    Route::post('/my-pets/{petId}/weight', [WeightHistoryController::class, 'store']);
    Route::post('/weight/{id}/photo',      [WeightHistoryController::class, 'uploadPhoto']);
    Route::delete('/weight/{id}',          [WeightHistoryController::class, 'destroy']);

    // Vet contacts (agenda de veterinarios por dueño)
    Route::get('/vets',         [VetContactController::class, 'index']);
    Route::post('/vets',        [VetContactController::class, 'store']);
    Route::put('/vets/{id}',    [VetContactController::class, 'update']);
    Route::delete('/vets/{id}', [VetContactController::class, 'destroy']);

    // Reminders
    Route::get('/reminders', [ReminderController::class, 'show']);
    Route::put('/reminders', [ReminderController::class, 'update']);

    // Billing
    Route::get('/billing/subscription',  [BillingController::class, 'show']);
    Route::put('/billing/subscription',  [BillingController::class, 'upsert']);
    Route::get('/billing/banners',       [BillingController::class, 'banners']);
    Route::get('/billing/invoices',      [StripeController::class, 'getInvoices']);
    Route::post('/billing/checkout',     [StripeController::class, 'createCheckoutSession']);
    Route::post('/billing/portal',       [StripeController::class, 'billingPortal']);

    // Admin — estadísticas
    Route::get('/admin/check',    [AdminController::class, 'check']);
    Route::get('/admin/overview', [AdminController::class, 'overview']);
    Route::get('/admin/pets',     [AdminController::class, 'pets']);

    // Admin — detalle de mascota
    Route::get('/admin/pets/{id}',                 [AdminController::class, 'getPet']);
    Route::patch('/admin/pets/{id}/lost-status',   [AdminController::class, 'updateLostStatus']);
    Route::get('/admin/pets/{id}/notifications',   [AdminController::class, 'getPetNotifications']);

    // Admin — notificar propietario
    Route::post('/admin/owners/{ownerId}/notify-expiry', [AdminController::class, 'notifyExpiry']);

    // Admin — registro de notificaciones
    Route::get('/admin/notifications',             [AdminController::class, 'listNotifications']);
    Route::get('/admin/notifications/{id}',        [AdminController::class, 'getNotification']);
    Route::post('/admin/notifications/{id}/retry', [AdminController::class, 'retryNotification']);

    // Admin — planes
    Route::get('/admin/plans',              [PlanController::class, 'adminIndex']);
    Route::post('/admin/plans',             [PlanController::class, 'store']);
    Route::put('/admin/plans/{id}',         [PlanController::class, 'update']);
    Route::patch('/admin/plans/{id}/toggle', [PlanController::class, 'toggle']);
    Route::delete('/admin/plans/{id}',      [PlanController::class, 'destroy']);
});
