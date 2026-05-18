<?php

namespace App\Http\Controllers\RokePet;

use App\Http\Controllers\Controller;
use App\Models\RokePet\Pet;
use App\Models\RokePet\WeightHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WeightHistoryController extends Controller
{
    /** GET /my-pets/{petId}/weight */
    public function index(Request $request, string $petId): JsonResponse
    {
        $this->ownerPet($request, $petId); // throws 404 if not owned

        $entries = WeightHistory::where('pet_id', $petId)
            ->orderBy('recorded_at', 'desc')
            ->get();

        return response()->json($entries->map(fn($e) => $this->format($e)));
    }

    /** POST /my-pets/{petId}/weight */
    public function store(Request $request, string $petId): JsonResponse
    {
        $this->ownerPet($request, $petId);

        $data = $request->validate([
            'weight'     => 'required|numeric|min:0|max:9999',
            'recordedAt' => 'sometimes|nullable|date',
            'notes'      => 'sometimes|nullable|string|max:1000',
        ]);

        $entry = WeightHistory::create([
            'pet_id'      => $petId,
            'weight'      => $data['weight'],
            'recorded_at' => $data['recordedAt'] ?? now()->toDateString(),
            'notes'       => $data['notes'] ?? '',
        ]);

        return response()->json($this->format($entry), 201);
    }

    /** DELETE /weight/{id} */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $ownerId = $request->user()->uuid;

        $entry = WeightHistory::whereHas('pet', fn($q) => $q->where('owner_id', $ownerId))
            ->where('id', $id)
            ->firstOrFail();

        $entry->delete();

        return response()->json(['ok' => true]);
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function ownerPet(Request $request, string $petId): Pet
    {
        return Pet::where('id', $petId)
            ->where('owner_id', $request->user()->uuid)
            ->firstOrFail();
    }

    private function format(WeightHistory $entry): array
    {
        return [
            'id'         => $entry->id,
            'petId'      => $entry->pet_id,
            'weight'     => (float) $entry->weight,
            'recordedAt' => $entry->recorded_at instanceof \Carbon\Carbon
                ? $entry->recorded_at->toDateString()
                : (string) $entry->recorded_at,
            'notes'      => $entry->notes ?? '',
            'createdAt'  => $entry->created_at,
        ];
    }
}
