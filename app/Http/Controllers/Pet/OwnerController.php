<?php

namespace App\Http\Controllers\Pet;

use App\Http\Controllers\Controller;
use App\Models\Pet\Owner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OwnerController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $owner = Owner::findOrFail($request->user()->uuid);
        return response()->json($this->format($owner));
    }

    public function update(Request $request): JsonResponse
    {
        $owner = Owner::findOrFail($request->user()->uuid);

        $data = $request->validate([
            'display_name'           => 'sometimes|string|max:255',
            'phone'                  => 'sometimes|nullable|string|max:50',
            'email'                  => 'sometimes|nullable|email|max:255',
            'address'                => 'sometimes|nullable|string',
            'emergency_contact'      => 'sometimes|nullable|string|max:255',
            'emergency_phone'        => 'sometimes|nullable|string|max:50',
            'public_email_visible'   => 'sometimes|boolean',
            'public_address_visible' => 'sometimes|boolean',
        ]);

        $owner->update($data);

        return response()->json($this->format($owner->fresh()));
    }

    private function format(Owner $owner): array
    {
        return [
            'id'                   => $owner->id,
            'displayName'          => $owner->display_name,
            'phone'                => $owner->phone ?? '',
            'email'                => $owner->email ?? '',
            'address'              => $owner->address ?? '',
            'emergencyContact'     => $owner->emergency_contact ?? '',
            'emergencyPhone'       => $owner->emergency_phone ?? '',
            'publicEmailVisible'   => $owner->public_email_visible,
            'publicAddressVisible' => $owner->public_address_visible,
        ];
    }
}
