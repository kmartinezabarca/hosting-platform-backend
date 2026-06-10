<?php

namespace App\Domains\Platform\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\BlogCommentRequest;
use App\Http\Resources\BlogCommentResource;
use App\Domains\Platform\Models\BlogComment;
use App\Domains\Platform\Models\BlogPost;
use Illuminate\Http\JsonResponse;

class BlogCommentController extends Controller
{
    /**
     * Lista los comentarios aprobados de un post (orden cronológico).
     */
    public function index(string $slug): JsonResponse
    {
        $post = $this->resolvePost($slug);

        $comments = $post->comments()
            ->approved()
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => BlogCommentResource::collection($comments),
        ]);
    }

    /**
     * Crea un comentario. Queda pendiente de aprobación (is_approved = false).
     * Anti-spam: Turnstile + honeypot (en el FormRequest) + throttle (en la ruta).
     */
    public function store(BlogCommentRequest $request, string $slug): JsonResponse
    {
        $post = $this->resolvePost($slug);

        $comment = $post->comments()->create([
            'author_name' => $request->validated('author_name'),
            'author_email' => $request->validated('author_email'),
            'content' => $request->validated('content'),
            'is_approved' => false,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tu comentario fue recibido y se publicará tras ser revisado.',
            'data' => new BlogCommentResource($comment),
        ], 201);
    }

    private function resolvePost(string $slug): BlogPost
    {
        return BlogPost::where('slug', $slug)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->firstOrFail();
    }
}
