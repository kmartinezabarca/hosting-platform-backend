<?php

namespace App\Http\Controllers\RokePet;

use App\Http\Controllers\Controller;
use App\Models\RokePet\ActivationEvent;
use App\Models\RokePet\Owner;
use App\Models\RokePet\Pet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $ownerId = $request->user()->uuid;
        $owner   = Owner::findOrFail($ownerId);

        $pets = Pet::where('owner_id', $ownerId)
            ->with(['vaccines', 'medicalRecords'])
            ->orderBy('created_at')
            ->get();

        return response()->json($pets->map(fn($p) => $this->format($p, $owner)));
    }

    public function store(Request $request): JsonResponse
    {
        $ownerId = $request->user()->uuid;

        $data = $request->validate([
            'name'    => 'required|string|max:255',
            'species' => 'required|in:cat,dog,rabbit,other',
            'breed'   => 'nullable|string|max:255',
            'breedEn' => 'nullable|string|max:255',
            'gender'  => 'nullable|in:female,male',
        ]);

        // Ensure owner profile exists
        Owner::firstOrCreate(['id' => $ownerId], [
            'display_name' => $request->user()->name,
            'email'        => $request->user()->email,
        ]);

        $pet = Pet::create([
            'owner_id'   => $ownerId,
            'slug'       => $this->buildSlug($data['name']),
            'name'       => $data['name'],
            'species'    => $data['species'],
            'breed'      => $data['breed'] ?? '',
            'breed_en'   => $data['breedEn'] ?? $data['breed'] ?? '',
            'gender'     => $data['gender'] ?? 'female',
            'traits'     => [],
            'traits_en'  => [],
            'allergies'  => [],
            'allergies_en' => [],
            'allergy_profiles' => [],
            'conditions' => [],
            'conditions_en' => [],
            'active_treatments' => [],
            'active_treatments_en' => [],
            'current_medications' => [],
            'current_medications_en' => [],
        ]);

        ActivationEvent::create([
            'owner_id'    => $ownerId,
            'pet_id'      => $pet->id,
            'event_type'  => 'pet_created',
            'source'      => 'dashboard',
            'metadata'    => ['slug' => $pet->slug],
            'occurred_at' => now(),
        ]);

        $owner = Owner::find($ownerId);
        return response()->json($this->format($pet, $owner), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $pet = Pet::where('id', $id)
            ->where('owner_id', $request->user()->uuid)
            ->firstOrFail();

        $data = $request->validate([
            'name'                 => 'sometimes|string|max:255',
            'species'              => 'sometimes|in:cat,dog,rabbit,other',
            'breed'                => 'sometimes|nullable|string',
            'breedEn'              => 'sometimes|nullable|string',
            'gender'               => 'sometimes|nullable|in:female,male',
            'birthDate'            => 'sometimes|nullable|date',
            'color'                => 'sometimes|nullable|string',
            'colorEn'              => 'sometimes|nullable|string',
            'eyeColor'             => 'sometimes|nullable|string',
            'eyeColorEn'           => 'sometimes|nullable|string',
            'weight'               => 'sometimes|nullable|numeric',
            'sterilized'           => 'sometimes|boolean',
            'microchipId'          => 'sometimes|nullable|string',
            'nfcId'                => 'sometimes|nullable|string',
            'photoUrl'             => 'sometimes|nullable|string',
            'story'                => 'sometimes|nullable|string',
            'storyEn'              => 'sometimes|nullable|string',
            'traits'               => 'sometimes|array',
            'traitsEn'             => 'sometimes|array',
            'allergies'            => 'sometimes|array',
            'allergiesEn'          => 'sometimes|array',
            'allergyProfiles'      => 'sometimes|array',
            'conditions'           => 'sometimes|array',
            'conditionsEn'         => 'sometimes|array',
            'activeTreatments'     => 'sometimes|array',
            'activeTreatmentsEn'   => 'sometimes|array',
            'currentMedications'   => 'sometimes|array',
            'currentMedicationsEn' => 'sometimes|array',
            'specialCare'          => 'sometimes|nullable|string',
            'specialCareEn'        => 'sometimes|nullable|string',
            'primaryVetName'       => 'sometimes|nullable|string',
            'primaryVetPhone'      => 'sometimes|nullable|string',
            'primaryVetClinic'     => 'sometimes|nullable|string',
            'publicProfileEnabled' => 'sometimes|boolean',
        ]);

        $pet->update($this->camelToSnake($data));

        $owner = Owner::find($request->user()->uuid);
        return response()->json($this->format($pet->fresh()->load(['vaccines', 'medicalRecords']), $owner));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $pet = Pet::where('id', $id)
            ->where('owner_id', $request->user()->uuid)
            ->firstOrFail();

        $pet->delete();
        return response()->json(['ok' => true]);
    }

    public function uploadPhoto(Request $request, string $id): JsonResponse
    {
        $pet = Pet::where('id', $id)
            ->where('owner_id', $request->user()->uuid)
            ->firstOrFail();

        $request->validate(['photo' => 'required|image|max:5120']);

        $file = $request->file('photo');
        $path = $file->store("pet-photos/{$request->user()->uuid}", 'public');
        $url  = asset("storage/{$path}") . '?t=' . time();

        $pet->update(['photo_url' => $url]);

        return response()->json(['url' => $url]);
    }

    private function buildSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'mascota';
        $uid  = Str::random(5);
        return "{$base}-{$uid}";
    }

    private function camelToSnake(array $data): array
    {
        $map = [
            'breedEn'              => 'breed_en',
            'birthDate'            => 'birth_date',
            'colorEn'              => 'color_en',
            'eyeColor'             => 'eye_color',
            'eyeColorEn'           => 'eye_color_en',
            'microchipId'          => 'microchip_id',
            'nfcId'                => 'nfc_id',
            'photoUrl'             => 'photo_url',
            'storyEn'              => 'story_en',
            'traitsEn'             => 'traits_en',
            'allergiesEn'          => 'allergies_en',
            'allergyProfiles'      => 'allergy_profiles',
            'conditionsEn'         => 'conditions_en',
            'activeTreatments'     => 'active_treatments',
            'activeTreatmentsEn'   => 'active_treatments_en',
            'currentMedications'   => 'current_medications',
            'currentMedicationsEn' => 'current_medications_en',
            'specialCare'          => 'special_care',
            'specialCareEn'        => 'special_care_en',
            'primaryVetName'       => 'primary_vet_name',
            'primaryVetPhone'      => 'primary_vet_phone',
            'primaryVetClinic'     => 'primary_vet_clinic',
            'publicProfileEnabled' => 'public_profile_enabled',
        ];

        $result = [];
        foreach ($data as $key => $value) {
            $result[$map[$key] ?? $key] = $value;
        }
        return $result;
    }

    public function format(Pet $pet, ?Owner $owner): array
    {
        return [
            'id'                   => $pet->id,
            'ownerId'              => $pet->owner_id,
            'slug'                 => $pet->slug,
            'name'                 => $pet->name,
            'species'              => $pet->species,
            'breed'                => $pet->breed ?? '',
            'breedEn'              => $pet->breed_en ?? '',
            'gender'               => $pet->gender ?? 'female',
            'birthDate'            => $pet->birth_date ?? '',
            'color'                => $pet->color ?? '',
            'colorEn'              => $pet->color_en ?? '',
            'eyeColor'             => $pet->eye_color ?? '',
            'eyeColorEn'           => $pet->eye_color_en ?? '',
            'weight'               => $pet->weight ?? 0,
            'sterilized'           => $pet->sterilized,
            'microchipId'          => $pet->microchip_id ?? '',
            'nfcId'                => $pet->nfc_id ?? '',
            'photoUrl'             => $pet->photo_url,
            'story'                => $pet->story ?? '',
            'storyEn'              => $pet->story_en ?? '',
            'traits'               => $pet->traits ?? [],
            'traitsEn'             => $pet->traits_en ?? [],
            'allergies'            => $pet->allergies ?? [],
            'allergiesEn'          => $pet->allergies_en ?? [],
            'allergyProfiles'      => $pet->allergy_profiles ?? [],
            'conditions'           => $pet->conditions ?? [],
            'conditionsEn'         => $pet->conditions_en ?? [],
            'activeTreatments'     => $pet->active_treatments ?? [],
            'activeTreatmentsEn'   => $pet->active_treatments_en ?? [],
            'currentMedications'   => $pet->current_medications ?? [],
            'currentMedicationsEn' => $pet->current_medications_en ?? [],
            'specialCare'          => $pet->special_care ?? '',
            'specialCareEn'        => $pet->special_care_en ?? '',
            'primaryVetName'       => $pet->primary_vet_name ?? '',
            'primaryVetPhone'      => $pet->primary_vet_phone ?? '',
            'primaryVetClinic'     => $pet->primary_vet_clinic ?? '',
            'scannedCount'         => $pet->scanned_count,
            'lastScanLocation'     => $pet->last_scan_location,
            'publicProfileEnabled' => $pet->public_profile_enabled,
            'createdAt'            => $pet->created_at,
            'updatedAt'            => $pet->updated_at,
            'vaccines'             => $pet->relationLoaded('vaccines')
                ? $pet->vaccines->map(fn($v) => VaccineController::formatVaccine($v))->values()
                : [],
            'medicalRecords' => $pet->relationLoaded('medicalRecords')
                ? $pet->medicalRecords->map(fn($r) => MedicalRecordController::formatRecord($r))->values()
                : [],
            'owner' => $owner ? [
                'displayName'          => $owner->display_name ?? '',
                'name'                 => $owner->display_name ?? '',
                'phone'                => $owner->phone ?? '',
                'email'                => $owner->email ?? '',
                'address'              => $owner->address ?? '',
                'emergencyContact'     => $owner->emergency_contact ?? '',
                'emergencyPhone'       => $owner->emergency_phone ?? '',
                'publicEmailVisible'   => $owner->public_email_visible,
                'publicAddressVisible' => $owner->public_address_visible,
            ] : [],
        ];
    }
}
