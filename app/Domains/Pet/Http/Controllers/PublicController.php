<?php

namespace App\Domains\Pet\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Domains\Pet\Events\PetScanBroadcast;
use App\Domains\Pet\Models\ActivationEvent;
use App\Domains\Pet\Models\InboxNotification;
use App\Domains\Pet\Models\NotificationLog;
use App\Domains\Pet\Models\Owner;
use App\Domains\Pet\Models\Pet;
use App\Domains\Pet\Models\PetScanEvent;
use App\Domains\Pet\Services\PushNotificationService;
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

        // Dedup: un mismo escaneo físico puede llegar dos veces — el registro
        // inmediato (sin ubicación, para avisar al dueño al instante) y el
        // enriquecimiento con ubicación tras el consentimiento, o un simple refresh.
        // Si ya hay un evento del mismo dispositivo + fuente en los últimos 2 min,
        // solo lo enriquecemos con la ubicación: sin crear evento nuevo, sin re-contar
        // y sin volver a notificar al dueño.
        $recent = PetScanEvent::where('pet_id', $pet->id)
            ->where('ip_address', $request->ip())
            ->where('source', $data['source'])
            ->where('scanned_at', '>=', now()->subMinutes(2))
            ->orderByDesc('scanned_at')
            ->first();

        if ($recent) {
            if ($hasCoords && $recent->latitude === null) {
                $recent->update([
                    'share_location_allowed' => true,
                    'latitude'               => $data['lat'],
                    'longitude'              => $data['lng'],
                    'accuracy'               => $data['accuracy'] ?? null,
                    'city'                   => $data['city'] ?? $recent->city,
                    'country'                => $data['country'] ?? $recent->country,
                ]);
                $pet->update([
                    'last_scan_location' => [
                        'lat'       => round((float) $data['lat'], 2),
                        'lng'       => round((float) $data['lng'], 2),
                        'city'      => $data['city'] ?? null,
                        'timestamp' => now()->toISOString(),
                    ],
                ]);
            }

            return response()->json(['ok' => true, 'count' => $pet->scanned_count, 'isLost' => $pet->is_lost]);
        }

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
                url:       '/dashboard/' . $pet->id,
                tag:       'found-' . $pet->id,
            );
        } catch (\Throwable) {
            // no fatal
        }

        // Tiempo real: si la app del dueño está abierta, ve el aviso al instante (Reverb).
        try {
            PetScanBroadcast::dispatch(
                $pet->owner_id, 'pet_found_report', (string) $pet->id, $pet->slug, $pet->name, $title, $body, $data['city'] ?? null,
            );
        } catch (\Throwable) {
            // best-effort, nunca rompe el flujo
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

            $sent > 0 ? $log->markSent() : $log->markFailed('no_subscriptions', 'No active push subscriptions for owner');
        } catch (\Throwable $e) {
            $log->markFailed('exception', substr($e->getMessage(), 0, 500));
            // Nunca fallar el scan por un error de notificación
        }

        // El inbox SIEMPRE se crea (aunque no haya push activo) para que el dueño
        // vea el escaneo de su mascota perdida al entrar a la app.
        try {
            InboxNotification::createForOwner(
                ownerId:   $pet->owner_id,
                title:     $title,
                body:      $body,
                notifType: 'lost_pet_scan',
                url:       '/dashboard/' . $pet->id,
                tag:       'lost-scan-' . $pet->id,
            );
        } catch (\Throwable) {
            // no fatal
        }

        // Tiempo real: si la app del dueño está abierta, ve el aviso al instante (Reverb).
        try {
            PetScanBroadcast::dispatch(
                $pet->owner_id, 'lost_pet_scan', (string) $pet->id, $pet->slug, $pet->name, $title, $body, $data['city'] ?? null,
            );
        } catch (\Throwable) {
            // best-effort, nunca rompe el flujo
        }
    }
}
