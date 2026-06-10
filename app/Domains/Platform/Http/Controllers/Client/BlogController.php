<?php

namespace App\Domains\Platform\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\BlogCategoryResource;
use App\Http\Resources\BlogPostResource;
use App\Domains\Platform\Models\BlogCategory;
use App\Domains\Platform\Models\BlogPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlogController extends Controller
{
    /**
     * Display a listing of the blog posts.
     */
    public function index(Request $request): JsonResponse
    {
        $query = BlogPost::with(['category', 'author'])
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());

        if ($request->has('category_slug')) {
            $query->whereHas('category', function ($q) use ($request) {
                $q->where('slug', $request->category_slug);
            });
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', '%' . $search . '%')
                    ->orWhere('excerpt', 'like', '%' . $search . '%')
                    ->orWhere('content', 'like', '%' . $search . '%');
            });
        }

        $posts = $query->orderBy('published_at', 'desc')->paginate(10);

        return response()->json([
            'success' => true,
            'data' => BlogPostResource::collection($posts),
            'meta' => [
                'total' => $posts->total(),
                'perPage' => $posts->perPage(),
                'currentPage' => $posts->currentPage(),
                'lastPage' => $posts->lastPage(),
            ],
        ]);
    }

    /**
     * Display the specified blog post.
     */
    public function show(string $slug): JsonResponse
    {
        $post = BlogPost::with(['category', 'author'])
            ->withCount(['comments' => fn ($q) => $q->where('is_approved', true)])
            ->where('slug', $slug)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->firstOrFail();

        // Conteo de vistas (no bloqueante para la respuesta).
        $post->increment('views');

        return response()->json([
            'success' => true,
            'data' => new BlogPostResource($post),
        ]);
    }

    /**
     * Incrementa el contador de "me gusta" de un post.
     * Sin login: la des-duplicación por usuario se maneja en el cliente
     * (localStorage). El throttle de la ruta limita el abuso.
     */
    public function like(string $slug): JsonResponse
    {
        $post = BlogPost::where('slug', $slug)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->firstOrFail();

        $post->increment('likes');

        return response()->json([
            'success' => true,
            'data' => ['likes' => (int) $post->likes],
        ]);
    }

    /**
     * Decrementa el contador de "me gusta" (cuando el usuario quita su like).
     */
    public function unlike(string $slug): JsonResponse
    {
        $post = BlogPost::where('slug', $slug)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->firstOrFail();

        if ($post->likes > 0) {
            $post->decrement('likes');
        }

        return response()->json([
            'success' => true,
            'data' => ['likes' => (int) $post->likes],
        ]);
    }

    /**
     * Display a listing of featured blog posts.
     */
    public function featuredPosts(): JsonResponse
    {
        $posts = BlogPost::with(['category', 'author'])
            ->where('is_featured', true)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderBy('published_at', 'desc')
            ->limit(3) // Adjust as needed
            ->get();

        return response()->json([
            'success' => true,
            'data' => BlogPostResource::collection($posts),
        ]);
    }

    /**
     * Display a listing of blog categories.
     */
    public function categories(): JsonResponse
    {
        $categories = BlogCategory::where('is_active', true)
            ->orderBy('sort_order', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => BlogCategoryResource::collection($categories),
        ]);
    }

    /**
     * Display a listing of blog posts by category slug.
     */
    public function postsByCategory(string $categorySlug): JsonResponse
    {
        $category = BlogCategory::where('slug', $categorySlug)->where('is_active', true)->firstOrFail();

        $posts = BlogPost::with(['category', 'author'])
            ->where('blog_category_id', $category->id)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderBy('published_at', 'desc')
            ->paginate(10);

        return response()->json([
            'success' => true,
            'data' => BlogPostResource::collection($posts),
            'meta' => [
                'total' => $posts->total(),
                'perPage' => $posts->perPage(),
                'currentPage' => $posts->currentPage(),
                'lastPage' => $posts->lastPage(),
            ],
        ]);
    }
}
