<?php

namespace App\Domains\Pet\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Domains\Pet\Models\AdoptionFollowup;
use App\Domains\Pet\Models\AdoptionListing;
use App\Domains\Pet\Models\AdoptionRequest;
use App\Domains\Pet\Models\AdoptionReview;
use App\Domains\Pet\Models\Owner;
use App\Domains\Pet\Services\ReputationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gestión de publicaciones de adopción del dueño/rescatista (autenticado).
 */
class MyAdoptionController extends Controller
{
    /** GET /my-adoptions — publicaciones del usuario. */
    public function index(Request $request): JsonResponse
    {
        $me = $request->user()->uuid;

        $listings = AdoptionListing::where('owner_id', $me)
            ->withCount(['requests as pending_requests_count' => fn ($q) => $q->where('status', 'pending')])
            ->with([
                'adopter',
                'followups' => fn ($q) => $q->orderByDesc('submitted_at')->orderByDesc('created_at'),
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        // ¿Ya calificó el rescatista al adoptante de cada adopción?
        $reviewedIds = AdoptionReview::where('reviewer_owner_id', $me)
            ->whereIn('listing_id', $listings->pluck('id'))
            ->pluck('listing_id')
            ->all();

        return response()->json([
            'data' => $listings->map(fn ($l) => $this->format($l, [
                'reviewedAdopter' => in_array($l->id, $reviewedIds, true),
            ])),
        ]);
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
        $listing = $this->findOwned($request, $id);

        // Una adopción completada es historial de reputación (reseñas y
        // seguimientos colgarían de ella): no se borra, se despublica.
        if ($listing->adopted_by_owner_id) {
            return response()->json([
                'error' => 'Esta publicación tiene una adopción completada; puedes pausarla pero no eliminarla',
            ], 422);
        }

        $listing->delete();
        return response()->json(['ok' => true]);
    }

    private const MAX_PHOTOS = 8;

    /** POST /my-adoptions/{id}/photo — agregar foto. */
    public function uploadPhoto(Request $request, string $id): JsonResponse
    {
        $listing = $this->findOwned($request, $id);
        // `mimes` explícito (sin SVG: puede incrustar scripts → XSS almacenado).
        $request->validate(['photo' => 'required|file|mimes:jpg,jpeg,png,webp,gif|max:5120']);

        $photos = $listing->photos ?? [];
        if (count($photos) >= self::MAX_PHOTOS) {
            return response()->json(['error' => 'Máximo ' . self::MAX_PHOTOS . ' fotos por publicación'], 422);
        }

        $path = $request->file('photo')->store("adoption-photos/{$request->user()->uuid}", 'public');
        $url  = asset('storage/' . ltrim($path, '/'));

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
        $data = $request->validate([
            'status'          => 'required|in:available,reserved,adopted,paused',
            'adopterRequestId' => 'nullable|uuid',  // qué solicitud aceptada es el adoptante
        ]);

        // Una adopción completada con adoptante vinculado es definitiva: revertirla
        // dejaría reseñas/seguimientos apuntando a una adopción "fantasma".
        if ($listing->adopted_by_owner_id && $data['status'] !== 'adopted') {
            return response()->json([
                'error' => 'La adopción ya fue completada y no puede revertirse',
            ], 422);
        }

        // Al completar la adopción por primera vez: vincula al adoptante,
        // crea el seguimiento automático (+30 días) y recalcula reputación.
        if ($data['status'] === 'adopted' && !$listing->adopted_by_owner_id) {
            $this->completeAdoption($listing, $data['adopterRequestId'] ?? null);
        }

        $listing->update(['status' => $data['status']]);

        return response()->json([
            'ok'              => true,
            'status'          => $listing->status,
            'adoptedByOwnerId' => $listing->adopted_by_owner_id,
        ]);
    }

    /** Vincula al adoptante (de la solicitud aceptada), agenda seguimiento y recalcula reputación. */
    private function completeAdoption(AdoptionListing $listing, ?string $adopterRequestId): void
    {
        if ($adopterRequestId) {
            $candidate = AdoptionRequest::where('listing_id', $listing->id)
                ->where('id', $adopterRequestId)
                ->where('status', 'accepted')
                ->first();

            if ($candidate) {
                $this->backfillRequesterOwner($candidate);
            }
        }

        $reqQuery = AdoptionRequest::where('listing_id', $listing->id)
            ->where('status', 'accepted')
            ->whereNotNull('requester_owner_id');

        if ($adopterRequestId) {
            $reqQuery->where('id', $adopterRequestId);
        }

        $adopterRequest = $reqQuery->orderByDesc('updated_at')->first();
        if (!$adopterRequest) {
            return; // adopción sin adoptante con cuenta (offline): sin reputación.
        }

        $adopterId = $adopterRequest->requester_owner_id;

        // Atómico: vincular adoptante + seguimiento automático van juntos
        // (sin esto, un fallo a medias dejaría la adopción sin seguimiento).
        DB::connection('roke_pet')->transaction(function () use ($listing, $adopterId) {
            $listing->forceFill([
                'adopted_by_owner_id' => $adopterId,
                'adopted_at'          => now(),
            ])->save();

            // Seguimiento automático a 30 días (señal central de reputación).
            AdoptionFollowup::create([
                'listing_id'            => $listing->id,
                'adopter_owner_id'      => $adopterId,
                'requested_by_owner_id' => $listing->owner_id,
                'status'                => 'requested',
                'requested_at'          => now(),
                'due_at'                => now()->addDays(30),
            ]);
        });

        (new ReputationService())->recompute($adopterId);
    }

    /**
     * GET /my-adoptions/{id}/requests — solicitudes recibidas, ordenadas por
     * reputación del solicitante (mejores candidatos primero). Los anónimos
     * (sin cuenta) van al final como "sin historial".
     */
    public function requests(Request $request, string $id): JsonResponse
    {
        $listing = $this->findOwned($request, $id);

        // Reputación de cada solicitante con cuenta, en bloque (sin N+1).
        $reqs       = $listing->requests()->get();
        $this->backfillRequesterOwners($reqs);
        $reqs = $reqs
            ->unique(fn (AdoptionRequest $r) => $r->requester_owner_id
                ? 'owner:' . $r->requester_owner_id
                : 'contact:' . strtolower(trim((string) $r->requester_contact)))
            ->values();
        $ownerIds   = $reqs->pluck('requester_owner_id')->filter()->unique()->all();
        $reputation = Owner::whereIn('id', $ownerIds)->get()->keyBy('id');

        $items = $reqs->map(function (AdoptionRequest $r) use ($reputation) {
            $o = $r->requester_owner_id ? $reputation->get($r->requester_owner_id) : null;
            $rep = $o ? [
                'ratingAvg'      => $o->adopter_rating_avg,
                'ratingCount'    => $o->adopter_rating_count ?? 0,
                'adoptionsCount' => $o->adopter_adoptions_count ?? 0,
                'followupsRatio' => $o->adopter_followups_ratio,
            ] : null;

            return [
                'id'               => $r->id,
                'name'             => $r->requester_name,
                'contact'          => $r->requester_contact,
                'message'          => $r->message,
                'status'           => $r->status,
                'requesterOwnerId' => $r->requester_owner_id,
                'reputation'       => $rep,
                'sortScore'        => $this->candidateScore($rep),
                'createdAt'        => $r->created_at?->toISOString(),
            ];
        });

        // Orden: mejor reputación primero; a igualdad, solicitud más reciente.
        $sorted = $items
            ->sortBy([
                ['sortScore', 'desc'],
                ['createdAt', 'desc'],
            ])
            ->values()
            ->map(fn ($i) => collect($i)->except('sortScore')->all());

        return response()->json([
            'data' => $sorted,
            'meta' => ['total' => $sorted->count()],
        ]);
    }

    /** Puntaje de candidato (0–1). Seguimiento al día pesa más; rating luego; adopciones un extra. */
    private function candidateScore(?array $rep): float
    {
        if ($rep === null) {
            return -1.0; // anónimo / sin cuenta → al final
        }
        $followups = $rep['followupsRatio'] ?? 0.5;                 // sin datos → neutral
        $rating    = ($rep['ratingAvg'] ?? 2.5) / 5;               // sin datos → neutral
        $adoptions = min($rep['adoptionsCount'] ?? 0, 5) / 5;
        return round(0.5 * $followups + 0.4 * $rating + 0.1 * $adoptions, 4);
    }

    /** Repara solicitudes antiguas creadas antes de exigir cuenta, usando el email registrado. */
    private function backfillRequesterOwners($requests): void
    {
        $contacts = $requests
            ->filter(fn (AdoptionRequest $r) => !$r->requester_owner_id && $this->looksLikeEmail($r->requester_contact))
            ->map(fn (AdoptionRequest $r) => strtolower(trim($r->requester_contact)))
            ->unique()
            ->values();

        if ($contacts->isEmpty()) {
            return;
        }

        $owners = Owner::whereIn('email', $contacts->all())
            ->get()
            ->keyBy(fn (Owner $owner) => strtolower(trim((string) $owner->email)));

        foreach ($requests as $request) {
            if ($request->requester_owner_id || !$this->looksLikeEmail($request->requester_contact)) {
                continue;
            }

            $owner = $owners->get(strtolower(trim($request->requester_contact)));
            if ($owner) {
                $request->forceFill([
                    'requester_owner_id' => $owner->id,
                    'requester_name'     => $request->requester_name ?: $owner->display_name,
                    'requester_contact'  => $owner->email ?: $request->requester_contact,
                ])->save();
            }
        }
    }

    private function backfillRequesterOwner(AdoptionRequest $request): void
    {
        if ($request->requester_owner_id || !$this->looksLikeEmail($request->requester_contact)) {
            return;
        }

        $owner = Owner::where('email', strtolower(trim($request->requester_contact)))->first();
        if (!$owner) {
            return;
        }

        $request->forceFill([
            'requester_owner_id' => $owner->id,
            'requester_name'     => $request->requester_name ?: $owner->display_name,
            'requester_contact'  => $owner->email ?: $request->requester_contact,
        ])->save();
    }

    private function looksLikeEmail(?string $value): bool
    {
        return is_string($value) && filter_var(trim($value), FILTER_VALIDATE_EMAIL) !== false;
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
            // Solo URLs http(s) reales: evita javascript:/data: u otros esquemas inyectados.
            'photos.*'     => 'string|max:500|url|starts_with:http://,https://',
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

    private function format(AdoptionListing $l, array $extra = []): array
    {
        return [
            'id'                   => $l->id,
            'slug'                 => $l->slug,
            'adoptedByOwnerId'     => $l->adopted_by_owner_id,
            'adoptedByName'        => $l->relationLoaded('adopter') ? $l->adopter?->display_name : null,
            'adoptedAt'            => $l->adopted_at?->toISOString(),
            'reviewedAdopter'      => $extra['reviewedAdopter'] ?? false,
            'followups'            => $l->relationLoaded('followups')
                ? $l->followups->map(fn (AdoptionFollowup $f) => $this->formatFollowup($f))->values()
                : [],
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

    private function formatFollowup(AdoptionFollowup $f): array
    {
        return [
            'id'           => $f->id,
            'status'       => $f->status,
            'photos'       => $f->photos ?? [],
            'note'         => $f->note,
            'reaction'     => $f->reaction,
            'reactionNote' => $f->reaction_note,
            'reactedAt'    => $f->reacted_at?->toISOString(),
            'requestedAt'  => $f->requested_at?->toISOString(),
            'dueAt'        => $f->due_at?->toISOString(),
            'submittedAt'  => $f->submitted_at?->toISOString(),
        ];
    }
}
