<?php

namespace App\Http\Controllers\RokePet;

use App\Http\Controllers\Controller;
use App\Models\RokePet\MedicalRecord;
use App\Models\RokePet\Owner;
use App\Models\RokePet\Pet;
use App\Models\RokePet\Vaccine;
use App\Models\RokePet\VetLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VetLinkController extends Controller
{
    public function index(Request $request, string $petId): JsonResponse
    {
        $pet = Pet::where('id', $petId)
            ->where('owner_id', $request->user()->uuid)
            ->firstOrFail();

        $links = VetLink::where('pet_id', $pet->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($l) => $this->formatLink($l));

        return response()->json($links);
    }

    public function revoke(Request $request, string $petId, string $linkId): JsonResponse
    {
        $pet = Pet::where('id', $petId)
            ->where('owner_id', $request->user()->uuid)
            ->firstOrFail();

        $link = VetLink::where('id', $linkId)
            ->where('pet_id', $pet->id)
            ->firstOrFail();

        // Expire immediately by setting expires_at to now
        $link->update(['expires_at' => now()->subSecond()]);

        return response()->json(['ok' => true]);
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

    public function generate(Request $request, string $petId): JsonResponse
    {
        $pet = Pet::where('id', $petId)
            ->where('owner_id', $request->user()->uuid)
            ->firstOrFail();

        $data = $request->validate([
            'expiresInHours'  => 'sometimes|integer|min:1|max:720',
            'allowAddRecords' => 'sometimes|boolean',
        ]);

        $hours     = $data['expiresInHours'] ?? 72;
        $token     = Str::random(32);
        $expiresAt = now()->addHours($hours);

        $link = VetLink::create([
            'pet_id'           => $pet->id,
            'owner_id'         => $request->user()->uuid,
            'token'            => $token,
            'expires_at'       => $expiresAt,
            'allow_add_records'=> $data['allowAddRecords'] ?? true,
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

        $petController = new PetController();

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
            'pet' => $petController->format($pet, $owner),
        ]);
    }

    public function addRecord(Request $request, string $token): JsonResponse
    {
        $link = VetLink::where('token', $token)
            ->where('allow_add_records', true)
            ->firstOrFail();

        if ($link->isExpired()) {
            return response()->json(['error' => 'Link expirado'], 410);
        }

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

        return response()->json(MedicalRecordController::formatRecord($record), 201);
    }

    public function addVaccine(Request $request, string $token): JsonResponse
    {
        $link = VetLink::where('token', $token)
            ->where('allow_add_records', true)
            ->firstOrFail();

        if ($link->isExpired()) {
            return response()->json(['error' => 'Link expirado'], 410);
        }

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

        return response()->json(VaccineController::formatVaccine($vaccine), 201);
    }
}
