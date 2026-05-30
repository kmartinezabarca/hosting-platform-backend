<?php

namespace App\Http\Controllers\Pet;

use App\Http\Controllers\Controller;
use App\Models\Pet\Pet;
use App\Models\Pet\Vaccine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class VaccineController extends Controller
{
    public function store(Request $request, string $petId): JsonResponse
    {
        $pet = Pet::where('id', $petId)->where('owner_id', $request->user()->uuid)->firstOrFail();

        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'nameEn'      => 'nullable|string|max:255',
            'date'        => 'nullable|date',
            'nextDue'     => 'nullable|date',
            'appliedBy'   => 'nullable|string|max:255',
            'vetLicense'  => 'nullable|string|max:255',
            'batchNumber' => 'nullable|string|max:255',
            'status'      => 'required|in:applied,pending,overdue',
        ]);

        $vaccine = self::createWithSchedule($pet->id, [
            'name'         => $data['name'],
            'name_en'      => $data['nameEn'] ?? null,
            'date'         => $data['date'] ?? null,
            'next_due'     => $data['nextDue'] ?? null,
            'applied_by'   => $data['appliedBy'] ?? null,
            'vet_license'  => $data['vetLicense'] ?? null,
            'batch_number' => $data['batchNumber'] ?? null,
            'status'       => $data['status'],
        ]);

        return response()->json(self::formatVaccine($vaccine), 201);
    }

    /**
     * Crea una vacuna respetando la invariante del modelo (Opción B):
     *   - Una fila "aplicada" NUNCA conserva un next_due accionable.
     *   - Si se registra una vacuna aplicada CON próxima dosis, se crea además
     *     una fila independiente con status 'pending' para esa próxima dosis.
     *
     * Una fila = una dosis. Así "pendiente" se define siempre por `status` y
     * nunca se produce el estado ambiguo (aplicada + next_due) que generaba la
     * "vacuna pendiente que no se podía aplicar".
     *
     * Devuelve la fila principal (la dosis registrada).
     */
    public static function createWithSchedule(string $petId, array $attrs): Vaccine
    {
        $isApplied = ($attrs['status'] ?? 'pending') === 'applied';
        $nextDue   = $attrs['next_due'] ?? null;

        $primary = Vaccine::create(array_merge($attrs, [
            'pet_id'   => $petId,
            'next_due' => $isApplied ? null : $nextDue,
        ]));

        if ($isApplied && $nextDue) {
            Vaccine::create([
                'pet_id'   => $petId,
                'name'     => $attrs['name'],
                'name_en'  => $attrs['name_en'] ?? null,
                'next_due' => $nextDue,
                'status'   => 'pending',
            ]);
        }

        return $primary;
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $vaccine = Vaccine::findOrFail($id);
        Pet::where('id', $vaccine->pet_id)->where('owner_id', $request->user()->uuid)->firstOrFail();

        $data = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'nameEn'      => 'sometimes|nullable|string',
            'date'        => 'sometimes|nullable|date',
            'nextDue'     => 'sometimes|nullable|date',
            'appliedBy'   => 'sometimes|nullable|string',
            'vetLicense'  => 'sometimes|nullable|string',
            'batchNumber' => 'sometimes|nullable|string',
            'status'      => 'sometimes|in:applied,pending,overdue',
        ]);

        $newStatus  = $data['status'] ?? $vaccine->status;
        $newNextDue = array_key_exists('nextDue', $data) ? $data['nextDue'] : $vaccine->next_due;

        // Invariante (Opción B): una vacuna aplicada nunca conserva un next_due
        // accionable. La próxima dosis vive en su propia fila 'pending' (creada
        // por el flujo de "marcar como aplicada" en el cliente).
        if ($newStatus === 'applied') {
            $newNextDue = null;
        }

        $vaccine->update([
            'name'         => $data['name'] ?? $vaccine->name,
            'name_en'      => $data['nameEn'] ?? $vaccine->name_en,
            'date'         => $data['date'] ?? $vaccine->date,
            'next_due'     => $newNextDue,
            'applied_by'   => $data['appliedBy'] ?? $vaccine->applied_by,
            'vet_license'  => $data['vetLicense'] ?? $vaccine->vet_license,
            'batch_number' => $data['batchNumber'] ?? $vaccine->batch_number,
            'status'       => $newStatus,
        ]);

        return response()->json(self::formatVaccine($vaccine->fresh()));
    }

    public function uploadPhoto(Request $request, string $id): JsonResponse
    {
        $vaccine = Vaccine::findOrFail($id);
        Pet::where('id', $vaccine->pet_id)->where('owner_id', $request->user()->uuid)->firstOrFail();

        $request->validate(['photo' => 'required|image|max:5120']);

        // Remove old photo if exists
        if ($vaccine->label_photo) {
            Storage::disk('public')->delete($vaccine->label_photo);
        }

        $path = $request->file('photo')->store("vaccine-labels/{$vaccine->id}", 'public');
        $vaccine->update(['label_photo' => $path]);

        return response()->json([
            'labelPhotoUrl' => Storage::disk('public')->url($path),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $vaccine = Vaccine::findOrFail($id);
        Pet::where('id', $vaccine->pet_id)->where('owner_id', $request->user()->uuid)->firstOrFail();

        if ($vaccine->label_photo) {
            Storage::disk('public')->delete($vaccine->label_photo);
        }

        $vaccine->delete();
        return response()->json(['ok' => true]);
    }

    public static function formatVaccine(Vaccine $v): array
    {
        return [
            'id'            => $v->id,
            'name'          => $v->name,
            'nameEn'        => $v->name_en ?? $v->name,
            'date'          => $v->date ?? '',
            'nextDue'       => $v->next_due,
            'appliedBy'     => $v->applied_by ?? '',
            'vetLicense'    => $v->vet_license ?? '',
            'batchNumber'   => $v->batch_number ?? '',
            'status'        => $v->status,
            'labelPhotoUrl' => $v->label_photo
                ? Storage::disk('public')->url($v->label_photo)
                : null,
        ];
    }
}
