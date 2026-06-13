<?php

use App\Domains\Platform\Ai\Http\Controllers\V2\ConversationController;
use App\Domains\Platform\Compute\Http\Controllers\V2\DeploymentController;
use App\Domains\Platform\Compute\Http\Controllers\V2\EnvVarController;
use App\Domains\Platform\Compute\Http\Controllers\V2\GamePresetController;
use App\Domains\Platform\Compute\Http\Controllers\V2\PlanController;
use App\Domains\Platform\Compute\Http\Controllers\V2\ProjectController;
use App\Domains\Platform\Compute\Http\Controllers\V2\ResourceController;
use App\Domains\Platform\Compute\Http\Controllers\V2\TeamController;
use App\Domains\Platform\Compute\Http\Controllers\V2\TeamMemberController;
use App\Domains\Platform\SiteBuilder\Http\Controllers\V2\PageGeneratorController;
use App\Domains\Platform\Git\Http\Controllers\GithubWebhookController;
use App\Domains\Platform\Git\Http\Controllers\V2\GithubController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v2 — plano de cómputo
|--------------------------------------------------------------------------
|
| Contrato nuevo (OpenAPI: docs/openapi/v2.yaml) que consumen el portal
| React, la app Flutter y el agente de IA. v1 (api.php/client.php/admin.php)
| queda congelada — las pantallas migran una por una.
|
| Convenciones: IDs públicos = uuid (route binding via HasUuidColumn),
| respuestas { success, data }, autorización por Policy (membresía de equipo).
|
*/

// Webhook de la GitHub App — público, verificado por HMAC (X-Hub-Signature-256).
Route::post('/webhooks/github', [GithubWebhookController::class, 'handle']);

Route::prefix('v2')
    ->middleware(['auth:sanctum', 'session.timeout'])
    ->group(function () {

        // Teams
        Route::get('/teams', [TeamController::class, 'index']);

        // Catálogo de planes de cómputo (precios mensual/anual + ahorro).
        Route::get('/plans', [PlanController::class, 'index']);

        // Catálogo de presets de servidores de juego (specs + disponibilidad).
        Route::get('/game-presets', [GamePresetController::class, 'index']);

        // Miembros del equipo (gestionar requiere rol admin+)
        Route::get('/teams/{team}/members', [TeamMemberController::class, 'index']);
        Route::post('/teams/{team}/members', [TeamMemberController::class, 'store']);
        Route::patch('/teams/{team}/members/{member}', [TeamMemberController::class, 'update']);
        Route::delete('/teams/{team}/members/{member}', [TeamMemberController::class, 'destroy']);

        // Projects
        Route::get('/projects', [ProjectController::class, 'index']);
        Route::post('/projects', [ProjectController::class, 'store']);
        Route::get('/projects/{project}', [ProjectController::class, 'show']);
        Route::post('/projects/{project}/analyze', [ProjectController::class, 'analyze'])
            ->middleware('throttle:10,1'); // golpea la API de GitHub

        // GitHub App
        Route::get('/github/install-url', [GithubController::class, 'installUrl']);
        Route::post('/github/installations/claim', [GithubController::class, 'claim']);
        Route::get('/github/installations', [GithubController::class, 'installations']);
        Route::get('/github/installations/{installation}/repos', [GithubController::class, 'repos']);
        Route::get('/github/installations/{installation}/branches', [GithubController::class, 'branches']);

        // Env vars (write-only para secretos; aplican en el próximo deploy)
        Route::get('/environments/{environment}/env-vars', [EnvVarController::class, 'index']);
        Route::put('/environments/{environment}/env-vars', [EnvVarController::class, 'upsert']);
        Route::post('/environments/{environment}/env-vars/import', [EnvVarController::class, 'import']);
        Route::delete('/environments/{environment}/env-vars/{key}', [EnvVarController::class, 'destroy'])
            ->where('key', '[A-Za-z_][A-Za-z0-9_]*');

        // Resources (apps) — operaciones largas responden 202 + orchestration
        Route::post('/environments/{environment}/resources', [ResourceController::class, 'store']);
        Route::get('/resources/{resource}', [ResourceController::class, 'show']);
        Route::get('/orchestrations/{orchestration}', [ResourceController::class, 'orchestration']);

        // Deployments
        Route::get('/resources/{resource}/deployments', [DeploymentController::class, 'index']);
        Route::post('/resources/{resource}/deployments', [DeploymentController::class, 'store']);
        Route::post('/resources/{resource}/deployments/{deployment}/rollback', [DeploymentController::class, 'rollback']);
        Route::get('/deployments/{deployment}/logs', [DeploymentController::class, 'logs']);

        // Asistente de IA (lectura + diagnóstico + acciones safe_write con gate)
        Route::post('/ai/conversations', [ConversationController::class, 'store']);
        Route::get('/ai/conversations/{conversation}', [ConversationController::class, 'show']);
        Route::post('/ai/conversations/{conversation}/messages', [ConversationController::class, 'message'])
            ->middleware('throttle:20,1'); // cada mensaje puede costar varias llamadas LLM

        // Gate de confirmación: las acciones que el agente propone solo se
        // ejecutan cuando el usuario las confirma (o las rechaza).
        Route::post('/ai/conversations/{conversation}/actions/{action}/confirm', [ConversationController::class, 'confirmAction']);
        Route::post('/ai/conversations/{conversation}/actions/{action}/reject', [ConversationController::class, 'rejectAction']);

        // SiteBuilder: generación de páginas con IA (proveedor agnóstico por env).
        // Throttle bajo: cada llamada es una generación LLM (cara/lenta).
        Route::post('/site-builder/generate', [PageGeneratorController::class, 'generate'])
            ->middleware('throttle:10,1');
        Route::get('/site-builder/pages', [PageGeneratorController::class, 'index']);
        Route::get('/site-builder/pages/{page}', [PageGeneratorController::class, 'show']);
        Route::delete('/site-builder/pages/{page}', [PageGeneratorController::class, 'destroy']);
        // "Desplegar con ROKE": publicar/despublicar (el backend sirve el HTML).
        Route::post('/site-builder/pages/{page}/publish', [PageGeneratorController::class, 'publish']);
        Route::post('/site-builder/pages/{page}/unpublish', [PageGeneratorController::class, 'unpublish']);

        // Mes 2 pendiente: tier destructive del agente, game servers self-service
        // v2 — ver docs/blueprint/02-api-and-modules.md
    });
