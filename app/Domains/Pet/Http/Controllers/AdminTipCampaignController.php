<?php

namespace App\Domains\Pet\Http\Controllers;

use App\Domains\Pet\Events\PetTipBroadcast;
use App\Domains\Pet\Jobs\SendCampaignJob;
use App\Domains\Pet\Models\AppAdmin;
use App\Domains\Pet\Models\InboxNotification;
use App\Domains\Pet\Models\NotificationCampaign;
use App\Domains\Pet\Models\NotificationLog;
use App\Domains\Pet\Models\NotificationTip;
use App\Domains\Pet\Models\Owner;
use App\Domains\Pet\Services\PushNotificationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin de ROKE Pet — biblioteca de consejos reutilizables + campañas de
 * notificación (envío a todos los dueños, ahora o programado). Auth: pet.admin
 * (a nivel de ruta) + requireAdmin por método (defensa en profundidad).
 *
 * El fan-out masivo lo hace SendCampaignJob (en cola). El envío de PRUEBA va a
 * un solo dueño de forma síncrona, para validar el contenido antes del masivo.
 */
class AdminTipCampaignController extends Controller
{
    public function __construct(private readonly PushNotificationService $push) {}

    /* ===================== Biblioteca de consejos ===================== */

    /** GET /admin/tips — lista de la biblioteca. */
    public function tips(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $tips = NotificationTip::query()
            ->when($request->boolean('only_active'), fn ($q) => $q->active())
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['success' => true, 'data' => $tips]);
    }

    /** POST /admin/tips */
    public function storeTip(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $data = $this->validateTip($request);
        $data['created_by'] = $request->user()->uuid;

        $tip = NotificationTip::create($data);

        return response()->json(['success' => true, 'data' => $tip], 201);
    }

    /** PUT /admin/tips/{id} */
    public function updateTip(Request $request, string $id): JsonResponse
    {
        $this->requireAdmin($request);
        $tip = NotificationTip::findOrFail($id);
        $tip->update($this->validateTip($request));

        return response()->json(['success' => true, 'data' => $tip]);
    }

    /** DELETE /admin/tips/{id} */
    public function destroyTip(Request $request, string $id): JsonResponse
    {
        $this->requireAdmin($request);
        NotificationTip::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    /* ===================== Campañas ===================== */

    /** GET /admin/campaigns/audience-count — cuántos dueños recibirían el envío. */
    public function audienceCount(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        return response()->json(['success' => true, 'data' => ['all' => Owner::count()]]);
    }

    /** GET /admin/campaigns — historial de envíos. */
    public function campaigns(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $campaigns = NotificationCampaign::query()
            ->when($request->get('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->get('per_page', 20), 100));

        return response()->json(['success' => true, 'data' => $campaigns]);
    }

    /** GET /admin/campaigns/{id} */
    public function showCampaign(Request $request, string $id): JsonResponse
    {
        $this->requireAdmin($request);

        return response()->json(['success' => true, 'data' => NotificationCampaign::findOrFail($id)]);
    }

    /**
     * POST /admin/campaigns — crea y despacha una campaña.
     * mode=now → se envía de inmediato; mode=schedule → queda programada y la
     * recoge el comando pet:dispatch-scheduled-campaigns.
     */
    public function storeCampaign(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $validated = $request->validate([
            'tip_id'       => 'nullable|uuid',
            'title'        => 'required_without:tip_id|nullable|string|max:160',
            'body'         => 'required_without:tip_id|nullable|string|max:2000',
            'category'     => 'nullable|string|max:32',
            'url'          => 'nullable|string|max:300',
            'icon'         => 'nullable|string|max:16',
            'mode'         => 'required|in:now,schedule',
            'scheduled_at' => 'required_if:mode,schedule|nullable|date|after:now',
        ]);

        // Snapshot del contenido: desde el tip o inline.
        $tip = null;
        if (! empty($validated['tip_id'])) {
            $tip = NotificationTip::findOrFail($validated['tip_id']);
        }

        $title = $validated['title'] ?? $tip?->title;
        $body  = $validated['body']  ?? $tip?->body;

        if (! $title || ! $body) {
            return response()->json(['success' => false, 'message' => 'Falta título o cuerpo.'], 422);
        }

        $isNow = $validated['mode'] === 'now';

        $campaign = NotificationCampaign::create([
            'tip_id'       => $tip?->id,
            'title'        => $title,
            'body'         => $body,
            'category'     => $validated['category'] ?? $tip?->category ?? 'consejo',
            'url'          => $validated['url'] ?? $tip?->url,
            'icon'         => $validated['icon'] ?? $tip?->icon,
            'audience'     => 'all',
            'status'       => NotificationCampaign::STATUS_SCHEDULED,
            'scheduled_at' => $isNow ? now() : $validated['scheduled_at'],
            'created_by'   => $request->user()->uuid,
        ]);

        if ($isNow) {
            SendCampaignJob::dispatch($campaign->id);
        }

        return response()->json(['success' => true, 'data' => $campaign->refresh()], 201);
    }

    /** POST /admin/campaigns/{id}/cancel — solo si sigue programada. */
    public function cancelCampaign(Request $request, string $id): JsonResponse
    {
        $this->requireAdmin($request);
        $campaign = NotificationCampaign::findOrFail($id);

        if ($campaign->status !== NotificationCampaign::STATUS_SCHEDULED) {
            return response()->json(['success' => false, 'message' => 'Solo se pueden cancelar campañas programadas.'], 422);
        }

        $campaign->forceFill(['status' => NotificationCampaign::STATUS_CANCELED])->save();

        return response()->json(['success' => true, 'data' => $campaign]);
    }

    /**
     * POST /admin/campaigns/test — envía el contenido a UN solo dueño (por
     * defecto, el del propio admin) para previsualizar antes del masivo.
     */
    public function testCampaign(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $validated = $request->validate([
            'tip_id'   => 'nullable|uuid',
            'title'    => 'required_without:tip_id|nullable|string|max:160',
            'body'     => 'required_without:tip_id|nullable|string|max:2000',
            'category' => 'nullable|string|max:32',
            'url'      => 'nullable|string|max:300',
            'icon'     => 'nullable|string|max:16',
            'owner_id' => 'nullable|uuid',
        ]);

        $tip   = ! empty($validated['tip_id']) ? NotificationTip::findOrFail($validated['tip_id']) : null;
        $title = $validated['title'] ?? $tip?->title;
        $body  = $validated['body']  ?? $tip?->body;
        $url   = $validated['url']   ?? $tip?->url;
        $icon  = $validated['icon']  ?? $tip?->icon;

        if (! $title || ! $body) {
            return response()->json(['success' => false, 'message' => 'Falta título o cuerpo.'], 422);
        }

        $ownerId = $validated['owner_id'] ?? $request->user()->uuid;
        if (! Owner::whereKey($ownerId)->exists()) {
            return response()->json(['success' => false, 'message' => 'El destinatario de prueba no es un dueño válido.'], 422);
        }

        $displayTitle = $icon ? trim($icon . ' ' . $title) : $title;
        $data = ['type' => 'tip', 'category' => $validated['category'] ?? $tip?->category ?? 'consejo', 'url' => $url ?? '', 'test' => '1'];

        InboxNotification::createForOwner(
            ownerId: $ownerId, title: $displayTitle, body: $body, notifType: 'tip', url: $url, tag: 'campaign-test-' . now()->timestamp,
        );

        $log = NotificationLog::create([
            'project_id' => 'roke_pet', 'owner_id' => $ownerId, 'channel' => 'push', 'provider' => 'webpush',
            'notification_type' => 'tip', 'title' => $displayTitle, 'body' => $body, 'payload' => ['data' => $data],
            'status' => 'pending', 'max_attempts' => 1,
        ]);

        $result = $this->push->sendToOwnerDetailed($ownerId, $displayTitle, $body, $data);
        $result['sent'] > 0 ? $log->markSent() : $log->markFailed('test', 'sin dispositivos o fallo en prueba');

        event(new PetTipBroadcast($ownerId, $displayTitle, $body, $url));

        return response()->json([
            'success' => true,
            'data'    => ['push_sent' => $result['sent'], 'owner_id' => $ownerId],
            'message' => $result['sent'] > 0
                ? "Prueba enviada: inbox + {$result['sent']} dispositivo(s)."
                : 'Prueba creada en bandeja (el destinatario no tiene push activo).',
        ]);
    }

    /* ===================== Helpers ===================== */

    /** @return array<string, mixed> */
    private function validateTip(Request $request): array
    {
        return $request->validate([
            'title'     => 'required|string|max:160',
            'body'      => 'required|string|max:2000',
            'category'  => 'nullable|string|max:32',
            'url'       => 'nullable|string|max:300',
            'icon'      => 'nullable|string|max:16',
            'is_active' => 'nullable|boolean',
        ]);
    }

    private function requireAdmin(Request $request): void
    {
        if (! AppAdmin::where('user_id', $request->user()->uuid)->exists()) {
            abort(403, 'Acceso denegado');
        }
    }
}
