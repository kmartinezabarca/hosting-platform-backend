<?php

namespace App\Domains\Platform\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\BlogCommentAdminResource;
use App\Domains\Platform\Models\BlogComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlogCommentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = BlogComment::with('post');

        // Filtro por estado: pending | approved (default: todos)
        if ($request->input('status') === 'pending') {
            $query->where('is_approved', false);
        } elseif ($request->input('status') === 'approved') {
            $query->where('is_approved', true);
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('author_name', 'like', "%{$search}%")
                    ->orWhere('author_email', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%");
            });
        }

        $comments = $query->orderBy('created_at', 'desc')->paginate(15);

        return response()->json(
            BlogCommentAdminResource::collection($comments)->response()->getData(true)
        );
    }

    public function show(string $uuid): JsonResponse
    {
        $comment = BlogComment::with('post')->where('uuid', $uuid)->firstOrFail();

        return response()->json(new BlogCommentAdminResource($comment));
    }

    public function approve(string $uuid): JsonResponse
    {
        $comment = BlogComment::where('uuid', $uuid)->firstOrFail();
        $comment->update(['is_approved' => true]);

        return response()->json([
            'message' => 'Comentario aprobado.',
            'data' => new BlogCommentAdminResource($comment),
        ]);
    }

    public function reject(string $uuid): JsonResponse
    {
        $comment = BlogComment::where('uuid', $uuid)->firstOrFail();
        $comment->update(['is_approved' => false]);

        return response()->json([
            'message' => 'Comentario marcado como no aprobado.',
            'data' => new BlogCommentAdminResource($comment),
        ]);
    }

    public function destroy(string $uuid): JsonResponse
    {
        $comment = BlogComment::where('uuid', $uuid)->firstOrFail();
        $comment->delete();

        return response()->json(['message' => 'Comentario eliminado.'], 204);
    }
}
