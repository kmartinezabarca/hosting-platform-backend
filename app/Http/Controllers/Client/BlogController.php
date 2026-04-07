<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\BlogCategoryResource;
use App\Http\Resources\BlogPostResource;
use App\Models\BlogCategory;
use App\Models\BlogPost;
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
            ->where('slug', $slug)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => new BlogPostResource($post),
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
