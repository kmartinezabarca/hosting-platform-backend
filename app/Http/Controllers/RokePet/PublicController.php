<?php

namespace App\Http\Controllers\RokePet;

use App\Http\Controllers\Controller;
use App\Models\RokePet\ActivationEvent;
use App\Models\RokePet\Owner;
use App\Models\RokePet\Pet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicController extends Controller
{
    public function petBySlug(string $slug): JsonResponse
    {
        $pet = Pet::where('slug', $slug)
            ->where('public_profile_enabled', true)
            ->with(['vaccines', 'medicalRecords'])
            ->first();

        if (!$pet) {
            return response()->json(['error' => 'Mascota no encontrada'], 404);
        }

        $owner = Owner::find($pet->owner_id);

        $petController = new PetController();
        return response()->json($petController->format($pet, $owner));
    }

    public function recordScan(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'source'  => 'required|in:nfc,qr,direct',
            'lat'     => 'nullable|numeric',
            'lng'     => 'nullable|numeric',
            'address' => 'nullable|string|max:500',
        ]);

        $pet = Pet::where('slug', $slug)
            ->where('public_profile_enabled', true)
            ->first();

        if (!$pet) {
            return response()->json(['ok' => false, 'count' => 0], 404);
        }

        $location = null;
        if (isset($data['lat'], $data['lng'])) {
            $location = [
                'lat'       => $data['lat'],
                'lng'       => $data['lng'],
                'address'   => $data['address'] ?? null,
                'timestamp' => now()->toISOString(),
            ];
        }

        $count = ($pet->scanned_count ?? 0) + 1;

        $pet->update([
            'scanned_count'      => $count,
            'last_scan_location' => $location ?? $pet->last_scan_location,
        ]);

        ActivationEvent::create([
            'owner_id'    => $pet->owner_id,
            'pet_id'      => $pet->id,
            'event_type'  => 'pet_scan_recorded',
            'source'      => $data['source'],
            'metadata'    => ['count' => $count, 'address' => $data['address'] ?? null],
            'occurred_at' => now(),
        ]);

        return response()->json(['ok' => true, 'count' => $count]);
    }
}
