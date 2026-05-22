<?php

namespace App\Http\Controllers\Pet;

use App\Http\Controllers\Controller;
use App\Models\Pet\MedicalRecord;
use App\Models\Pet\Owner;
use App\Models\Pet\Pet;
use App\Models\Pet\Vaccine;
use App\Models\Pet\VetLink;
use App\Models\Pet\WeightHistory;
use App\Services\Pet\PushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VetLinkController extends Controller
{
    public function index(Request $request, string $petId): JsonResponse
    {
        $pet   = Pet::where('id', $petId)->where('owner_id', $request->user()->uuid)->firstOrFail();
        $links = VetLink::where('pet_id', $pet->id)->orderByDesc('created_at')
            ->get()->map(fn($l) => $this->formatLink($l));
        return response()->json($links);
    }

    public function revoke(Request $request, string $petId, string $linkId): JsonResponse
    {
        $pet  = Pet::where('id', $petId)->where('owner_id', $request->user()->uuid)->firstOrFail();
        $link = VetLink::where('id', $linkId)->where('pet_id', $pet->id)->firstOrFail();
        $link->update(['expires_at' => now()->subSecond()]);
        return response()->json(['ok' => true]);
    }

    public function generate(Request $request, string $petId): JsonResponse
    {
        $pet  = Pet::where('id', $petId)->where('owner_id', $request->user()->uuid)->firstOrFail();
        $data = $request->validate([
            'expiresInHours'  => 'sometimes|integer|min:1|max:720',
            'allowAddRecords' => 'sometimes|boolean',
        ]);

        $link = VetLink::create([
            'pet_id'            => $pet->id,
            'owner_id'          => $request->user()->uuid,
            'token'             => Str::random(32),
            'expires_at'        => now()->addHours($data['expiresInHours'] ?? 72),
            'allow_add_records' => $data['allowAddRecords'] ?? true,
        ]);

        return response()->json($this->formatLink($link), 201);
    }

    public function portal(string $token): JsonResponse
    {
        $link = VetLink::where('token', $token)->firstOrFail();

        if ($link->isExpired()) {
            return response()->json(['error' => 'Link expirado'], 410);
        }

        $link->increment('view_count');
        $pet   = $link->pet()->with(['vaccines', 'medicalRecords'])->firstOrFail();
        $owner = Owner::find($pet->owner_id);

        return response()->json([
            'link' => [
                'id'             => $link->id,
                'petId'          => $link->pet_id,
                'token'          => $link->token,
                'expiresAt'      => $link->expires_at,
                'createdAt'      => $link->created_at,
                'createdBy'      => $link->owner_id,
                'allowAddRecords'=> $link->allow_add_records,
                'viewCount'      => $link->view_count,
            ],
            'pet' => (new PetController())->format($pet, $owner),
        ]);
    }

    public function weight(string $token): JsonResponse
    {
        $link = VetLink::where('token', $token)->firstOrFail();

        if ($link->isExpired()) {
            return response()->json(['error' => 'Link expirado'], 410);
        }

        $entries = WeightHistory::where('pet_id', $link->pet_id)
            ->orderBy('recorded_at', 'desc')
            ->get();

        return response()->json($entries->map(fn($entry) => $this->formatWeight($entry))->values());
    }

    public function addRecord(Request $request, string $token): JsonResponse
    {
        $link = VetLink::where('token', $token)->where('allow_add_records', true)->firstOrFail();
        if ($link->isExpired()) return response()->json(['error' => 'Link expirado'], 410);

        $data = $request->validate([
            'date'          => 'required|date',
            'followUpDate'  => 'nullable|date',
            'type'          => 'required|in:checkup,surgery,treatment,deworming,illness',
            'description'   => 'nullable|string',
            'descriptionEn' => 'nullable|string',
            'vet'           => 'nullable|string|max:255',
            'clinic'        => 'nullable|string|max:255',
            'notes'         => 'nullable|string',
        ]);

        $record = MedicalRecord::create([
            'pet_id'         => $link->pet_id,
            'date'           => $data['date'],
            'follow_up_date' => $data['followUpDate'] ?? null,
            'type'           => $data['type'],
            'description'    => $data['description'] ?? null,
            'description_en' => $data['descriptionEn'] ?? null,
            'vet'            => $data['vet'] ?? null,
            'clinic'         => $data['clinic'] ?? null,
            'notes'          => $data['notes'] ?? null,
        ]);

        $pet = Pet::find($link->pet_id);
        dispatch_after_response(function () use ($link, $pet, $data) {
            $vetName = $data['vet'] ?? null;
            $base    = $pet ? "El veterinario añadió un registro para {$pet->name}" : 'El veterinario añadió un registro médico';
            (new PushNotificationService())->sendToOwner(
                $link->owner_id,
                'Nuevo registro médico',
                $base . ($vetName ? " — {$vetName}" : ''),
                ['url' => '/dashboard/' . $link->pet_id],
            );
        });

        return response()->json(MedicalRecordController::formatRecord($record), 201);
    }

    public function addVaccine(Request $request, string $token): JsonResponse
    {
        $link = VetLink::where('token', $token)->where('allow_add_records', true)->firstOrFail();
        if ($link->isExpired()) return response()->json(['error' => 'Link expirado'], 410);

        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'nameEn'      => 'nullable|string',
            'date'        => 'nullable|date',
            'nextDue'     => 'nullable|date',
            'appliedBy'   => 'nullable|string',
            'batchNumber' => 'nullable|string',
            'status'      => 'required|in:applied,pending,overdue',
        ]);

        $vaccine = Vaccine::create([
            'pet_id'       => $link->pet_id,
            'name'         => $data['name'],
            'name_en'      => $data['nameEn'] ?? null,
            'date'         => $data['date'] ?? null,
            'next_due'     => $data['nextDue'] ?? null,
            'applied_by'   => $data['appliedBy'] ?? null,
            'batch_number' => $data['batchNumber'] ?? null,
            'status'       => $data['status'],
        ]);

        $pet = Pet::find($link->pet_id);
        dispatch_after_response(function () use ($link, $pet, $data) {
            $appliedBy = $data['appliedBy'] ?? null;
            $base      = $pet ? "Se registró la vacuna {$data['name']} para {$pet->name}" : "Se registró la vacuna {$data['name']}";
            (new PushNotificationService())->sendToOwner(
                $link->owner_id,
                'Vacuna registrada',
                $base . ($appliedBy ? " — {$appliedBy}" : ''),
                ['url' => '/dashboard/' . $link->pet_id],
            );
        });

        return response()->json(VaccineController::formatVaccine($vaccine), 201);
    }

    private function formatLink(VetLink $link): array
    {
        return [
            'id'             => $link->id,
            'petId'          => $link->pet_id,
            'token'          => $link->token,
            'expiresAt'      => $link->expires_at,
            'allowAddRecords'=> $link->allow_add_records,
            'viewCount'      => $link->view_count,
            'expired'        => $link->isExpired(),
        ];
    }

    private function formatWeight(WeightHistory $entry): array
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
