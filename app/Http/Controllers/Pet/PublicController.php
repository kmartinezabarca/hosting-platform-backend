<?php

namespace App\Http\Controllers\Pet;

use App\Http\Controllers\Controller;
use App\Models\Pet\ActivationEvent;
use App\Models\Pet\InboxNotification;
use App\Models\Pet\NotificationLog;
use App\Models\Pet\Owner;
use App\Models\Pet\Pet;
use App\Models\Pet\PetScanEvent;
use App\Services\Pet\PushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicController extends Controller
{
    public function petBySlug(string $slug): JsonResponse
    {
        $pet = Pet::where('slug', $slug)->where('public_profile_enabled', true)
            ->with(['vaccines', 'medicalRecords'])->first();

        if (!$pet) {
            return response()->json(['error' => 'Mascota no encontrada'], 404);
        }

        $owner = Owner::find($pet->owner_id);
        return response()->json((new PetController())->format($pet, $owner, true));
    }

    public function recordScan(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'source'               => 'required|in:nfc,qr,direct',
            'lat'                  => 'nullable|numeric|between:-90,90',
            'lng'                  => 'nullable|numeric|between:-180,180',
            'accuracy'             => 'nullable|numeric|min:0',
            'shareLocationAllowed' => 'nullable|boolean',
            'city'                 => 'nullable|string|max:100',
            'country'              => 'nullable|string|max:100',
            'deviceType'           => 'nullable|string|max:50',
        ]);

        $pet = Pet::where('slug', $slug)->where('public_profile_enabled', true)->first();

        if (!$pet) {
            return response()->json(['ok' => false, 'count' => 0], 404);
        }

        $shareLocation = (bool) ($data['shareLocationAllowed'] ?? false);
        $hasCoords     = $shareLocation && isset($data['lat'], $data['lng']);

        // Guardar evento detallado de escaneo
        PetScanEvent::create([
            'pet_id'                 => $pet->id,
            'scanned_at'             => now(),
            'source'                 => $data['source'],
            'ip_address'             => $request->ip(),
            'user_agent'             => substr($request->userAgent() ?? '', 0, 500),
            'share_location_allowed' => $shareLocation,
            'latitude'               => $hasCoords ? $data['lat'] : null,
            'longitude'              => $hasCoords ? $data['lng'] : null,
            'accuracy'               => $hasCoords ? ($data['accuracy'] ?? null) : null,
            'city'                   => $data['city'] ?? null,
            'country'                => $data['country'] ?? null,
            'device_type'            => $data['deviceType'] ?? null,
        ]);

        // Actualizar contador y última ubicación de escaneo en pets
        $count = ($pet->scanned_count ?? 0) + 1;
        $scanLocation = $hasCoords ? [
            'lat'       => round((float) $data['lat'], 2),
            'lng'       => round((float) $data['lng'], 2),
            'city'      => $data['city'] ?? null,
            'timestamp' => now()->toISOString(),
        ] : null;

        $pet->update([
            'scanned_count'      => $count,
            'last_scan_location' => $scanLocation ?? $pet->last_scan_location,
        ]);

        // Log en activation_events (para analytics globales)
        ActivationEvent::create([
            'owner_id'    => $pet->owner_id,
            'pet_id'      => $pet->id,
            'event_type'  => 'pet_scan_recorded',
            'source'      => $data['source'],
            'metadata'    => [
                'count'   => $count,
                'city'    => $data['city'] ?? null,
                'is_lost' => $pet->is_lost,
            ],
            'occurred_at' => now(),
        ]);

        // Si la mascota está perdida, notificar al owner
        if ($pet->is_lost) {
            $this->notifyOwnerOnLostScan($pet, $data, $hasCoords);
        }

        return response()->json(['ok' => true, 'count' => $count, 'isLost' => $pet->is_lost]);
    }

    /**
     * "Encontré a esta mascota" — relay anónimo al dueño.
     *
     * Funciona aunque la mascota NO esté marcada como perdida (el dueño puede no
     * saber aún que se perdió) y NUNCA expone el contacto del dueño a quien la
     * encontró: solo le avisa (push + inbox) con la ubicación/mensaje opcional.
     */
    public function reportFound(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'lat'                  => 'nullable|numeric|between:-90,90',
            'lng'                  => 'nullable|numeric|between:-180,180',
            'accuracy'             => 'nullable|numeric|min:0',
            'shareLocationAllowed' => 'nullable|boolean',
            'city'                 => 'nullable|string|max:100',
            'country'              => 'nullable|string|max:100',
            'message'              => 'nullable|string|max:500',
            'finderContact'        => 'nullable|string|max:120',
        ]);

        $pet = Pet::where('slug', $slug)->where('public_profile_enabled', true)->first();
        if (!$pet) {
            return response()->json(['ok' => false], 404);
        }

        $shareLocation = (bool) ($data['shareLocationAllowed'] ?? false);
        $hasCoords     = $shareLocation && isset($data['lat'], $data['lng']);

        // Registrar el reporte como evento de escaneo (con ubicación si se compartió).
        PetScanEvent::create([
            'pet_id'                 => $pet->id,
            'scanned_at'             => now(),
            'source'                 => 'direct',
            'ip_address'             => $request->ip(),
            'user_agent'             => substr($request->userAgent() ?? '', 0, 500),
            'share_location_allowed' => $shareLocation,
            'latitude'               => $hasCoords ? $data['lat'] : null,
            'longitude'              => $hasCoords ? $data['lng'] : null,
            'accuracy'               => $hasCoords ? ($data['accuracy'] ?? null) : null,
            'city'                   => $data['city'] ?? null,
            'country'                => $data['country'] ?? null,
            'device_type'            => 'found_report',
        ]);

        if ($hasCoords) {
            $pet->update([
                'last_scan_location' => [
                    'lat'       => round((float) $data['lat'], 2),
                    'lng'       => round((float) $data['lng'], 2),
                    'city'      => $data['city'] ?? null,
                    'timestamp' => now()->toISOString(),
                ],
            ]);
        }

        ActivationEvent::create([
            'owner_id'    => $pet->owner_id,
            'pet_id'      => $pet->id,
            'event_type'  => 'pet_found_report',
            'source'      => 'direct',
            'metadata'    => [
                'city'        => $data['city'] ?? null,
                'has_coords'  => $hasCoords,
                'has_message' => !empty($data['message']),
                'is_lost'     => $pet->is_lost,
            ],
            'occurred_at' => now(),
        ]);

        $this->notifyOwnerOnFound($pet, $data, $hasCoords);

        // Respuesta SIN datos del dueño.
        return response()->json(['ok' => true]);
    }

    private function notifyOwnerOnFound(Pet $pet, array $data, bool $hasCoords): void
    {
        $title = "🐾 ¡Alguien encontró a {$pet->name}!";
        $body  = "Alguien escaneó el tag de {$pet->name}";
        if ($hasCoords && !empty($data['city'])) {
            $body .= " en {$data['city']}";
        }
        $body .= '.';
        if (!empty($data['message'])) {
            $body .= ' Mensaje: "' . $data['message'] . '"';
        }
        if (!empty($data['finderContact'])) {
            $body .= ' Contacto: ' . $data['finderContact'];
        }

        $payload = [
            'data' => [
                'type'    => 'pet_found_report',
                'petId'   => $pet->id,
                'petSlug' => $pet->slug,
                'city'    => $data['city'] ?? null,
            ],
        ];

        $log = NotificationLog::create([
            'project_id'        => 'roke_pet',
            'pet_id'            => $pet->id,
            'owner_id'          => $pet->owner_id,
            'channel'           => 'push',
            'provider'          => 'webpush',
            'notification_type' => 'pet_found_report',
            'title'             => $title,
            'body'              => $body,
            'payload'           => $payload,
            'status'            => 'pending',
            'max_attempts'      => 3,
        ]);

        try {
            $sent = (new PushNotificationService())->sendToOwner(
                $pet->owner_id,
                $title,
                $body,
                $payload['data'],
            );

            $sent > 0 ? $log->markSent() : $log->markFailed('no_subscriptions', 'No active push subscriptions for owner');
        } catch (\Throwable $e) {
            $log->markFailed('exception', substr($e->getMessage(), 0, 500));
        }

        // El inbox SIEMPRE se crea (aunque no haya push activo) para que el dueño lo vea al entrar.
        try {
            InboxNotification::createForOwner(
                ownerId:   $pet->owner_id,
                title:     $title,
                body:      $body,
                notifType: 'pet_found_report',
                url:       '/lost/' . $pet->slug,
                tag:       'found-' . $pet->id,
            );
        } catch (\Throwable) {
            // no fatal
        }
    }

    private function notifyOwnerOnLostScan(Pet $pet, array $data, bool $hasCoords): void
    {
        $title   = "🐾 ¡Escanearon a {$pet->name}!";
        $body    = "Alguien escaneó a {$pet->name} hace unos segundos";
        if ($hasCoords && !empty($data['city'])) {
            $body .= " en {$data['city']}";
        }
        $body .= '.';

        $payload = [
            'data' => [
                'type'    => 'lost_pet_scan',
                'petId'   => $pet->id,
                'petSlug' => $pet->slug,
                'city'    => $data['city'] ?? null,
            ],
        ];

        $log = NotificationLog::create([
            'project_id'        => 'roke_pet',
            'pet_id'            => $pet->id,
            'owner_id'          => $pet->owner_id,
            'channel'           => 'push',
            'provider'          => 'webpush',
            'notification_type' => 'lost_pet_scan',
            'title'             => $title,
            'body'              => $body,
            'payload'           => $payload,
            'status'            => 'pending',
            'max_attempts'      => 3,
        ]);

        try {
            $sent = (new PushNotificationService())->sendToOwner(
                $pet->owner_id,
                $title,
                $body,
                $payload['data'],
            );

            if ($sent > 0) {
                $log->markSent();
                InboxNotification::createForOwner(
                    ownerId:   $pet->owner_id,
                    title:     $title,
                    body:      $body,
                    notifType: 'lost_pet_scan',
                    url:       '/lost/' . $pet->slug,
                    tag:       'lost-scan-' . $pet->id,
                );
            } else {
                $log->markFailed('no_subscriptions', 'No active push subscriptions for owner');
            }
        } catch (\Throwable $e) {
            $log->markFailed('exception', substr($e->getMessage(), 0, 500));
            // Nunca fallar el scan por un error de notificación
        }
    }
}
