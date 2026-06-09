<?php

namespace App\Domains\Pet\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Domains\Pet\Models\AdoptionListing;
use App\Domains\Pet\Models\AdoptionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Gestión de publicaciones de adopción del dueño/rescatista (autenticado).
 */
class MyAdoptionController extends Controller
{
    /** GET /my-adoptions — publicaciones del usuario. */
    public function index(Request $request): JsonResponse
    {
        $listings = AdoptionListing::where('owner_id', $request->user()->uuid)
            ->withCount(['requests as pending_requests_count' => fn ($q) => $q->where('status', 'pending')])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $listings->map(fn ($l) => $this->format($l))]);
    }

    /** POST /my-adoptions — crear publicación. */
    public function store(Request $request): JsonResponse
    {
        $v = $this->validateListing($request);

        $cols = $this->mapToColumns($v);
        $cols['owner_id']  = $request->user()->uuid;
        $cols['slug']      = $this->buildSlug($v['name']);
        $cols['photo_url'] = $v['photos'][0] ?? null;

        $listing = AdoptionListing::create($cols);

        return response()->json($this->format($listing), 201);
    }

    /** PUT /my-adoptions/{id} — editar. */
    public function update(Request $request, string $id): JsonResponse
    {
        $listing = $this->findOwned($request, $id);
        $v = $this->validateListing($request, partial: true);

        $cols = $this->mapToColumns($v);
        if (array_key_exists('photos', $cols)) {
            $cols['photo_url'] = $cols['photos'][0] ?? null;
        }
        $listing->update($cols);

        return response()->json($this->format($listing->fresh()));
    }

    /** DELETE /my-adoptions/{id} */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->findOwned($request, $id)->delete();
        return response()->json(['ok' => true]);
    }

    /** POST /my-adoptions/{id}/photo — agregar foto. */
    public function uploadPhoto(Request $request, string $id): JsonResponse
    {
        $listing = $this->findOwned($request, $id);
        $request->validate(['photo' => 'required|image|max:5120']);

        $path = $request->file('photo')->store("adoption-photos/{$request->user()->uuid}", 'public');
        $url  = asset('storage/' . ltrim($path, '/'));

        $photos   = $listing->photos ?? [];
        $photos[] = $url;
        $listing->update([
            'photos'    => $photos,
            'photo_url' => $listing->photo_url ?: $url,
        ]);

        return response()->json(['url' => $url, 'photos' => $photos]);
    }

    /** PATCH /my-adoptions/{id}/status — cambiar estado. */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $listing = $this->findOwned($request, $id);
        $data = $request->validate(['status' => 'required|in:available,reserved,adopted,paused']);
        $listing->update(['status' => $data['status']]);

        return response()->json(['ok' => true, 'status' => $listing->status]);
    }

    /** GET /my-adoptions/{id}/requests — solicitudes recibidas. */
    public function requests(Request $request, string $id): JsonResponse
    {
        $listing = $this->findOwned($request, $id);
        $reqs = $listing->requests()->paginate(50);

        return response()->json([
            'data' => $reqs->map(fn (AdoptionRequest $r) => [
                'id'        => $r->id,
                'name'      => $r->requester_name,
                'contact'   => $r->requester_contact,
                'message'   => $r->message,
                'status'    => $r->status,
                'createdAt' => $r->created_at?->toISOString(),
            ]),
            'meta' => ['total' => $reqs->total()],
        ]);
    }

    /** PATCH /my-adoptions/{id}/requests/{requestId} — aceptar/rechazar. */
    public function respondRequest(Request $request, string $id, string $requestId): JsonResponse
    {
        $listing = $this->findOwned($request, $id);
        $data = $request->validate(['status' => 'required|in:accepted,rejected']);

        $adReq = AdoptionRequest::where('id', $requestId)
            ->where('listing_id', $listing->id)
            ->firstOrFail();
        $adReq->update(['status' => $data['status']]);

        // Al aceptar, la publicación pasa a "reservada" si seguía disponible.
        if ($data['status'] === 'accepted' && $listing->status === 'available') {
            $listing->update(['status' => 'reserved']);
        }

        return response()->json([
            'ok'            => true,
            'status'        => $adReq->status,
            'listingStatus' => $listing->fresh()->status,
        ]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function findOwned(Request $request, string $id): AdoptionListing
    {
        return AdoptionListing::where('id', $id)
            ->where('owner_id', $request->user()->uuid)
            ->firstOrFail();
    }

    private function validateListing(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name'         => "$req|string|max:120",
            'species'      => "$req|in:cat,dog,rabbit,other",
            'breed'        => 'nullable|string|max:120',
            'gender'       => 'nullable|in:female,male',
            'ageLabel'     => 'nullable|string|max:60',
            'size'         => 'nullable|in:small,medium,large',
            'color'        => 'nullable|string|max:80',
            'description'  => 'nullable|string|max:2000',
            'photos'       => 'nullable|array|max:8',
            'photos.*'     => 'string|max:500',
            'city'         => 'nullable|string|max:120',
            'state'        => 'nullable|string|max:120',
            'lat'          => 'nullable|numeric|between:-90,90',
            'lng'          => 'nullable|numeric|between:-180,180',
            'sterilized'   => 'nullable|boolean',
            'vaccinated'   => 'nullable|boolean',
            'dewormed'     => 'nullable|boolean',
            'goodWithKids' => 'nullable|boolean',
            'goodWithPets' => 'nullable|boolean',
            'specialNeeds' => 'nullable|boolean',
            'requirements' => 'nullable|string|max:1000',
            'isPublished'  => 'nullable|boolean',
        ]);
    }

    /** Mapea solo las claves presentes (camelCase API → snake_case DB). */
    private function mapToColumns(array $v): array
    {
        $map = [
            'name' => 'name', 'species' => 'species', 'breed' => 'breed', 'gender' => 'gender',
            'ageLabel' => 'age_label', 'size' => 'size', 'color' => 'color', 'description' => 'description',
            'photos' => 'photos', 'city' => 'city', 'state' => 'state', 'lat' => 'lat', 'lng' => 'lng',
            'sterilized' => 'sterilized', 'vaccinated' => 'vaccinated', 'dewormed' => 'dewormed',
            'goodWithKids' => 'good_with_kids', 'goodWithPets' => 'good_with_pets',
            'specialNeeds' => 'special_needs', 'requirements' => 'requirements', 'isPublished' => 'is_published',
        ];

        $out = [];
        foreach ($map as $camel => $col) {
            if (array_key_exists($camel, $v)) {
                $out[$col] = $v[$camel];
            }
        }
        return $out;
    }

    private function buildSlug(string $name): string
    {
        return (Str::slug($name) ?: 'adopcion') . '-' . Str::random(5);
    }

    private function format(AdoptionListing $l): array
    {
        return [
            'id'                   => $l->id,
            'slug'                 => $l->slug,
            'name'                 => $l->name,
            'species'              => $l->species,
            'breed'                => $l->breed,
            'gender'               => $l->gender,
            'ageLabel'             => $l->age_label,
            'size'                 => $l->size,
            'color'                => $l->color,
            'description'          => $l->description,
            'photos'               => $l->photos ?? [],
            'photoUrl'             => $l->photo_url,
            'city'                 => $l->city,
            'state'                => $l->state,
            'lat'                  => $l->lat,
            'lng'                  => $l->lng,
            'sterilized'           => $l->sterilized,
            'vaccinated'           => $l->vaccinated,
            'dewormed'             => $l->dewormed,
            'goodWithKids'         => $l->good_with_kids,
            'goodWithPets'         => $l->good_with_pets,
            'specialNeeds'         => $l->special_needs,
            'requirements'         => $l->requirements,
            'status'               => $l->status,
            'isPublished'          => $l->is_published,
            'moderationStatus'     => $l->moderation_status,
            'viewsCount'           => $l->views_count,
            'requestsCount'        => $l->requests_count,
            'pendingRequestsCount' => $l->pending_requests_count ?? 0,
            'createdAt'            => $l->created_at?->toISOString(),
        ];
    }
}
