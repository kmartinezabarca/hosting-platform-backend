<?php

namespace App\Domains\Pet\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Domains\Pet\Models\AdoptionFollowup;
use App\Domains\Pet\Models\AdoptionListing;
use App\Domains\Pet\Models\AdoptionReview;
use App\Domains\Pet\Models\AdoptionReviewReport;
use App\Domains\Pet\Models\InboxNotification;
use App\Domains\Pet\Models\Owner;
use App\Domains\Pet\Services\PushNotificationService;
use App\Domains\Pet\Services\ReputationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Reputación de adopciones — reseñas bidireccionales, historial del adoptante
 * y seguimiento con fotos. Construye confianza sobre adopciones reales.
 */
class ReputationController extends Controller
{
    public function __construct(private ReputationService $reputation = new ReputationService())
    {
    }

    /** POST /adoptions/reviews — calificar la contraparte de una adopción completada. */
    public function storeReview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'listingId'           => 'required|uuid',
            'rating'              => 'required|integer|min:1|max:5',
            'scoreResponsibility' => 'nullable|integer|min:1|max:5',
            'scoreCommunication'  => 'nullable|integer|min:1|max:5',
            'scoreConditions'     => 'nullable|integer|min:1|max:5',
            'comment'             => 'nullable|string|max:1000',
        ]);

        $me      = $request->user()->uuid;
        $listing = AdoptionListing::findOrFail($data['listingId']);

        // Solo sobre adopciones reales y completadas.
        if ($listing->status !== 'adopted' || !$listing->adopted_by_owner_id) {
            return response()->json(['error' => 'Esta adopción todavía no está completada'], 422);
        }

        // Determina quién califica a quién según el rol del que reseña.
        if ($me === $listing->owner_id) {
            $revieweeId = $listing->adopted_by_owner_id;
            $role       = 'adopter';            // el evaluado es el adoptante
        } elseif ($me === $listing->adopted_by_owner_id) {
            $revieweeId = $listing->owner_id;
            $role       = 'rescuer';            // el evaluado es el rescatista
        } else {
            return response()->json(['error' => 'No participaste en esta adopción'], 403);
        }

        if ($me === $revieweeId) {
            return response()->json(['error' => 'No puedes calificarte a ti mismo'], 422);
        }

        // 1 reseña por persona por adopción.
        if (AdoptionReview::where('listing_id', $listing->id)->where('reviewer_owner_id', $me)->exists()) {
            return response()->json(['error' => 'Ya calificaste esta adopción'], 422);
        }

        $review = AdoptionReview::create([
            'listing_id'           => $listing->id,
            'reviewer_owner_id'    => $me,
            'reviewee_owner_id'    => $revieweeId,
            'role'                 => $role,
            'rating'               => $data['rating'],
            'score_responsibility' => $data['scoreResponsibility'] ?? null,
            'score_communication'  => $data['scoreCommunication'] ?? null,
            'score_conditions'     => $data['scoreConditions'] ?? null,
            'comment'              => isset($data['comment']) ? trim($data['comment']) : null,
        ]);

        $this->reputation->recompute($revieweeId);
        $this->notifyReviewee($revieweeId, $role, $data['rating'], $listing->name);

        return response()->json($this->formatReview($review, $me), 201);
    }

    /** GET /reputation/{ownerId} — resumen público + reseñas recientes (para badges/perfil). */
    public function show(Request $request, string $ownerId): JsonResponse
    {
        $owner = Owner::find($ownerId);
        if (!$owner) {
            return response()->json(['error' => 'No encontrado'], 404);
        }

        // Paginadas: el perfil siempre puede llegar a TODAS las reseñas visibles.
        $reviewsPage = AdoptionReview::where('reviewee_owner_id', $ownerId)
            ->where('moderation_status', '!=', 'hidden')
            ->with('reviewer')
            ->orderByDesc('created_at')
            ->paginate(10);

        $reviews = collect($reviewsPage->items())->map(fn (AdoptionReview $r) => [
            'id'        => $r->id,
            'role'      => $r->role,
            'rating'    => $r->rating,
            'comment'   => $r->comment,
            'author'    => $r->reviewer?->display_name ?? 'Alguien',
            'createdAt' => $r->created_at?->toISOString(),
        ]);

        // Galería de seguimiento: fotos que los adoptantes subieron de animales
        // que este dueño rescató. Es la prueba social más fuerte de su perfil.
        $gallery = AdoptionFollowup::where('status', 'submitted')
            ->whereHas('listing', fn ($q) => $q->where('owner_id', $ownerId))
            ->with('listing:id,name,species')
            ->orderByDesc('submitted_at')
            ->limit(12)
            ->get()
            ->flatMap(fn (AdoptionFollowup $f) => collect($f->photos ?? [])->map(fn ($url) => [
                'url'         => $url,
                'petName'     => $f->listing?->name,
                'submittedAt' => $f->submitted_at?->toISOString(),
            ]))
            ->take(18)
            ->values();

        return response()->json([
            ...$this->reputation->summaryFor($owner),
            'reviews'         => $reviews,
            'reviewsMeta'     => [
                'total'       => $reviewsPage->total(),
                'currentPage' => $reviewsPage->currentPage(),
                'lastPage'    => $reviewsPage->lastPage(),
            ],
            'followupGallery' => $gallery,
        ]);
    }

    /** POST /my-adoptions/followups/{id}/react — el rescatista reacciona al seguimiento entregado. */
    public function reactFollowup(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'reaction' => 'required|in:heart,clap,smile,pray',
            'note'     => 'nullable|string|max:300',
        ]);

        $followup = AdoptionFollowup::with('listing')->findOrFail($id);
        $listing  = $followup->listing;

        if (!$listing || $listing->owner_id !== $request->user()->uuid) {
            return response()->json(['error' => 'No autorizado'], 403);
        }
        if ($followup->status !== 'submitted') {
            return response()->json(['error' => 'Este seguimiento aún no fue entregado'], 422);
        }

        $followup->update([
            'reaction'      => $data['reaction'],
            'reaction_note' => isset($data['note']) ? trim($data['note']) ?: null : null,
            'reacted_at'    => now(),
        ]);

        // Avisar al adoptante: su esfuerzo fue visto (refuerza que siga compartiendo).
        $emoji = ['heart' => '❤️', 'clap' => '👏', 'smile' => '😊', 'pray' => '🙏'][$data['reaction']];
        $title = "{$emoji} Al rescatista le encantó tu seguimiento de {$listing->name}";
        $body  = $followup->reaction_note
            ? '"' . Str::limit($followup->reaction_note, 120) . '"'
            : 'Gracias por mostrar cómo está hoy.';
        $this->bestEffortNotify($followup->adopter_owner_id, $title, $body, 'adoption_followup_reaction');

        return response()->json([
            'ok'           => true,
            'reaction'     => $followup->reaction,
            'reactionNote' => $followup->reaction_note,
            'reactedAt'    => $followup->reacted_at?->toISOString(),
        ]);
    }

    /** POST /reputation/reviews/{id}/report — reportar una reseña (moderación). */
    public function reportReview(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'reason'  => 'required|in:spam,inappropriate,false,other',
            'details' => 'nullable|string|max:500',
        ]);

        $review = AdoptionReview::find($id);
        if (!$review) {
            return response()->json(['ok' => false], 404);
        }

        // Una misma IP no infla el contador reportando en bucle la misma reseña.
        $already = AdoptionReviewReport::where('review_id', $review->id)
            ->where('ip_address', $request->ip())
            ->where('resolved', false)
            ->exists();
        if ($already) {
            return response()->json(['ok' => true]); // idempotente
        }

        AdoptionReviewReport::create([
            'review_id'         => $review->id,
            'reporter_owner_id' => optional($request->user('sanctum'))->uuid,
            'reason'            => $data['reason'],
            'details'           => $data['details'] ?? null,
            'ip_address'        => $request->ip(),
        ]);

        // 3+ reportes abiertos → flagged para revisión admin (no se oculta solo).
        $count = AdoptionReviewReport::where('review_id', $review->id)->where('resolved', false)->count();
        if ($count >= 3 && $review->moderation_status === 'active') {
            $review->update(['moderation_status' => 'flagged']);
        }

        return response()->json(['ok' => true]);
    }

    /** GET /my-adoption-history — adopciones donde el usuario fue el adoptante. */
    public function myAdoptionHistory(Request $request): JsonResponse
    {
        $me = $request->user()->uuid;

        $listings = AdoptionListing::where('adopted_by_owner_id', $me)
            ->where('status', 'adopted')
            ->with(['owner', 'followups' => fn ($q) => $q->where('adopter_owner_id', $me)])
            ->orderByDesc('adopted_at')
            ->get();

        $reviewedListingIds = AdoptionReview::where('reviewer_owner_id', $me)
            ->whereIn('listing_id', $listings->pluck('id'))
            ->pluck('listing_id')
            ->all();

        return response()->json([
            'data' => $listings->map(function (AdoptionListing $l) use ($reviewedListingIds) {
                $pendingFollowups = $l->followups->where('status', 'requested')->values();
                return [
                    'listingId'        => $l->id,
                    'name'             => $l->name,
                    'species'          => $l->species,
                    'photoUrl'         => $l->photo_url,
                    'adoptedAt'        => $l->adopted_at?->toISOString(),
                    'rescuer' => [
                        'ownerId'     => $l->owner?->id,
                        'name'        => $l->owner?->display_name ?? 'Rescatista',
                        'ratingAvg'   => $l->owner?->rescuer_rating_avg,
                        'ratingCount' => $l->owner?->rescuer_rating_count ?? 0,
                    ],
                    'canReviewRescuer'  => !in_array($l->id, $reviewedListingIds, true),
                    'pendingFollowups'  => $pendingFollowups->map(fn (AdoptionFollowup $f) => [
                        'id'    => $f->id,
                        'dueAt' => $f->due_at?->toISOString(),
                    ]),
                ];
            }),
        ]);
    }

    /** POST /my-adoptions/{id}/followups/request — el rescatista pide seguimiento al adoptante. */
    public function requestFollowup(Request $request, string $id): JsonResponse
    {
        $listing = AdoptionListing::where('id', $id)
            ->where('owner_id', $request->user()->uuid)
            ->firstOrFail();

        if ($listing->status !== 'adopted' || !$listing->adopted_by_owner_id) {
            return response()->json(['error' => 'La adopción no está completada'], 422);
        }

        // Anti-acoso: máximo un seguimiento pendiente a la vez por adopción.
        $hasPending = AdoptionFollowup::where('listing_id', $listing->id)
            ->where('status', 'requested')
            ->exists();
        if ($hasPending) {
            return response()->json([
                'error' => 'Ya hay un seguimiento pendiente; espera a que el adoptante lo entregue',
            ], 422);
        }

        $followup = AdoptionFollowup::create([
            'listing_id'            => $listing->id,
            'adopter_owner_id'      => $listing->adopted_by_owner_id,
            'requested_by_owner_id' => $request->user()->uuid,
            'status'                => 'requested',
            'requested_at'          => now(),
            'due_at'                => now()->addDays(14),
        ]);

        $this->notifyFollowupRequested($listing);

        return response()->json(['ok' => true, 'id' => $followup->id], 201);
    }

    /** POST /adoptions/followups/{id}/submit — el adoptante sube fotos del seguimiento. */
    public function submitFollowup(Request $request, string $id): JsonResponse
    {
        $followup = AdoptionFollowup::findOrFail($id);

        if ($followup->adopter_owner_id !== $request->user()->uuid) {
            return response()->json(['error' => 'No autorizado'], 403);
        }
        if ($followup->status === 'submitted') {
            return response()->json(['error' => 'Este seguimiento ya fue entregado'], 422);
        }

        $request->validate([
            'photos'   => 'required|array|min:1|max:3',
            'photos.*' => 'file|mimetypes:image/jpeg,image/png,image/webp,image/gif|max:5120',
            'note'     => 'nullable|string|max:500',
        ]);

        $owner = $request->user()->uuid;
        $urls  = [];
        foreach ($request->file('photos') as $file) {
            $path   = $file->store("adoption-followups/{$owner}", 'public');
            $urls[] = asset('storage/' . ltrim($path, '/'));
        }

        $followup->update([
            'status'       => 'submitted',
            'photos'       => $urls,
            'note'         => $request->input('note') ? trim($request->input('note')) : null,
            'submitted_at' => now(),
        ]);

        $this->reputation->recompute($owner);

        $listing = $followup->listing;
        if ($listing) {
            $this->notifyFollowupSubmitted($listing);
        }

        return response()->json(['ok' => true, 'photos' => $urls], 201);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function formatReview(AdoptionReview $r, ?string $viewerId): array
    {
        return [
            'id'        => $r->id,
            'listingId' => $r->listing_id,
            'role'      => $r->role,
            'rating'    => $r->rating,
            'comment'   => $r->comment,
            'isMine'    => $viewerId !== null && $r->reviewer_owner_id === $viewerId,
            'createdAt' => $r->created_at?->toISOString(),
        ];
    }

    private function notifyReviewee(string $ownerId, string $role, int $rating, string $petName): void
    {
        $title = $role === 'adopter'
            ? "⭐ Te calificaron como adoptante de {$petName}"
            : "⭐ Te calificaron como rescatista de {$petName}";
        $body = "Recibiste {$rating}/5. Tu reputación en adopciones se actualizó.";

        $this->bestEffortNotify($ownerId, $title, $body, 'adoption_review');
    }

    private function notifyFollowupRequested(AdoptionListing $listing): void
    {
        $this->bestEffortNotify(
            $listing->adopted_by_owner_id,
            "📸 Seguimiento de {$listing->name}",
            "El rescatista te pidió fotos de cómo está {$listing->name}. Súbelas desde tus adopciones.",
            'adoption_followup_request',
        );
    }

    private function notifyFollowupSubmitted(AdoptionListing $listing): void
    {
        $this->bestEffortNotify(
            $listing->owner_id,
            "📸 Nuevo seguimiento de {$listing->name}",
            "El adoptante subió fotos de cómo está {$listing->name}.",
            'adoption_followup_submitted',
        );
    }

    private function bestEffortNotify(string $ownerId, string $title, string $body, string $type): void
    {
        $url = '/dashboard/adopciones';

        try {
            (new PushNotificationService())->sendToOwner($ownerId, $title, $body, ['type' => $type, 'url' => $url]);
        } catch (\Throwable) {
            // best-effort
        }
        try {
            InboxNotification::createForOwner(
                ownerId:   $ownerId,
                title:     $title,
                body:      $body,
                notifType: $type,
                url:       $url,
                tag:       $type . '-' . Str::random(6),
            );
        } catch (\Throwable) {
            // no fatal
        }
    }
}
