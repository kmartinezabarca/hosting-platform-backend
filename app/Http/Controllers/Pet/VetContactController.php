<?php

namespace App\Http\Controllers\Pet;

use App\Http\Controllers\Controller;
use App\Models\Pet\VetContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VetContactController extends Controller
{
    /** GET /vets — list all vet contacts for the current owner */
    public function index(Request $request): JsonResponse
    {
        $contacts = VetContact::where('owner_id', $request->user()->uuid)
            ->orderBy('name')
            ->get()
            ->map(fn($v) => self::format($v));

        return response()->json($contacts);
    }

    /** POST /vets — create a new vet contact */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'       => 'required|string|max:255',
            'clinic'     => 'nullable|string|max:255',
            'phone'      => 'nullable|string|max:50',
            'vetLicense' => 'nullable|string|max:255',
            'specialty'  => 'nullable|string|max:255',
        ]);

        $contact = VetContact::create([
            'owner_id'    => $request->user()->uuid,
            'name'        => $data['name'],
            'clinic'      => $data['clinic'] ?? null,
            'phone'       => $data['phone'] ?? null,
            'vet_license' => $data['vetLicense'] ?? null,
            'specialty'   => $data['specialty'] ?? null,
        ]);

        return response()->json(self::format($contact), 201);
    }

    /** PUT /vets/{id} — update a vet contact */
    public function update(Request $request, string $id): JsonResponse
    {
        $contact = VetContact::where('id', $id)
            ->where('owner_id', $request->user()->uuid)
            ->firstOrFail();

        $data = $request->validate([
            'name'       => 'sometimes|string|max:255',
            'clinic'     => 'sometimes|nullable|string|max:255',
            'phone'      => 'sometimes|nullable|string|max:50',
            'vetLicense' => 'sometimes|nullable|string|max:255',
            'specialty'  => 'sometimes|nullable|string|max:255',
        ]);

        $contact->update([
            'name'        => $data['name'] ?? $contact->name,
            'clinic'      => $data['clinic'] ?? $contact->clinic,
            'phone'       => $data['phone'] ?? $contact->phone,
            'vet_license' => $data['vetLicense'] ?? $contact->vet_license,
            'specialty'   => $data['specialty'] ?? $contact->specialty,
        ]);

        return response()->json(self::format($contact->fresh()));
    }

    /** DELETE /vets/{id} */
    public function destroy(Request $request, string $id): JsonResponse
    {
        VetContact::where('id', $id)
            ->where('owner_id', $request->user()->uuid)
            ->firstOrFail()
            ->delete();

        return response()->json(['ok' => true]);
    }

    public static function format(VetContact $v): array
    {
        return [
            'id'         => $v->id,
            'name'       => $v->name,
            'clinic'     => $v->clinic ?? '',
            'phone'      => $v->phone ?? '',
            'vetLicense' => $v->vet_license ?? '',
            'specialty'  => $v->specialty ?? '',
            'createdAt'  => $v->created_at,
        ];
    }
}
