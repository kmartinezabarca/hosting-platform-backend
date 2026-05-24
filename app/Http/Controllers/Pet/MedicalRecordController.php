<?php

namespace App\Http\Controllers\Pet;

use App\Http\Controllers\Controller;
use App\Models\Pet\MedicalRecord;
use App\Models\Pet\Pet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MedicalRecordController extends Controller
{
    public function store(Request $request, string $petId): JsonResponse
    {
        $pet = Pet::where('id', $petId)->where('owner_id', $request->user()->uuid)->firstOrFail();

        $data = $request->validate([
            'date'          => 'required|date',
            'followUpDate'  => 'nullable|date',
            'type'          => 'required|in:checkup,surgery,treatment,deworming,illness,vaccination,study,emergency,other',
            'description'   => 'nullable|string',
            'descriptionEn' => 'nullable|string',
            'vet'           => 'nullable|string|max:255',
            'vetLicense'    => 'nullable|string|max:255',
            'clinic'        => 'nullable|string|max:255',
            'notes'         => 'nullable|string',
        ]);

        $record = $pet->medicalRecords()->create([
            'date'           => $data['date'],
            'follow_up_date' => $data['followUpDate'] ?? null,
            'type'           => $data['type'],
            'description'    => $data['description'] ?? null,
            'description_en' => $data['descriptionEn'] ?? null,
            'vet'            => $data['vet'] ?? null,
            'vet_license'    => $data['vetLicense'] ?? null,
            'clinic'         => $data['clinic'] ?? null,
            'notes'          => $data['notes'] ?? null,
        ]);

        return response()->json(self::formatRecord($record), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $record = MedicalRecord::findOrFail($id);
        Pet::where('id', $record->pet_id)->where('owner_id', $request->user()->uuid)->firstOrFail();

        $data = $request->validate([
            'date'          => 'sometimes|date',
            'followUpDate'  => 'sometimes|nullable|date',
            'type'          => 'sometimes|in:checkup,surgery,treatment,deworming,illness,vaccination,study,emergency,other',
            'description'   => 'sometimes|nullable|string',
            'descriptionEn' => 'sometimes|nullable|string',
            'vet'           => 'sometimes|nullable|string',
            'vetLicense'    => 'sometimes|nullable|string',
            'clinic'        => 'sometimes|nullable|string',
            'notes'         => 'sometimes|nullable|string',
        ]);

        $record->update([
            'date'           => $data['date'] ?? $record->date,
            'follow_up_date' => $data['followUpDate'] ?? $record->follow_up_date,
            'type'           => $data['type'] ?? $record->type,
            'description'    => $data['description'] ?? $record->description,
            'description_en' => $data['descriptionEn'] ?? $record->description_en,
            'vet'            => $data['vet'] ?? $record->vet,
            'vet_license'    => $data['vetLicense'] ?? $record->vet_license,
            'clinic'         => $data['clinic'] ?? $record->clinic,
            'notes'          => $data['notes'] ?? $record->notes,
        ]);

        return response()->json(self::formatRecord($record->fresh()));
    }

    public function uploadPhoto(Request $request, string $id): JsonResponse
    {
        $record = MedicalRecord::findOrFail($id);
        Pet::where('id', $record->pet_id)->where('owner_id', $request->user()->uuid)->firstOrFail();

        $request->validate(['photo' => 'required|image|max:8192']);

        if ($record->photo_url) {
            Storage::disk('public')->delete($record->photo_url);
        }

        $path = $request->file('photo')->store("record-photos/{$record->id}", 'public');
        $record->update(['photo_url' => $path]);

        return response()->json([
            'photoUrl' => Storage::disk('public')->url($path),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $record = MedicalRecord::findOrFail($id);
        Pet::where('id', $record->pet_id)->where('owner_id', $request->user()->uuid)->firstOrFail();

        if ($record->photo_url) {
            Storage::disk('public')->delete($record->photo_url);
        }

        $record->delete();
        return response()->json(['ok' => true]);
    }

    public static function formatRecord(MedicalRecord $r): array
    {
        return [
            'id'            => $r->id,
            'date'          => $r->date ?? '',
            'followUpDate'  => $r->follow_up_date,
            'type'          => $r->type,
            'description'   => $r->description ?? '',
            'descriptionEn' => $r->description_en ?? '',
            'vet'           => $r->vet ?? '',
            'vetLicense'    => $r->vet_license ?? '',
            'clinic'        => $r->clinic ?? '',
            'notes'         => $r->notes ?? '',
            'photoUrl'      => $r->photo_url
                ? Storage::disk('public')->url($r->photo_url)
                : null,
        ];
    }
}
