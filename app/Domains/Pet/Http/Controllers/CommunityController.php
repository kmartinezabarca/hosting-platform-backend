<?php

namespace App\Domains\Pet\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Domains\Pet\Models\InboxNotification;
use App\Domains\Pet\Models\Pet;
use App\Domains\Pet\Models\PetPost;
use App\Domains\Pet\Models\PetPostComment;
use App\Domains\Pet\Models\PetPostLike;
use App\Domains\Pet\Models\PetPostReport;
use App\Domains\Pet\Services\PushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Comunidad ROKE PET — red social de mascotas (fotos/video corto).
 *
 * Ver el feed es público (marketing + comunidad abierta); publicar, dar
 * me gusta y comentar requiere cuenta. Cada publicación pertenece a una
 * mascota del dueño (la mascota ES el perfil, no la persona).
 */
class CommunityController extends Controller
{
    private const PAGE_SIZE = 12;
    private const MAX_MEDIA = 4;

    /** GET /community/feed — feed público paginado (likedByMe si hay sesión). */
    public function feed(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'petId' => 'nullable|uuid',
        ]);

        $query = PetPost::query()
            ->where('moderation_status', '!=', 'hidden')
            ->with(['pet', 'owner'])
            ->orderByDesc('created_at');

        if (!empty($filters['petId'])) {
            $query->where('pet_id', $filters['petId']);
        }

        $posts = $query->paginate(self::PAGE_SIZE);

        // likedByMe en bloque para la página (sin N+1), solo si hay sesión.
        $viewer   = $request->user('sanctum');
        $likedIds = [];
        if ($viewer) {
            $likedIds = PetPostLike::where('owner_id', $viewer->uuid)
                ->whereIn('post_id', collect($posts->items())->pluck('id'))
                ->pluck('post_id')
                ->all();
        }

        return response()->json([
            'data' => collect($posts->items())->map(
                fn (PetPost $p) => $this->formatPost($p, $viewer?->uuid, in_array($p->id, $likedIds, true)),
            ),
            'meta' => [
                'total'       => $posts->total(),
                'currentPage' => $posts->currentPage(),
                'lastPage'    => $posts->lastPage(),
            ],
        ]);
    }

    /** GET /community/posts/{id}/comments — comentarios paginados (público). */
    public function comments(Request $request, string $id): JsonResponse
    {
        $post = PetPost::where('id', $id)->where('moderation_status', '!=', 'hidden')->firstOrFail();

        $comments = PetPostComment::where('post_id', $post->id)
            ->with('owner')
            ->orderBy('created_at', 'asc')
            ->paginate(30);

        $viewer = $request->user('sanctum');

        return response()->json([
            'data' => collect($comments->items())->map(fn ($c) => $this->formatComment($c, $viewer?->uuid, $post)),
            'meta' => [
                'total'       => $comments->total(),
                'currentPage' => $comments->currentPage(),
                'lastPage'    => $comments->lastPage(),
            ],
        ]);
    }

    /** POST /community/posts — crear publicación (multipart: petId, caption, media[]). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'petId'   => 'required|uuid',
            'caption' => 'nullable|string|max:2000',
            'media'   => 'required|array|min:1|max:' . self::MAX_MEDIA,
            'media.*' => 'file|mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm|max:25600',
        ]);

        $owner = $request->user();
        $pet   = Pet::where('id', $data['petId'])->where('owner_id', $owner->uuid)->first();
        if (!$pet) {
            return response()->json(['error' => 'La mascota no existe o no es tuya'], 422);
        }

        // Máximo 1 video por publicación; las imágenes ≤ 5MB (el límite de 25MB es para video).
        $media      = [];
        $videoCount = 0;
        foreach ($data['media'] as $file) {
            $isVideo = str_starts_with($file->getMimeType(), 'video/');
            if ($isVideo && ++$videoCount > 1) {
                return response()->json(['error' => 'Máximo un video por publicación'], 422);
            }
            if (!$isVideo && $file->getSize() > 5 * 1024 * 1024) {
                return response()->json(['error' => 'Cada imagen debe pesar máximo 5MB'], 422);
            }
            $path    = $file->store("community/{$owner->uuid}", 'public');
            $media[] = [
                'type' => $isVideo ? 'video' : 'image',
                'url'  => asset('storage/' . ltrim($path, '/')),
            ];
        }

        $post = PetPost::create([
            'owner_id' => $owner->uuid,
            'pet_id'   => $pet->id,
            'caption'  => trim((string) ($data['caption'] ?? '')) ?: null,
            'media'    => $media,
        ]);
        $post->setRelation('pet', $pet);
        $post->setRelation('owner', $owner);

        return response()->json($this->formatPost($post, $owner->uuid, false), 201);
    }

    /** DELETE /community/posts/{id} — eliminar publicación propia. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        PetPost::where('id', $id)->where('owner_id', $request->user()->uuid)->firstOrFail()->delete();
        return response()->json(['ok' => true]);
    }

    /** POST /community/posts/{id}/like — alternar me gusta. */
    public function toggleLike(Request $request, string $id): JsonResponse
    {
        $post  = PetPost::where('id', $id)->where('moderation_status', '!=', 'hidden')->firstOrFail();
        $owner = $request->user();

        $existing = PetPostLike::where('post_id', $post->id)->where('owner_id', $owner->uuid)->first();

        if ($existing) {
            $existing->delete();
            $post->decrement('likes_count');
            $liked = false;
        } else {
            PetPostLike::firstOrCreate(['post_id' => $post->id, 'owner_id' => $owner->uuid]);
            $post->increment('likes_count');
            $liked = true;
        }

        return response()->json(['liked' => $liked, 'likesCount' => max(0, $post->fresh()->likes_count)]);
    }

    /** POST /community/posts/{id}/comments — comentar (notifica al dueño del post). */
    public function storeComment(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['body' => 'required|string|max:1000']);

        $post  = PetPost::with('pet')->where('id', $id)->where('moderation_status', '!=', 'hidden')->firstOrFail();
        $owner = $request->user();

        $comment = PetPostComment::create([
            'post_id'  => $post->id,
            'owner_id' => $owner->uuid,
            'body'     => trim($data['body']),
        ]);
        $post->increment('comments_count');
        $comment->setRelation('owner', $owner);

        if ($post->owner_id !== $owner->uuid) {
            $this->notifyOwnerOnComment($post, $owner->display_name ?? 'Alguien', $comment->body);
        }

        return response()->json($this->formatComment($comment, $owner->uuid, $post), 201);
    }

    /** DELETE /community/posts/{id}/comments/{commentId} — autor del comentario o dueño del post. */
    public function destroyComment(Request $request, string $id, string $commentId): JsonResponse
    {
        $post    = PetPost::findOrFail($id);
        $comment = PetPostComment::where('id', $commentId)->where('post_id', $post->id)->firstOrFail();
        $viewer  = $request->user()->uuid;

        if ($comment->owner_id !== $viewer && $post->owner_id !== $viewer) {
            return response()->json(['error' => 'No autorizado'], 403);
        }

        $comment->delete();
        $post->decrement('comments_count');

        return response()->json(['ok' => true]);
    }

    /** POST /community/posts/{id}/report — reporte de moderación (público). */
    public function report(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'reason'  => 'required|in:spam,inappropriate,other',
            'details' => 'nullable|string|max:500',
        ]);

        $post = PetPost::find($id);
        if (!$post) {
            return response()->json(['ok' => false], 404);
        }

        PetPostReport::create([
            'post_id'    => $post->id,
            'reason'     => $data['reason'],
            'details'    => $data['details'] ?? null,
            'ip_address' => $request->ip(),
        ]);

        // Igual que adopción: 3+ reportes abiertos → flagged para revisión admin.
        $count = PetPostReport::where('post_id', $post->id)->where('resolved', false)->count();
        if ($count >= 3 && $post->moderation_status === 'active') {
            $post->update(['moderation_status' => 'flagged']);
        }

        return response()->json(['ok' => true]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function notifyOwnerOnComment(PetPost $post, string $commenterName, string $body): void
    {
        $petName = $post->pet?->name ?? 'tu mascota';
        $title   = "💬 Nuevo comentario en la foto de {$petName}";
        $text    = "{$commenterName}: \"" . Str::limit($body, 120) . '"';

        try {
            (new PushNotificationService())->sendToOwner(
                $post->owner_id,
                $title,
                $text,
                ['type' => 'post_comment', 'postId' => $post->id],
            );
        } catch (\Throwable) {
            // best-effort
        }

        try {
            InboxNotification::createForOwner(
                ownerId:   $post->owner_id,
                title:     $title,
                body:      $text,
                notifType: 'post_comment',
                url:       '/comunidad',
                tag:       'post-comment-' . $post->id,
            );
        } catch (\Throwable) {
            // no fatal
        }
    }

    private function formatPost(PetPost $p, ?string $viewerId, bool $likedByMe): array
    {
        return [
            'id'            => $p->id,
            'caption'       => $p->caption,
            'media'         => $p->media ?? [],
            'likesCount'    => $p->likes_count,
            'commentsCount' => $p->comments_count,
            'likedByMe'     => $likedByMe,
            'isMine'        => $viewerId !== null && $p->owner_id === $viewerId,
            'createdAt'     => $p->created_at?->toISOString(),
            'pet' => [
                'id'       => $p->pet?->id,
                'name'     => $p->pet?->name ?? 'Mascota',
                'species'  => $p->pet?->species ?? 'other',
                'photoUrl' => $p->pet?->photo_url,
                'slug'     => $p->pet?->public_profile_enabled ? $p->pet?->slug : null,
            ],
            'ownerName' => $p->owner?->display_name ?? 'Dueño',
        ];
    }

    private function formatComment(PetPostComment $c, ?string $viewerId, PetPost $post): array
    {
        return [
            'id'        => $c->id,
            'body'      => $c->body,
            'createdAt' => $c->created_at?->toISOString(),
            'author'    => $c->owner?->display_name ?? 'Alguien',
            'isMine'    => $viewerId !== null && $c->owner_id === $viewerId,
            'canDelete' => $viewerId !== null && ($c->owner_id === $viewerId || $post->owner_id === $viewerId),
        ];
    }
}
