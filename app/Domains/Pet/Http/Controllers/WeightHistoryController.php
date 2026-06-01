<?php

namespace App\Domains\Pet\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Domains\Pet\Models\Pet;
use App\Domains\Pet\Models\WeightHistory;
use App\Domains\Pet\Support\GatesPlanFeatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class WeightHistoryController extends Controller
{
    use GatesPlanFeatures;

    public function index(Request $request, string $petId): JsonResponse
    {
        $this->ownerPet($request, $petId);

        $entries = WeightHistory::where('pet_id', $petId)
            ->orderBy('recorded_at', 'desc')
            ->get();

        return response()->json($entries->map(fn($e) => $this->format($e)));
    }

    public function store(Request $request, string $petId): JsonResponse
    {
        if ($r = $this->requirePlanFeature($request->user()->uuid, 'weight_tracking')) return $r;

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

    public function uploadPhoto(Request $request, string $id): JsonResponse
    {
        $entry = WeightHistory::whereHas('pet', fn($q) => $q->where('owner_id', $request->user()->uuid))
            ->where('id', $id)
            ->firstOrFail();

        $request->validate(['photo' => 'required|image|max:5120']);

        if ($entry->photo_url) {
            Storage::disk('public')->delete($entry->photo_url);
        }

        $path = $request->file('photo')->store("weight-photos/{$entry->id}", 'public');
        $entry->update(['photo_url' => $path]);

        return response()->json([
            'photoUrl' => Storage::disk('public')->url($path),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $entry = WeightHistory::whereHas('pet', fn($q) => $q->where('owner_id', $request->user()->uuid))
            ->where('id', $id)
            ->firstOrFail();

        if ($entry->photo_url) {
            Storage::disk('public')->delete($entry->photo_url);
        }

        $entry->delete();
        return response()->json(['ok' => true]);
    }

    private function ownerPet(Request $request, string $petId): Pet
    {
        return Pet::where('id', $petId)->where('owner_id', $request->user()->uuid)->firstOrFail();
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
            'photoUrl'   => $entry->photo_url
                ? Storage::disk('public')->url($entry->photo_url)
                : null,
            'createdAt'  => $entry->created_at,
        ];
    }
}
