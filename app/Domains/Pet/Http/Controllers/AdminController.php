<?php

namespace App\Domains\Pet\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Domains\Pet\Jobs\RetryNotificationJob;
use App\Domains\Pet\Models\ActivationEvent;
use App\Domains\Pet\Models\AppAdmin;
use App\Domains\Pet\Models\InboxNotification;
use App\Domains\Pet\Models\NotificationLog;
use App\Domains\Pet\Models\Owner;
use App\Domains\Pet\Models\OwnerSubscription;
use App\Domains\Pet\Models\Pet;
use App\Domains\Pet\Models\PushSubscription;
use App\Domains\Pet\Models\SentReminder;
use App\Domains\Pet\Services\PushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function check(Request $request): JsonResponse
    {
        $isAdmin = AppAdmin::where('user_id', $request->user()->uuid)->exists();

        if (!$isAdmin && in_array($request->user()->role, ['super_admin', 'admin'])) {
            AppAdmin::firstOrCreate(['user_id' => $request->user()->uuid]);
            $isAdmin = true;
        }

        return response()->json(['isAdmin' => $isAdmin]);
    }

    public function overview(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $since30       = now()->subDays(30);
        $owners        = Owner::orderBy('created_at', 'desc')->limit(20)->get();
        $allPets       = Pet::select('id', 'owner_id', 'scanned_count')->get();
        $subscriptions = OwnerSubscription::orderBy('updated_at', 'desc')->get();
        $events        = ActivationEvent::orderBy('occurred_at', 'desc')->limit(20)->get();
        $sentCount     = SentReminder::where('sent_at', '>=', $since30)->count();
        $scansLast30   = ActivationEvent::where('event_type', 'pet_scan_recorded')
            ->where('occurred_at', '>=', $since30)->count();

        // Cargar conteos de push en una sola query para evitar N+1
        $ownerIds       = $owners->pluck('id');
        $pushCounts     = PushSubscription::whereIn('owner_id', $ownerIds)
            ->selectRaw('owner_id, count(*) as cnt')
            ->groupBy('owner_id')
            ->pluck('cnt', 'owner_id');

        $customers = $owners->map(function ($owner) use ($allPets, $subscriptions, $pushCounts) {
            $pets = $allPets->where('owner_id', $owner->id);
            $sub  = $subscriptions->firstWhere('owner_id', $owner->id);

            return [
                'ownerId'              => $owner->id,
                'displayName'          => $owner->display_name ?? 'Sin nombre',
                'email'                => $owner->email ?? '',
                'phone'                => $owner->phone ?? '',
                'petsCount'            => $pets->count(),
                'scansCount'           => $pets->sum('scanned_count'),
                'subscriptionStatus'   => $sub?->status ?? 'trialing',
                'trialEndsAt'          => $sub?->trial_ends_at,
                'currentPeriodEnd'     => $sub?->current_period_end,
                'updatedAt'            => $sub?->updated_at ?? $owner->updated_at,
                'pushSubscriptionsCount' => (int) ($pushCounts[$owner->id] ?? 0),
            ];
        });

        return response()->json([
            'totals' => [
                'owners'              => Owner::count(),
                'pets'                => $allPets->count(),
                'activeSubscriptions' => $subscriptions->whereIn('status', ['active'])->count(),
                'scansLast30Days'     => $scansLast30,
                'reminderEmailsSent'  => $sentCount,
            ],
            'customers'    => $customers,
            'recentEvents' => $events->map(fn($e) => [
                'id'         => $e->id,
                'ownerId'    => $e->owner_id,
                'petId'      => $e->pet_id,
                'eventType'  => $e->event_type,
                'source'     => $e->source,
                'occurredAt' => $e->occurred_at,
                'metadata'   => $e->metadata ?? [],
            ]),
        ]);
    }

    public function pets(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $pets = Pet::with('owner')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($pet) => $this->formatPetListItem($pet));

        return response()->json(['pets' => $pets]);
    }

    public function getPet(Request $request, string $id): JsonResponse
    {
        $this->requireAdmin($request);

        $pet   = Pet::with(['owner', 'vaccines', 'medicalRecords'])->findOrFail($id);
        $owner = $pet->owner;

        // Scan analytics snapshot — reorder() limpia el ORDER BY del scope para compatibilidad con GROUP BY
        $scansBySource = $pet->scanEvents()
            ->reorder()
            ->selectRaw('source, count(*) as cnt')
            ->groupBy('source')
            ->pluck('cnt', 'source')
            ->toArray();

        $scansByCity = $pet->scanEvents()
            ->reorder()
            ->whereNotNull('city')
            ->selectRaw('city, count(*) as cnt')
            ->groupBy('city')
            ->orderByDesc('cnt')
            ->limit(10)
            ->pluck('cnt', 'city')
            ->toArray();

        $lastScan = $pet->scanEvents()->first();

        $recentScans = $pet->scanEvents()
            ->orderBy('scanned_at', 'desc')
            ->limit(20)
            ->get()
            ->map(fn ($s) => [
                'id'          => $s->id,
                'source'      => $s->source,
                'scannedAt'   => $s->scanned_at,
                'city'        => $s->city,
                'country'     => $s->country,
                'hasLocation' => $s->share_location_allowed && $s->latitude !== null,
                'ipAddress'   => $s->ip_address,
                'deviceType'  => $s->device_type,
            ]);

        $recentNotifications = NotificationLog::where('pet_id', $id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($n) => $this->formatNotificationLog($n));

        $sub = $owner ? OwnerSubscription::where('owner_id', $owner->id)->first() : null;

        return response()->json([
            'pet' => [
                'id'                   => $pet->id,
                'name'                 => $pet->name,
                'slug'                 => $pet->slug,
                'species'              => $pet->species,
                'breed'                => $pet->breed,
                'color'                => $pet->color,
                'gender'               => $pet->gender,
                'birthdate'            => $pet->birthdate,
                'weight'               => $pet->weight,
                'scannedCount'         => $pet->scanned_count,
                'nfcId'                => $pet->nfc_id,
                'photoUrl'             => $pet->photo_url,
                'publicProfileEnabled' => $pet->public_profile_enabled,
                'createdAt'            => $pet->created_at,
                // Lost
                'isLost'               => $pet->is_lost,
                'lostSince'            => $pet->lost_since,
                'lostDescription'      => $pet->lost_description,
                'lastSeenLocation'     => $pet->last_seen_location,
                'emergencyContactOverride' => $pet->emergency_contact_override,
                'lostBannerEnabled'    => $pet->lost_banner_enabled,
                'lastScanLocation'     => $pet->last_scan_location,
            ],
            'owner' => $owner ? [
                'id'           => $owner->id,
                'displayName'  => $owner->display_name ?? 'Sin nombre',
                'email'        => $owner->email ?? '',
                'phone'        => $owner->phone ?? '',
                'emergencyPhone' => $owner->emergency_phone ?? null,
                'address'      => $owner->address ?? null,
                'city'         => $owner->city ?? null,
                'country'      => $owner->country ?? null,
                'createdAt'    => $owner->created_at,
                'subscription' => $sub ? [
                    'status'           => $sub->status,
                    'trialEndsAt'      => $sub->trial_ends_at,
                    'currentPeriodEnd' => $sub->current_period_end,
                ] : null,
            ] : null,
            'scanAnalytics' => [
                'totalScans' => $pet->scanned_count,
                'bySource'   => $scansBySource,
                'byCity'     => $scansByCity,
                'lastScan'   => $lastScan ? [
                    'scannedAt' => $lastScan->scanned_at,
                    'city'      => $lastScan->city,
                    'source'    => $lastScan->source,
                ] : null,
            ],
            'recentScans'         => $recentScans,
            'recentNotifications' => $recentNotifications,
        ]);
    }

    public function updateLostStatus(Request $request, string $id): JsonResponse
    {
        $this->requireAdmin($request);

        $pet  = Pet::findOrFail($id);
        $data = $request->validate([
            'isLost'                  => 'required|boolean',
            'lostDescription'         => 'nullable|string|max:500',
            'emergencyContactOverride' => 'nullable|string|max:100',
        ]);

        if ($data['isLost']) {
            $pet->update([
                'is_lost'                   => true,
                'lost_since'                => $pet->lost_since ?? now(),
                'lost_description'          => $data['lostDescription'] ?? $pet->lost_description,
                'emergency_contact_override' => $data['emergencyContactOverride'] ?? $pet->emergency_contact_override,
            ]);
        } else {
            $pet->update([
                'is_lost'                   => false,
                'lost_since'                => null,
                'lost_description'          => null,
                'last_seen_location'        => null,
                'emergency_contact_override' => null,
            ]);
        }

        return response()->json(['ok' => true, 'isLost' => $pet->is_lost]);
    }

    public function getPetNotifications(Request $request, string $id): JsonResponse
    {
        $this->requireAdmin($request);

        Pet::findOrFail($id);

        $perPage = min((int) $request->get('per_page', 20), 100);
        $page    = (int) $request->get('page', 1);

        $notifications = NotificationLog::where('pet_id', $id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => collect($notifications->items())->map(fn ($n) => $this->formatNotificationLog($n)),
            'meta' => [
                'total'       => $notifications->total(),
                'currentPage' => $notifications->currentPage(),
                'lastPage'    => $notifications->lastPage(),
                'perPage'     => $notifications->perPage(),
            ],
        ]);
    }

    public function listNotifications(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $perPage  = min((int) $request->get('per_page', 30), 100);
        $page     = (int) $request->get('page', 1);
        $status   = $request->get('status');
        $type     = $request->get('type');
        $petId    = $request->get('pet_id');

        $query = NotificationLog::with('pet')
            ->orderBy('created_at', 'desc');

        if ($status)  $query->where('status', $status);
        if ($type)    $query->where('notification_type', $type);
        if ($petId)   $query->where('pet_id', $petId);

        $notifications = $query->paginate($perPage, ['*'], 'page', $page);

        // Totals for the filter bar
        $totals = NotificationLog::selectRaw("
            count(*) as total,
            sum(case when status = 'sent' then 1 else 0 end) as sent,
            sum(case when status = 'pending' then 1 else 0 end) as pending,
            sum(case when status = 'failed' then 1 else 0 end) as failed,
            sum(case when status = 'delivered' then 1 else 0 end) as delivered
        ")->first();

        return response()->json([
            'data' => collect($notifications->items())->map(fn ($n) => $this->formatNotificationLog($n, withPet: true)),
            'meta' => [
                'total'       => $notifications->total(),
                'currentPage' => $notifications->currentPage(),
                'lastPage'    => $notifications->lastPage(),
                'perPage'     => $notifications->perPage(),
            ],
            'totals' => [
                'total'     => (int) $totals->total,
                'sent'      => (int) $totals->sent,
                'pending'   => (int) $totals->pending,
                'failed'    => (int) $totals->failed,
                'delivered' => (int) $totals->delivered,
            ],
        ]);
    }

    public function getNotification(Request $request, string $id): JsonResponse
    {
        $this->requireAdmin($request);

        $log = NotificationLog::with('pet.owner')->findOrFail($id);

        return response()->json($this->formatNotificationLog($log, withPet: true, full: true));
    }

    public function notifyExpiry(Request $request, string $ownerId): JsonResponse
    {
        $this->requireAdmin($request);

        $data  = $request->validate([
            'type' => 'required|in:trial_expiring,subscription_expiring,test',
        ]);

        $owner          = Owner::findOrFail($ownerId);
        $sub            = OwnerSubscription::where('owner_id', $ownerId)->first();
        $devicesCount   = PushSubscription::where('owner_id', $ownerId)->count();

        if ($devicesCount === 0) {
            return response()->json([
                'ok'      => false,
                'sent'    => 0,
                'devices' => 0,
                'message' => 'Este propietario no tiene notificaciones push activadas. Debe activarlas desde su cuenta en roke.pet (Ajustes → Notificaciones).',
            ]);
        }

        // Build message based on type
        [$title, $body] = match ($data['type']) {
            'trial_expiring' => [
                '⏳ Tu período de prueba está por terminar',
                $sub?->trial_ends_at
                    ? 'Tu trial de roke.pet vence el ' . \Carbon\Carbon::parse($sub->trial_ends_at)->translatedFormat('j \d\e F') . '. ¡Activa tu suscripción para no perder el acceso!'
                    : 'Tu período de prueba está próximo a vencer. ¡Activa tu plan para continuar!',
            ],
            'subscription_expiring' => [
                '🔔 Tu suscripción vence pronto',
                $sub?->current_period_end
                    ? 'Tu suscripción de roke.pet vence el ' . \Carbon\Carbon::parse($sub->current_period_end)->translatedFormat('j \d\e F') . '. Renueva para mantener el acceso.'
                    : 'Tu suscripción está próxima a vencer. Renueva para no perder el acceso.',
            ],
            'test' => [
                '🐾 Notificación de prueba',
                'Esta es una notificación de prueba desde el panel de ROKE Pet. ¡Todo funciona correctamente!',
            ],
        };

        $payload = ['data' => ['type' => $data['type'], 'url' => '/billing']];

        $log = NotificationLog::create([
            'project_id'        => 'roke_pet',
            'owner_id'          => $ownerId,
            'channel'           => 'push',
            'provider'          => 'webpush',
            'notification_type' => $data['type'],
            'title'             => $title,
            'body'              => $body,
            'payload'           => $payload,
            'status'            => 'pending',
            'max_attempts'      => 1,
        ]);

        try {
            $result = (new PushNotificationService())->sendToOwnerDetailed(
                $ownerId,
                $title,
                $body,
                $payload['data'],
            );

            if ($result['sent'] > 0) {
                $log->markSent();
                InboxNotification::createForOwner(
                    ownerId:   $ownerId,
                    title:     $title,
                    body:      $body,
                    notifType: $data['type'],
                    url:       '/billing',
                    tag:       'admin-' . $data['type'] . '-' . now()->timestamp,
                );
                return response()->json([
                    'ok'      => true,
                    'sent'    => $result['sent'],
                    'devices' => $devicesCount,
                    'message' => "✓ Notificación entregada a {$result['sent']} de {$devicesCount} dispositivo(s)",
                ]);
            }

            if ($result['expired'] > 0 && $result['failed'] === 0) {
                // Todas las suscripciones estaban expiradas — ya se limpiaron automáticamente
                $log->markFailed('expired', "Las {$result['expired']} suscripciones estaban expiradas y se eliminaron");
                return response()->json([
                    'ok'      => false,
                    'sent'    => 0,
                    'devices' => $devicesCount,
                    'expired' => $result['expired'],
                    'message' => "La suscripción push estaba expirada. El propietario debe reactivar las notificaciones desde su cuenta en roke.pet.",
                ]);
            }

            $errorDetail = !empty($result['errors']) ? implode('; ', $result['errors']) : 'sin detalle';
            $log->markFailed('delivery_failed', "sent={$result['sent']} expired={$result['expired']} failed={$result['failed']} errors={$errorDetail}");
            return response()->json([
                'ok'      => false,
                'sent'    => 0,
                'devices' => $devicesCount,
                'message' => "Entrega fallida ({$result['failed']} error(es)). Detalle: {$errorDetail}",
            ]);
        } catch (\Throwable $e) {
            $log->markFailed('exception', substr($e->getMessage(), 0, 500));
            return response()->json([
                'ok'      => false,
                'sent'    => 0,
                'devices' => $devicesCount,
                'message' => 'Error al enviar: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function retryNotification(Request $request, string $id): JsonResponse
    {
        $this->requireAdmin($request);

        $log = NotificationLog::findOrFail($id);

        if (!$log->isRetryable()) {
            return response()->json([
                'ok'      => false,
                'message' => 'Esta notificación no se puede reintentar (ya entregada, agotada o en proceso).',
            ], 422);
        }

        RetryNotificationJob::dispatch($log->id);

        return response()->json(['ok' => true, 'message' => 'Reintento encolado correctamente.']);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function formatPetListItem(Pet $pet): array
    {
        return [
            'id'                   => $pet->id,
            'name'                 => $pet->name,
            'slug'                 => $pet->slug,
            'species'              => $pet->species,
            'breed'                => $pet->breed,
            'gender'               => $pet->gender,
            'scannedCount'         => $pet->scanned_count,
            'nfcId'                => $pet->nfc_id,
            'photoUrl'             => $pet->photo_url,
            'publicProfileEnabled' => $pet->public_profile_enabled,
            'isLost'               => $pet->is_lost,
            'lostSince'            => $pet->lost_since,
            'createdAt'            => $pet->created_at,
            'ownerId'              => $pet->owner_id,
            'ownerName'            => $pet->owner?->display_name ?? 'Sin nombre',
            'ownerEmail'           => $pet->owner?->email ?? '',
        ];
    }

    private function formatNotificationLog(NotificationLog $n, bool $withPet = false, bool $full = false): array
    {
        $result = [
            'id'                => $n->id,
            'petId'             => $n->pet_id,
            'ownerId'           => $n->owner_id,
            'channel'           => $n->channel,
            'provider'          => $n->provider,
            'notificationType'  => $n->notification_type,
            'title'             => $n->title,
            'body'              => $n->body,
            'status'            => $n->status,
            'attempts'          => $n->attempts,
            'maxAttempts'       => $n->max_attempts,
            'errorCode'         => $n->error_code,
            'errorMessage'      => $n->error_message,
            'sentAt'            => $n->sent_at,
            'failedAt'          => $n->failed_at,
            'lastAttemptAt'     => $n->last_attempt_at,
            'nextRetryAt'       => $n->next_retry_at,
            'createdAt'         => $n->created_at,
            'isRetryable'       => $n->isRetryable(),
        ];

        if ($full) {
            $result['payload']      = $n->payload;
            $result['providerMessageId'] = $n->provider_message_id;
        }

        if ($withPet && $n->pet) {
            $result['petName']  = $n->pet->name;
            $result['petSlug']  = $n->pet->slug;
            $result['ownerName'] = $n->pet->owner?->display_name ?? null;
        }

        return $result;
    }

    private function requireAdmin(Request $request): void
    {
        if (!AppAdmin::where('user_id', $request->user()->uuid)->exists()) {
            abort(403, 'Acceso denegado');
        }
    }
}
