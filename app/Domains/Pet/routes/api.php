<?php

use App\Domains\Pet\Http\Controllers\AdminController;
use App\Domains\Pet\Http\Controllers\AdminModerationController;
use App\Domains\Pet\Http\Controllers\AdminTipCampaignController;
use App\Domains\Pet\Http\Controllers\AdoptionController;
use App\Domains\Pet\Http\Controllers\AuthController;
use App\Domains\Pet\Http\Controllers\BillingController;
use App\Domains\Pet\Http\Controllers\CommunityController;
use App\Domains\Pet\Http\Controllers\InboxController;
use App\Domains\Pet\Http\Controllers\LostController;
use App\Domains\Pet\Http\Controllers\MedicalRecordController;
use App\Domains\Pet\Http\Controllers\MyAdoptionController;
use App\Domains\Pet\Http\Controllers\OwnerController;
use App\Domains\Pet\Http\Controllers\PasswordResetController;
use App\Domains\Pet\Http\Controllers\PetController;
use App\Domains\Pet\Http\Controllers\PlanController;
use App\Domains\Pet\Http\Controllers\PublicController;
use App\Domains\Pet\Http\Controllers\PushController;
use App\Domains\Pet\Http\Controllers\ReminderController;
use App\Domains\Pet\Http\Controllers\ReputationController;
use App\Domains\Pet\Http\Controllers\StripeController;
use App\Domains\Pet\Http\Controllers\VaccineController;
use App\Domains\Pet\Http\Controllers\VetContactController;
use App\Domains\Pet\Http\Controllers\VetLinkController;
use App\Domains\Pet\Http\Controllers\WeightHistoryController;
use App\Domains\Pet\Http\Controllers\Chat\OwnerChatController;
use App\Domains\Pet\Http\Controllers\Chat\GuestChatController;
use App\Domains\Pet\Http\Controllers\Chat\AdminChatController;
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

// ── Adopción (público) ────────────────────────────────────────────────────────
Route::get('/adoptions',        [AdoptionController::class, 'index']);
Route::get('/adoptions/{slug}', [AdoptionController::class, 'show']);
// Reporte de moderación — público, rate-limit estricto (5/min por IP).
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/adoptions/{slug}/report',  [AdoptionController::class, 'report']);
});

// Reputación pública de un dueño (badge / perfil de confianza).
Route::get('/reputation/{ownerId}', [ReputationController::class, 'show']);
// Reporte de reseña (moderación) — público, rate-limit estricto (5/min por IP).
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/reputation/reviews/{id}/report', [ReputationController::class, 'reportReview']);
});

// ── Comunidad (público: ver feed y comentarios) ───────────────────────────────
Route::get('/community/feed',                [CommunityController::class, 'feed']);
Route::get('/community/posts/{id}/comments', [CommunityController::class, 'comments']);
// Reportes de moderación — rate-limit estricto (5/min por IP).
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/community/posts/{id}/report', [CommunityController::class, 'report']);
});

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

// ── Chat de invitado (landing pública, sin sesión) ────────────────────────────
// Alta protegida por Turnstile + rate-limit estricto (5/min IP). El resto se
// autoriza por header X-Guest-Token. Sin tiempo real: el frontend hace polling.
Route::prefix('chat/guest')->group(function () {
    Route::middleware('throttle:5,1')->group(function () {
        Route::post('/conversation', [GuestChatController::class, 'start']);
    });
    Route::get('/conversation',          [GuestChatController::class, 'current'])->middleware('throttle:60,1');
    Route::get('/conversation/messages', [GuestChatController::class, 'messages'])->middleware('throttle:120,1');
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('/conversation/messages',  [GuestChatController::class, 'send']);
        Route::post('/conversation/escalate',  [GuestChatController::class, 'escalate']);
        Route::post('/conversation/read',       [GuestChatController::class, 'read']);
    });
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

    // Solicitud de adopción: siempre autenticada para enlazar adoptante, seguimiento y reputación.
    Route::middleware('throttle:5,1')->group(function () {
        Route::post('/adoptions/{slug}/request', [AdoptionController::class, 'request']);
    });

    // Mascota perdida
    Route::post('/my-pets/{id}/lost',              [LostController::class, 'markLost']);
    Route::delete('/my-pets/{id}/lost',            [LostController::class, 'markFound']);
    Route::get('/my-pets/{id}/scan-history',       [LostController::class, 'scanHistory']);
    Route::get('/my-pets/{id}/scan-analytics',     [LostController::class, 'scanAnalytics']);
    Route::get('/my-pets/{id}/lost-poster',        [LostController::class, 'lostPoster']);

    // Adopción (gestión del publicador)
    Route::get('/my-adoptions',                             [MyAdoptionController::class, 'index']);
    Route::post('/my-adoptions',                            [MyAdoptionController::class, 'store']);
    Route::put('/my-adoptions/{id}',                        [MyAdoptionController::class, 'update']);
    Route::delete('/my-adoptions/{id}',                     [MyAdoptionController::class, 'destroy']);
    Route::post('/my-adoptions/{id}/photo',                 [MyAdoptionController::class, 'uploadPhoto']);
    Route::patch('/my-adoptions/{id}/status',               [MyAdoptionController::class, 'updateStatus']);
    Route::get('/my-adoptions/{id}/requests',               [MyAdoptionController::class, 'requests']);
    Route::patch('/my-adoptions/{id}/requests/{requestId}', [MyAdoptionController::class, 'respondRequest']);

    // Reputación de adopciones (reseñas bidireccionales + seguimiento con fotos)
    Route::get('/my-adoption-history', [ReputationController::class, 'myAdoptionHistory']);
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/adoptions/reviews',                   [ReputationController::class, 'storeReview']);
        Route::post('/my-adoptions/{id}/followups/request', [ReputationController::class, 'requestFollowup']);
        Route::post('/adoptions/followups/{id}/submit',     [ReputationController::class, 'submitFollowup']);
        Route::post('/adoptions/followups/{id}/react',      [ReputationController::class, 'reactFollowup']);
    });

    // Comunidad (publicar e interactuar) — throttles por usuario contra spam.
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/community/posts', [CommunityController::class, 'store']);
    });
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('/community/posts/{id}/comments', [CommunityController::class, 'storeComment']);
    });
    Route::middleware('throttle:60,1')->group(function () {
        Route::post('/community/posts/{id}/like', [CommunityController::class, 'toggleLike']);
    });
    Route::delete('/community/posts/{id}',                      [CommunityController::class, 'destroy']);
    Route::delete('/community/posts/{id}/comments/{commentId}', [CommunityController::class, 'destroyComment']);

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
    Route::post('/inbox/archive-all',     [InboxController::class, 'archiveAll']);
    Route::delete('/inbox',               [InboxController::class, 'destroyAll']);
    Route::post('/inbox/{id}/read',       [InboxController::class, 'markRead']);
    Route::post('/inbox/{id}/archive',    [InboxController::class, 'archive']);
    Route::post('/inbox/{id}/unarchive',  [InboxController::class, 'unarchive']);
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

    // ── Chat de soporte (lado del dueño) ──────────────────────────────────────
    // Crear conversación y enviar mensajes con rate-limit (anti-spam). El resto
    // (typing, read, escalar, cerrar) con límites más holgados.
    Route::get('/chat/conversation', [OwnerChatController::class, 'current']);
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/chat/conversation', [OwnerChatController::class, 'start']);
    });
    Route::get('/chat/conversations/{conversation}/messages', [OwnerChatController::class, 'messages']);
    Route::middleware('throttle:30,1')->group(function () {
        Route::post('/chat/conversations/{conversation}/messages', [OwnerChatController::class, 'send']);
    });
    Route::post('/chat/conversations/{conversation}/escalate', [OwnerChatController::class, 'escalate']);
    Route::post('/chat/conversations/{conversation}/typing',   [OwnerChatController::class, 'typing']);
    Route::post('/chat/conversations/{conversation}/read',     [OwnerChatController::class, 'read']);
    Route::post('/chat/conversations/{conversation}/close',    [OwnerChatController::class, 'close']);

    // Admin — check queda FUERA del middleware: responde "¿soy admin?" para
    // cualquier usuario autenticado (y auto-promueve a los admins de plataforma).
    Route::get('/admin/check',    [AdminController::class, 'check']);

    // Resto del panel admin — guard a nivel de RUTA (pet.admin) además de los
    // checks por método de los controladores (defensa en profundidad).
    Route::middleware('pet.admin')->group(function () {
        // Estadísticas
        Route::get('/admin/overview', [AdminController::class, 'overview']);
        Route::get('/admin/pets',     [AdminController::class, 'pets']);

        // Detalle de mascota
        Route::get('/admin/pets/{id}',                 [AdminController::class, 'getPet']);
        Route::patch('/admin/pets/{id}/lost-status',   [AdminController::class, 'updateLostStatus']);
        Route::get('/admin/pets/{id}/notifications',   [AdminController::class, 'getPetNotifications']);

        // Notificar propietario
        Route::post('/admin/owners/{ownerId}/notify-expiry', [AdminController::class, 'notifyExpiry']);

        // Moderación de adopciones, reseñas y comunidad
        Route::get('/admin/adoptions',                       [AdminModerationController::class, 'adoptions']);
        Route::get('/admin/adoptions/{id}',                  [AdminModerationController::class, 'adoptionDetail']);
        Route::patch('/admin/adoptions/{id}/moderation',     [AdminModerationController::class, 'moderateAdoption']);
        Route::get('/admin/reviews',                         [AdminModerationController::class, 'reviews']);
        Route::patch('/admin/reviews/{id}/moderation',       [AdminModerationController::class, 'moderateReview']);
        Route::get('/admin/community/posts',                 [AdminModerationController::class, 'communityPosts']);
        Route::patch('/admin/community/posts/{id}/moderation', [AdminModerationController::class, 'moderatePost']);
        Route::get('/admin/community/posts/{id}/comments',   [AdminModerationController::class, 'postComments']);
        Route::delete('/admin/community/comments/{id}',      [AdminModerationController::class, 'deleteComment']);
        Route::get('/admin/moderation-queue',                [AdminModerationController::class, 'moderationQueue']);

        // Registro de notificaciones
        Route::get('/admin/notifications',             [AdminController::class, 'listNotifications']);
        Route::get('/admin/notifications/{id}',        [AdminController::class, 'getNotification']);
        Route::post('/admin/notifications/{id}/retry', [AdminController::class, 'retryNotification']);

        // ── Consejos (biblioteca) + campañas de notificación ──────────────────
        Route::get('/admin/tips',          [AdminTipCampaignController::class, 'tips']);
        Route::post('/admin/tips',         [AdminTipCampaignController::class, 'storeTip']);
        Route::put('/admin/tips/{id}',     [AdminTipCampaignController::class, 'updateTip']);
        Route::delete('/admin/tips/{id}',  [AdminTipCampaignController::class, 'destroyTip']);

        Route::get('/admin/campaigns/audience-count', [AdminTipCampaignController::class, 'audienceCount']);
        Route::get('/admin/campaigns',                [AdminTipCampaignController::class, 'campaigns']);
        Route::post('/admin/campaigns',               [AdminTipCampaignController::class, 'storeCampaign']);
        Route::post('/admin/campaigns/test',          [AdminTipCampaignController::class, 'testCampaign']);
        Route::get('/admin/campaigns/{id}',           [AdminTipCampaignController::class, 'showCampaign']);
        Route::post('/admin/campaigns/{id}/cancel',   [AdminTipCampaignController::class, 'cancelCampaign']);

        // Planes
        Route::get('/admin/plans',              [PlanController::class, 'adminIndex']);
        Route::post('/admin/plans',             [PlanController::class, 'store']);
        Route::put('/admin/plans/{id}',         [PlanController::class, 'update']);
        Route::patch('/admin/plans/{id}/toggle', [PlanController::class, 'toggle']);
        Route::delete('/admin/plans/{id}',      [PlanController::class, 'destroy']);

        // ── Chat de soporte (panel admin / agente) ────────────────────────────
        Route::get('/admin/chat/stats',                            [AdminChatController::class, 'stats']);
        Route::get('/admin/chat/conversations',                    [AdminChatController::class, 'index']);
        Route::get('/admin/chat/conversations/{conversation}',     [AdminChatController::class, 'show']);
        Route::get('/admin/chat/conversations/{conversation}/messages', [AdminChatController::class, 'messages']);
        Route::post('/admin/chat/conversations/{conversation}/messages', [AdminChatController::class, 'send']);
        Route::post('/admin/chat/conversations/{conversation}/takeover',  [AdminChatController::class, 'takeover']);
        Route::post('/admin/chat/conversations/{conversation}/assign',    [AdminChatController::class, 'assign']);
        Route::post('/admin/chat/conversations/{conversation}/typing',    [AdminChatController::class, 'typing']);
        Route::post('/admin/chat/conversations/{conversation}/read',      [AdminChatController::class, 'read']);
        Route::post('/admin/chat/conversations/{conversation}/resolve',   [AdminChatController::class, 'resolve']);
        Route::post('/admin/chat/conversations/{conversation}/close',     [AdminChatController::class, 'close']);
    });
});
