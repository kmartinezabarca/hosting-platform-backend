<?php

namespace App\Domains\Pet\Http\Controllers;

use App\Domains\Pet\Models\AdoptionFollowup;
use App\Domains\Pet\Models\AdoptionListing;
use App\Domains\Pet\Models\AdoptionReport;
use App\Domains\Pet\Models\AdoptionRequest;
use App\Domains\Pet\Models\AdoptionReview;
use App\Domains\Pet\Models\AdoptionReviewReport;
use App\Domains\Pet\Models\AppAdmin;
use App\Domains\Pet\Models\InboxNotification;
use App\Domains\Pet\Models\PetPost;
use App\Domains\Pet\Models\PetPostComment;
use App\Domains\Pet\Models\PetPostReport;
use App\Domains\Pet\Services\PushNotificationService;
use App\Domains\Pet\Services\ReputationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * Panel de administración de ROKE PET — control y moderación de adopciones,
 * solicitudes, reseñas, seguimientos y comunidad. Prefijo /api/rp/admin,
 * protegido por Sanctum + app_admins.
 */
class AdminModerationController extends Controller
{
    private function requireAdmin(Request $request): void
    {
        if (! AppAdmin::where('user_id', $request->user()->uuid)->exists()) {
            abort(403, 'Acceso denegado');
        }
    }

    /**
     * Avisa al dueño (bandeja + push) que su contenido fue ocultado por
     * moderación. Best-effort: nunca rompe la acción del admin.
     */
    private function notifyContentHidden(?string $ownerId, string $label): void
    {
        if (! $ownerId) {
            return;
        }

        $title = 'Contenido retirado por moderación';
        $body  = "{$label} fue ocultada por el equipo de ROKE Pet tras una revisión. Si crees que es un error, escríbenos desde el chat de soporte.";

        InboxNotification::createForOwner(
            ownerId:   $ownerId,
            title:     $title,
            body:      $body,
            notifType: 'moderation',
            url:       null,
            tag:       'moderation-' . now()->timestamp,
        );

        try {
            (new PushNotificationService())->sendToOwner($ownerId, $title, $body, ['type' => 'moderation']);
        } catch (\Throwable) {
            // best-effort
        }
    }

    // ══ Adopciones ══════════════════════════════════════════════════════════

    /** GET /admin/adoptions — todas las publicaciones (filtros: status, moderation, q). */
    public function adoptions(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $filters = $request->validate([
            'status' => 'nullable|in:available,reserved,adopted,paused',
            'moderation' => 'nullable|in:active,flagged,hidden',
            'q' => 'nullable|string|max:120',
            'page' => 'nullable|integer|min:1',
        ]);

        $query = AdoptionListing::query()
            ->with(['owner', 'adopter'])
            ->withCount([
                'requests',
                'requests as pending_requests_count' => fn ($q) => $q->where('status', 'pending'),
                'reviews',
                'followups',
            ])
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['moderation'])) {
            $query->where('moderation_status', $filters['moderation']);
        }
        if (! empty($filters['q'])) {
            $q = $filters['q'];
            $query->where(fn ($w) => $w->where('name', 'like', "%$q%")->orWhere('city', 'like', "%$q%"));
        }

        $page = $query->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (AdoptionListing $l) => $this->listingRow($l)),
            'meta' => $this->meta($page),
        ]);
    }

    /** GET /admin/adoptions/{id} — detalle completo (solicitudes, reseñas, seguimientos). */
    public function adoptionDetail(Request $request, string $id): JsonResponse
    {
        $this->requireAdmin($request);

        $listing = AdoptionListing::with(['owner', 'adopter'])->findOrFail($id);

        $requests = AdoptionRequest::where('listing_id', $listing->id)
            ->orderByDesc('created_at')->get()
            ->map(fn (AdoptionRequest $r) => [
                'id' => $r->id,
                'name' => $r->requester_name,
                'contact' => $r->requester_contact,
                'message' => $r->message,
                'status' => $r->status,
                'ownerId' => $r->requester_owner_id,
                'createdAt' => $r->created_at?->toISOString(),
            ]);

        $reviews = AdoptionReview::where('listing_id', $listing->id)
            ->with('reviewer')
            ->orderByDesc('created_at')->get()
            ->map(fn (AdoptionReview $r) => $this->reviewRow($r));

        $followups = AdoptionFollowup::where('listing_id', $listing->id)
            ->orderByDesc('created_at')->get()
            ->map(fn (AdoptionFollowup $f) => [
                'id' => $f->id,
                'status' => $f->status,
                'photos' => $f->photos ?? [],
                'note' => $f->note,
                'reaction' => $f->reaction,
                'reactionNote' => $f->reaction_note,
                'dueAt' => $f->due_at?->toISOString(),
                'submittedAt' => $f->submitted_at?->toISOString(),
            ]);

        $reports = AdoptionReport::where('listing_id', $listing->id)
            ->orderByDesc('created_at')->get()
            ->map(fn (AdoptionReport $r) => $this->reportRow($r));

        return response()->json([
            'listing' => $this->listingRow($listing, detail: true),
            'requests' => $requests,
            'reviews' => $reviews,
            'followups' => $followups,
            'reports' => $reports,
        ]);
    }

    /** PATCH /admin/adoptions/{id}/moderation — active|flagged|hidden. */
    public function moderateAdoption(Request $request, string $id): JsonResponse
    {
        $this->requireAdmin($request);
        $data = $request->validate(['moderationStatus' => 'required|in:active,flagged,hidden']);

        $listing = AdoptionListing::findOrFail($id);
        $listing->update(['moderation_status' => $data['moderationStatus']]);

        // Al resolver (ocultar o reactivar), se cierran los reportes abiertos.
        if (in_array($data['moderationStatus'], ['hidden', 'active'], true)) {
            AdoptionReport::where('listing_id', $listing->id)->where('resolved', false)->update(['resolved' => true]);
        }

        if ($data['moderationStatus'] === 'hidden') {
            $this->notifyContentHidden($listing->owner_id, 'Tu publicación de adopción');
        }

        return response()->json(['ok' => true, 'moderationStatus' => $listing->moderation_status]);
    }

    // ══ Reseñas ═════════════════════════════════════════════════════════════

    /** GET /admin/reviews — todas las reseñas (filtros: moderation, role). */
    public function reviews(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $filters = $request->validate([
            'moderation' => 'nullable|in:active,flagged,hidden',
            'role' => 'nullable|in:adopter,rescuer',
            'page' => 'nullable|integer|min:1',
        ]);

        $query = AdoptionReview::with(['reviewer', 'listing'])
            ->withCount(['reports as open_reports_count' => fn ($q) => $q->where('resolved', false)])
            ->orderByRaw("CASE WHEN moderation_status = 'flagged' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at');

        if (! empty($filters['moderation'])) {
            $query->where('moderation_status', $filters['moderation']);
        }
        if (! empty($filters['role'])) {
            $query->where('role', $filters['role']);
        }

        $page = $query->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (AdoptionReview $r) => $this->reviewRow($r, withReports: true)),
            'meta' => $this->meta($page),
        ]);
    }

    /** PATCH /admin/reviews/{id}/moderation — ocultar/restaurar y recalcular reputación. */
    public function moderateReview(Request $request, string $id): JsonResponse
    {
        $this->requireAdmin($request);
        $data = $request->validate(['moderationStatus' => 'required|in:active,flagged,hidden']);

        $review = AdoptionReview::findOrFail($id);
        $review->update(['moderation_status' => $data['moderationStatus']]);

        if (in_array($data['moderationStatus'], ['hidden', 'active'], true)) {
            AdoptionReviewReport::where('review_id', $review->id)->where('resolved', false)->update(['resolved' => true]);
        }

        if ($data['moderationStatus'] === 'hidden') {
            $this->notifyContentHidden($review->reviewer_owner_id, 'Tu reseña');
        }

        // La reputación del evaluado cambia si se oculta/restaura una reseña.
        (new ReputationService)->recompute($review->reviewee_owner_id);

        return response()->json(['ok' => true, 'moderationStatus' => $review->moderation_status]);
    }

    // ══ Comunidad ═══════════════════════════════════════════════════════════

    /** GET /admin/community/posts — publicaciones (filtro moderation). */
    public function communityPosts(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $filters = $request->validate([
            'moderation' => 'nullable|in:active,flagged,hidden',
            'page' => 'nullable|integer|min:1',
        ]);

        $query = PetPost::with(['owner', 'pet'])
            ->withCount(['reports as open_reports_count' => fn ($q) => $q->where('resolved', false)])
            ->orderByRaw("CASE WHEN moderation_status = 'flagged' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at');

        if (! empty($filters['moderation'])) {
            $query->where('moderation_status', $filters['moderation']);
        }

        $page = $query->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (PetPost $p) => $this->postRow($p)),
            'meta' => $this->meta($page),
        ]);
    }

    /** PATCH /admin/community/posts/{id}/moderation. */
    public function moderatePost(Request $request, string $id): JsonResponse
    {
        $this->requireAdmin($request);
        $data = $request->validate(['moderationStatus' => 'required|in:active,flagged,hidden']);

        $post = PetPost::findOrFail($id);
        $post->update(['moderation_status' => $data['moderationStatus']]);

        if (in_array($data['moderationStatus'], ['hidden', 'active'], true)) {
            PetPostReport::where('post_id', $post->id)->where('resolved', false)->update(['resolved' => true]);
        }

        if ($data['moderationStatus'] === 'hidden') {
            $this->notifyContentHidden($post->owner_id, 'Tu publicación en la comunidad');
        }

        return response()->json(['ok' => true, 'moderationStatus' => $post->moderation_status]);
    }

    /** GET /admin/community/posts/{id}/comments — comentarios de un post. */
    public function postComments(Request $request, string $id): JsonResponse
    {
        $this->requireAdmin($request);

        $comments = PetPostComment::where('post_id', $id)
            ->with('owner')
            ->orderBy('created_at')
            ->get()
            ->map(fn (PetPostComment $c) => [
                'id' => $c->id,
                'parentId' => $c->parent_id,
                'body' => $c->body,
                'author' => $c->owner?->display_name ?? 'Alguien',
                'createdAt' => $c->created_at?->toISOString(),
            ]);

        return response()->json(['data' => $comments]);
    }

    /** DELETE /admin/community/comments/{id} — eliminar comentario (con sus respuestas). */
    public function deleteComment(Request $request, string $id): JsonResponse
    {
        $this->requireAdmin($request);

        $comment = PetPostComment::findOrFail($id);
        $removed = 1 + ($comment->parent_id ? 0 : $comment->replies()->count());

        if ($comment->parent_id) {
            PetPostComment::where('id', $comment->parent_id)->where('replies_count', '>', 0)->decrement('replies_count');
        }
        $post = PetPost::find($comment->post_id);
        $comment->delete();
        if ($post) {
            $post->decrement('comments_count', min($removed, max(1, $post->comments_count)));
        }

        return response()->json(['ok' => true, 'removed' => $removed]);
    }

    // ══ Cola de reportes ════════════════════════════════════════════════════

    /** GET /admin/moderation-queue — todo lo flagged o con reportes abiertos. */
    public function moderationQueue(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $listings = AdoptionListing::with('owner')
            ->where(fn ($q) => $q->where('moderation_status', 'flagged')
                ->orWhereHas('reports', fn ($r) => $r->where('resolved', false)))
            ->withCount(['reports as open_reports_count' => fn ($q) => $q->where('resolved', false)])
            ->orderByDesc('updated_at')->limit(50)->get()
            ->map(fn (AdoptionListing $l) => [
                'type' => 'adoption',
                'id' => $l->id,
                'title' => $l->name,
                'subtitle' => $l->owner?->display_name ?? 'Dueño',
                'moderation' => $l->moderation_status,
                'openReports' => $l->open_reports_count,
                'createdAt' => $l->created_at?->toISOString(),
            ]);

        $posts = PetPost::with(['owner', 'pet'])
            ->where(fn ($q) => $q->where('moderation_status', 'flagged')
                ->orWhereHas('reports', fn ($r) => $r->where('resolved', false)))
            ->withCount(['reports as open_reports_count' => fn ($q) => $q->where('resolved', false)])
            ->orderByDesc('updated_at')->limit(50)->get()
            ->map(fn (PetPost $p) => [
                'type' => 'post',
                'id' => $p->id,
                'title' => $p->pet?->name ? "Foto de {$p->pet->name}" : 'Publicación',
                'subtitle' => $p->owner?->display_name ?? 'Dueño',
                'moderation' => $p->moderation_status,
                'openReports' => $p->open_reports_count,
                'createdAt' => $p->created_at?->toISOString(),
            ]);

        $reviews = AdoptionReview::with('reviewer')
            ->where(fn ($q) => $q->where('moderation_status', 'flagged')
                ->orWhereHas('reports', fn ($r) => $r->where('resolved', false)))
            ->withCount(['reports as open_reports_count' => fn ($q) => $q->where('resolved', false)])
            ->orderByDesc('updated_at')->limit(50)->get()
            ->map(fn (AdoptionReview $r) => [
                'type' => 'review',
                'id' => $r->id,
                'title' => '“'.Str::limit($r->comment ?? 'Sin comentario', 60).'”',
                'subtitle' => ($r->reviewer?->display_name ?? 'Alguien').' · '.$r->rating.'★',
                'moderation' => $r->moderation_status,
                'openReports' => $r->open_reports_count,
                'createdAt' => $r->created_at?->toISOString(),
            ]);

        $queue = $listings->concat($posts)->concat($reviews)
            ->sortByDesc('openReports')->values();

        return response()->json([
            'data' => $queue,
            'total' => $queue->count(),
        ]);
    }

    // ── Helpers de formato ────────────────────────────────────────────────────

    private function listingRow(AdoptionListing $l, bool $detail = false): array
    {
        $row = [
            'id' => $l->id,
            'slug' => $l->slug,
            'name' => $l->name,
            'species' => $l->species,
            'breed' => $l->breed,
            'gender' => $l->gender,
            'birthDate' => $l->birth_date?->toDateString(),
            'ageLabel' => $l->display_age_label,
            'size' => $l->size,
            'photoUrl' => $l->photo_url,
            'city' => $l->city,
            'state' => $l->state,
            'status' => $l->status,
            'moderationStatus' => $l->moderation_status,
            'isPublished' => $l->is_published,
            'ownerId' => $l->owner?->id,
            'ownerName' => $l->owner?->display_name ?? 'Dueño',
            'ownerEmail' => $l->owner?->email,
            'adopterName' => $l->adopter?->display_name,
            'requestsCount' => $l->requests_count ?? 0,
            'pendingRequests' => $l->pending_requests_count ?? 0,
            'reviewsCount' => $l->reviews_count ?? 0,
            'followupsCount' => $l->followups_count ?? 0,
            'viewsCount' => $l->views_count,
            'createdAt' => $l->created_at?->toISOString(),
        ];
        if ($detail) {
            $row['description'] = $l->description;
            $row['photos'] = $l->photos ?? [];
            $row['requirements'] = $l->requirements;
            $row['adoptedAt'] = $l->adopted_at?->toISOString();
        }

        return $row;
    }

    private function reviewRow(AdoptionReview $r, bool $withReports = false): array
    {
        $row = [
            'id' => $r->id,
            'listingId' => $r->listing_id,
            'listingName' => $r->listing?->name,
            'role' => $r->role,
            'rating' => $r->rating,
            'comment' => $r->comment,
            'author' => $r->reviewer?->display_name ?? 'Alguien',
            'revieweeOwnerId' => $r->reviewee_owner_id,
            'moderationStatus' => $r->moderation_status,
            'createdAt' => $r->created_at?->toISOString(),
        ];
        if ($withReports) {
            $row['openReports'] = $r->open_reports_count ?? 0;
        }

        return $row;
    }

    private function postRow(PetPost $p): array
    {
        return [
            'id' => $p->id,
            'caption' => $p->caption,
            'media' => $p->media ?? [],
            'likesCount' => $p->likes_count,
            'commentsCount' => $p->comments_count,
            'moderationStatus' => $p->moderation_status,
            'ownerName' => $p->owner?->display_name ?? 'Dueño',
            'petName' => $p->pet?->name,
            'openReports' => $p->open_reports_count ?? 0,
            'createdAt' => $p->created_at?->toISOString(),
        ];
    }

    private function reportRow(AdoptionReport $r): array
    {
        return [
            'id' => $r->id,
            'reason' => $r->reason,
            'details' => $r->details,
            'resolved' => $r->resolved,
            'createdAt' => $r->created_at?->toISOString(),
        ];
    }

    /** @param LengthAwarePaginator $page */
    private function meta($page): array
    {
        return [
            'total' => $page->total(),
            'currentPage' => $page->currentPage(),
            'lastPage' => $page->lastPage(),
        ];
    }
}
