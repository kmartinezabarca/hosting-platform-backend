<?php

namespace App\Domains\Pet\Http\Controllers;

use App\Domains\Pet\Events\AdoptionRequestBroadcast;
use App\Domains\Pet\Events\PetModerationReportBroadcast;
use App\Domains\Pet\Models\AdoptionListing;
use App\Domains\Pet\Models\AdoptionReport;
use App\Domains\Pet\Models\AdoptionRequest;
use App\Domains\Pet\Models\InboxNotification;
use App\Domains\Pet\Models\Owner;
use App\Domains\Pet\Services\PushNotificationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Adopción — lado público: explorar, ver detalle, solicitar e informar.
 * Nunca expone el contacto del publicador: el interesado queda vinculado con
 * su cuenta registrada y se avisa al publicador (push + inbox + tiempo real).
 */
class AdoptionController extends Controller
{
    /** GET /adoptions — listado público con filtros y búsqueda. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'species' => 'nullable|in:cat,dog,rabbit,other',
            'size' => 'nullable|in:small,medium,large',
            'gender' => 'nullable|in:female,male',
            'city' => 'nullable|string|max:120',
            'sterilized' => 'nullable|boolean',
            'vaccinated' => 'nullable|boolean',
            'goodWithKids' => 'nullable|boolean',
            'goodWithPets' => 'nullable|boolean',
            'q' => 'nullable|string|max:120',
            'status' => 'nullable|in:available,reserved,adopted',
        ]);

        $query = AdoptionListing::query()
            ->where('is_published', true)
            ->where('moderation_status', '!=', 'hidden')
            ->whereIn('status', ['available', 'reserved', 'adopted'])
            ->with('owner');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        foreach (['species', 'size', 'gender'] as $f) {
            if (! empty($filters[$f])) {
                $query->where($f, $filters[$f]);
            }
        }
        if (! empty($filters['city'])) {
            $query->where('city', 'like', '%'.$filters['city'].'%');
        }
        $boolFilters = [
            'sterilized' => 'sterilized',
            'vaccinated' => 'vaccinated',
            'goodWithKids' => 'good_with_kids',
            'goodWithPets' => 'good_with_pets',
        ];
        foreach ($boolFilters as $filterKey => $column) {
            if (! empty($filters[$filterKey])) {
                $query->where($column, true);
            }
        }
        if (! empty($filters['q'])) {
            $q = $filters['q'];
            $query->where(function ($w) use ($q) {
                $w->where('name', 'like', "%$q%")
                    ->orWhere('breed', 'like', "%$q%")
                    ->orWhere('description', 'like', "%$q%");
            });
        }

        $listings = $query->orderByDesc('created_at')->paginate(24);

        return response()->json([
            'data' => collect($listings->items())->map(fn ($l) => $this->formatPublic($l)),
            'meta' => [
                'total' => $listings->total(),
                'currentPage' => $listings->currentPage(),
                'lastPage' => $listings->lastPage(),
            ],
        ]);
    }

    /** GET /adoptions/{slug} — detalle público. */
    public function show(string $slug): JsonResponse
    {
        $listing = AdoptionListing::where('slug', $slug)
            ->where('is_published', true)
            ->where('moderation_status', '!=', 'hidden')
            ->with('owner')
            ->first();

        if (! $listing) {
            return response()->json(['error' => 'Publicación de adopción no encontrada'], 404);
        }

        $listing->increment('views_count');

        return response()->json($this->formatPublic($listing, detail: true));
    }

    /** POST /adoptions/{slug}/request — solicitud de adopción autenticada. */
    public function request(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'message' => 'nullable|string|max:1000',
        ]);

        $listing = AdoptionListing::where('slug', $slug)->where('is_published', true)->first();
        if (! $listing) {
            return response()->json(['ok' => false], 404);
        }

        $user = $request->user();
        $ownerId = $user->uuid;

        if ($listing->owner_id === $ownerId) {
            return response()->json(['ok' => false, 'error' => 'No puedes solicitar adoptar tu propia publicación.'], 422);
        }

        $owner = Owner::firstOrCreate(
            ['id' => $ownerId],
            [
                'display_name' => trim("{$user->first_name} {$user->last_name}") ?: $user->email,
                'email' => $user->email,
            ],
        );

        $adReq = AdoptionRequest::updateOrCreate([
            'listing_id' => $listing->id,
            'requester_owner_id' => $ownerId,
        ], [
            'requester_name' => $owner->display_name ?: (trim("{$user->first_name} {$user->last_name}") ?: $user->email),
            'requester_contact' => $owner->email ?: $user->email,
            'message' => $data['message'] ?? null,
            'status' => 'pending',
            'ip_address' => $request->ip(),
        ]);

        if ($adReq->wasRecentlyCreated) {
            $listing->increment('requests_count');
            // Solo la PRIMERA solicitud notifica: reenviar el formulario actualiza
            // el mensaje sin volver a timbrar al rescatista (anti-spam).
            $this->notifyOwnerOnRequest($listing, $adReq);
        }

        // Respuesta SIN datos del publicador.
        return response()->json(['ok' => true]);
    }

    /** POST /adoptions/{slug}/report — reporte de moderación. */
    public function report(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'required|in:spam,inappropriate,scam,other',
            'details' => 'nullable|string|max:500',
        ]);

        $listing = AdoptionListing::where('slug', $slug)->first();
        if (! $listing) {
            return response()->json(['ok' => false], 404);
        }

        // Una misma IP no infla el contador reportando en bucle la misma publicación.
        $alreadyReported = AdoptionReport::where('listing_id', $listing->id)
            ->where('ip_address', $request->ip())
            ->where('resolved', false)
            ->exists();
        if ($alreadyReported) {
            return response()->json(['ok' => true]); // idempotente: no revela si contó
        }

        AdoptionReport::create([
            'listing_id' => $listing->id,
            'reason' => $data['reason'],
            'details' => $data['details'] ?? null,
            'ip_address' => $request->ip(),
        ]);

        // Auto-marcar para revisión del admin al acumular reportes (no se oculta solo;
        // un admin decide ocultarla, para evitar abuso por reportes masivos).
        $count = AdoptionReport::where('listing_id', $listing->id)->where('resolved', false)->count();
        if ($count >= 3 && $listing->moderation_status === 'active') {
            $listing->update(['moderation_status' => 'flagged']);
        }

        // Aviso en vivo a los admins (toast + refresco de la cola de moderación).
        PetModerationReportBroadcast::dispatch(
            'adoption', (string) $listing->id, (string) $listing->name, $data['reason'], $count,
        );

        return response()->json(['ok' => true]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function notifyOwnerOnRequest(AdoptionListing $listing, AdoptionRequest $req): void
    {
        $title = "🏡 Alguien quiere adoptar a {$listing->name}";
        $body = "{$req->requester_name} quiere adoptar a {$listing->name}.";
        if ($req->message) {
            $body .= ' "'.Str::limit($req->message, 120).'"';
        }

        $url = '/dashboard/adopciones';
        $payload = [
            'type' => 'adoption_request',
            'listingId' => $listing->id,
            'listingSlug' => $listing->slug,
            'url' => $url,
        ];

        try {
            (new PushNotificationService)->sendToOwner($listing->owner_id, $title, $body, $payload);
        } catch (\Throwable) {
            // best-effort
        }

        // El inbox SIEMPRE se crea (aunque no haya push activo).
        try {
            InboxNotification::createForOwner(
                ownerId: $listing->owner_id,
                title: $title,
                body: $body,
                notifType: 'adoption_request',
                url: $url,
                tag: 'adoption-req-'.$listing->id,
            );
        } catch (\Throwable) {
            // no fatal
        }

        // Tiempo real (Reverb): si la app del publicador está abierta, lo ve al instante.
        try {
            AdoptionRequestBroadcast::dispatch(
                $listing->owner_id, $listing->id, $listing->slug, $listing->name, $title, $body, $url,
            );
        } catch (\Throwable) {
            // best-effort
        }
    }

    private function formatPublic(AdoptionListing $l, bool $detail = false): array
    {
        $base = [
            'id' => $l->id,
            'slug' => $l->slug,
            'name' => $l->name,
            'species' => $l->species,
            'breed' => $l->breed,
            'gender' => $l->gender,
            'birthDate' => $l->birth_date?->toDateString(),
            'ageLabel' => $l->display_age_label,
            'size' => $l->size,
            'color' => $l->color,
            'photoUrl' => $l->photo_url,
            'city' => $l->city,
            'state' => $l->state,
            'sterilized' => $l->sterilized,
            'vaccinated' => $l->vaccinated,
            'status' => $l->status,
            'publishedBy' => $l->owner?->display_name ?? null,
            'createdAt' => $l->created_at?->toISOString(),
        ];

        if ($detail) {
            $base['description'] = $l->description;
            $base['photos'] = $l->photos ?? [];
            $base['dewormed'] = $l->dewormed;
            $base['goodWithKids'] = $l->good_with_kids;
            $base['goodWithPets'] = $l->good_with_pets;
            $base['specialNeeds'] = $l->special_needs;
            $base['requirements'] = $l->requirements;
            $base['lat'] = $l->lat;
            $base['lng'] = $l->lng;
            $base['viewsCount'] = $l->views_count;
            // Reputación del rescatista (confianza para el adoptante).
            $base['rescuer'] = $l->owner ? [
                'ownerId' => $l->owner->id,
                'name' => $l->owner->display_name ?? 'Rescatista',
                'ratingAvg' => $l->owner->rescuer_rating_avg,
                'ratingCount' => $l->owner->rescuer_rating_count ?? 0,
            ] : null;
        }

        return $base;
    }
}
