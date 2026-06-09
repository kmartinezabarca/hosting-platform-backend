<?php

namespace App\Domains\Pet\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Domains\Pet\Models\Pet;
use App\Domains\Pet\Models\PetScanEvent;
use App\Domains\Pet\Support\GatesPlanFeatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LostController extends Controller
{
    use GatesPlanFeatures;

    /** POST /my-pets/{id}/lost — marcar mascota como perdida */
    public function markLost(Request $request, string $id): JsonResponse
    {
        $pet = Pet::where('id', $id)->where('owner_id', $request->user()->uuid)->firstOrFail();

        $data = $request->validate([
            'description'              => 'nullable|string|max:1000',
            'lastSeenLat'              => 'nullable|numeric|between:-90,90',
            'lastSeenLng'              => 'nullable|numeric|between:-180,180',
            'lastSeenAddress'          => 'nullable|string|max:500',
            'emergencyContactOverride' => 'nullable|string|max:255',
            'lostBannerEnabled'        => 'nullable|boolean',
        ]);

        $lastSeen = (isset($data['lastSeenLat'], $data['lastSeenLng'])) ? [
            'lat'       => round((float) $data['lastSeenLat'], 2),
            'lng'       => round((float) $data['lastSeenLng'], 2),
            'address'   => $data['lastSeenAddress'] ?? null,
            'timestamp' => now()->toISOString(),
        ] : null;

        $pet->update([
            'is_lost'                   => true,
            'lost_since'                => $pet->lost_since ?? now(),
            'lost_description'          => $data['description'] ?? $pet->lost_description,
            'last_seen_location'        => $lastSeen ?? $pet->last_seen_location,
            'emergency_contact_override' => $data['emergencyContactOverride'] ?? $pet->emergency_contact_override,
            'lost_banner_enabled'       => $data['lostBannerEnabled'] ?? true,
        ]);

        return response()->json(['ok' => true, 'isLost' => true]);
    }

    /** DELETE /my-pets/{id}/lost — marcar mascota como encontrada */
    public function markFound(Request $request, string $id): JsonResponse
    {
        $pet = Pet::where('id', $id)->where('owner_id', $request->user()->uuid)->firstOrFail();

        $pet->update([
            'is_lost'                   => false,
            'lost_since'                => null,
            'lost_description'          => null,
            'last_seen_location'        => null,
            'emergency_contact_override' => null,
            'lost_banner_enabled'       => true,
        ]);

        return response()->json(['ok' => true, 'isLost' => false]);
    }

    /** GET /my-pets/{id}/scan-history — historial de escaneos (solo owner) */
    public function scanHistory(Request $request, string $id): JsonResponse
    {
        $pet = Pet::where('id', $id)->where('owner_id', $request->user()->uuid)->firstOrFail();

        $scans = PetScanEvent::where('pet_id', $pet->id)
            ->orderBy('scanned_at', 'desc')
            ->paginate(50);

        return response()->json([
            'data' => $scans->map(fn($s) => $this->formatScan($s, private: true)),
            'meta' => [
                'total'       => $scans->total(),
                'currentPage' => $scans->currentPage(),
                'lastPage'    => $scans->lastPage(),
            ],
        ]);
    }

    /** GET /my-pets/{id}/scan-analytics — analytics de escaneos (solo owner) */
    public function scanAnalytics(Request $request, string $id): JsonResponse
    {
        if ($r = $this->requirePlanFeature($request->user()->uuid, 'scan_analytics')) return $r;

        $pet = Pet::where('id', $id)->where('owner_id', $request->user()->uuid)->firstOrFail();

        $scans = PetScanEvent::where('pet_id', $pet->id);

        $bySource = (clone $scans)
            ->selectRaw('source, COUNT(*) as total')
            ->groupBy('source')
            ->pluck('total', 'source');

        $byCity = (clone $scans)
            ->whereNotNull('city')
            ->selectRaw('city, COUNT(*) as total')
            ->groupBy('city')
            ->orderByDesc('total')
            ->limit(10)
            ->pluck('total', 'city');

        $byDay = (clone $scans)
            ->selectRaw('DATE(scanned_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->orderBy('day', 'desc')
            ->limit(30)
            ->get()
            ->mapWithKeys(fn($r) => [$r->day => (int) $r->total]);

        $lastScan = (clone $scans)->orderBy('scanned_at', 'desc')->first();

        return response()->json([
            'totalScans'   => $pet->scanned_count,
            'bySource'     => $bySource,
            'byCity'       => $byCity,
            'last30Days'   => $byDay,
            'lastScan'     => $lastScan ? $this->formatScan($lastScan, private: true) : null,
        ]);
    }

    /** GET /my-pets/{id}/lost-poster — datos para generar cartel (solo owner) */
    public function lostPoster(Request $request, string $id): JsonResponse
    {
        $pet = Pet::where('id', $id)->where('owner_id', $request->user()->uuid)
            ->with('owner')
            ->firstOrFail();

        return response()->json($this->buildPosterData($pet, $pet->owner));
    }

    /** GET /pets/{slug}/lost-poster — datos de cartel públicos (solo si is_lost=true) */
    public function publicLostPoster(string $slug): JsonResponse
    {
        $pet = Pet::where('slug', $slug)
            ->where('public_profile_enabled', true)
            ->where('is_lost', true)
            ->with('owner')
            ->first();

        if (!$pet) {
            return response()->json(['error' => 'No se encontró mascota perdida con ese slug'], 404);
        }

        return response()->json($this->buildPosterData($pet, $pet->owner, public: true));
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function buildPosterData(Pet $pet, $owner, bool $public = false): array
    {
        $lastScan = PetScanEvent::where('pet_id', $pet->id)
            ->where('share_location_allowed', true)
            ->orderBy('scanned_at', 'desc')
            ->first();

        $emergencyContact = $pet->emergency_contact_override
            ?? ($owner?->emergency_contact ?? '');
        $emergencyPhone = $pet->emergency_contact_override
            ? ''
            : ($owner?->emergency_phone ?? '');
        $contactPhone = $owner?->phone ?? '';

        return [
            'petName'         => $pet->name,
            'species'         => $pet->species,
            'breed'           => $pet->breed ?? '',
            'color'           => $pet->color ?? '',
            'gender'          => $pet->gender ?? '',
            'photoUrl'        => $pet->photo_url,
            'description'     => $pet->lost_description ?? '',
            'lostSince'       => $pet->lost_since?->toISOString(),
            'lastSeenZone'    => $pet->last_seen_location,
            'lastScanZone'    => $lastScan?->approximateLocation(),
            'lastScanAt'      => $lastScan?->scanned_at?->toISOString(),
            'publicUrl'       => config('services.rokepet.frontend_url') . '/pet/' . $pet->slug,
            'slug'            => $pet->slug,
            // El cartel público muestra el teléfono para que quien la encuentre pueda llamar directo.
            'contactPhone'    => $contactPhone,
            'emergencyContact' => $emergencyContact,
            'emergencyPhone'  => $emergencyPhone,
            'ownerName'       => $owner?->display_name ?? '',
        ];
    }

    private function formatScan(PetScanEvent $scan, bool $private = false): array
    {
        $base = [
            'id'          => $scan->id,
            'source'      => $scan->source,
            'scannedAt'   => $scan->scanned_at?->toISOString(),
            'deviceType'  => $scan->device_type,
            'city'        => $scan->city,
            'country'     => $scan->country,
            'hasLocation' => $scan->share_location_allowed && $scan->latitude !== null,
            'approximate' => $scan->approximateLocation(),
        ];

        if ($private) {
            $base['ipAddress']           = $scan->ip_address;
            $base['userAgent']           = $scan->user_agent;
            $base['shareLocationAllowed'] = $scan->share_location_allowed;
            $base['latitude']            = $scan->latitude;
            $base['longitude']           = $scan->longitude;
            $base['accuracy']            = $scan->accuracy;
        }

        return $base;
    }
}
