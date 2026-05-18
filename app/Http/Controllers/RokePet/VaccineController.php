<?php

namespace App\Http\Controllers\RokePet;

use App\Http\Controllers\Controller;
use App\Models\RokePet\Pet;
use App\Models\RokePet\Vaccine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VaccineController extends Controller
{
    public function store(Request $request, string $petId): JsonResponse
    {
        $pet = Pet::where('id', $petId)
            ->where('owner_id', $request->user()->uuid)
            ->firstOrFail();

        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'nameEn'      => 'nullable|string|max:255',
            'date'        => 'nullable|date',
            'nextDue'     => 'nullable|date',
            'appliedBy'   => 'nullable|string|max:255',
            'batchNumber' => 'nullable|string|max:255',
            'status'      => 'required|in:applied,pending,overdue',
        ]);

        $vaccine = $pet->vaccines()->create([
            'name'         => $data['name'],
            'name_en'      => $data['nameEn'] ?? null,
            'date'         => $data['date'] ?? null,
            'next_due'     => $data['nextDue'] ?? null,
            'applied_by'   => $data['appliedBy'] ?? null,
            'batch_number' => $data['batchNumber'] ?? null,
            'status'       => $data['status'],
        ]);

        return response()->json(self::formatVaccine($vaccine), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $vaccine = Vaccine::findOrFail($id);
        $pet     = Pet::where('id', $vaccine->pet_id)
            ->where('owner_id', $request->user()->uuid)
            ->firstOrFail();

        $data = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'nameEn'      => 'sometimes|nullable|string',
            'date'        => 'sometimes|nullable|date',
            'nextDue'     => 'sometimes|nullable|date',
            'appliedBy'   => 'sometimes|nullable|string',
            'batchNumber' => 'sometimes|nullable|string',
            'status'      => 'sometimes|in:applied,pending,overdue',
        ]);

        $vaccine->update([
            'name'         => $data['name'] ?? $vaccine->name,
            'name_en'      => $data['nameEn'] ?? $vaccine->name_en,
            'date'         => $data['date'] ?? $vaccine->date,
            'next_due'     => $data['nextDue'] ?? $vaccine->next_due,
            'applied_by'   => $data['appliedBy'] ?? $vaccine->applied_by,
            'batch_number' => $data['batchNumber'] ?? $vaccine->batch_number,
            'status'       => $data['status'] ?? $vaccine->status,
        ]);

        return response()->json(self::formatVaccine($vaccine->fresh()));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $vaccine = Vaccine::findOrFail($id);
        Pet::where('id', $vaccine->pet_id)
            ->where('owner_id', $request->user()->uuid)
            ->firstOrFail();

        $vaccine->delete();
        return response()->json(['ok' => true]);
    }

    public static function formatVaccine(Vaccine $v): array
    {
        return [
            'id'          => $v->id,
            'name'        => $v->name,
            'nameEn'      => $v->name_en ?? $v->name,
            'date'        => $v->date ?? '',
            'nextDue'     => $v->next_due,
            'appliedBy'   => $v->applied_by ?? '',
            'batchNumber' => $v->batch_number ?? '',
            'status'      => $v->status,
        ];
    }
}
