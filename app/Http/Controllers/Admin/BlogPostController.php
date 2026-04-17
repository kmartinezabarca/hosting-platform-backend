<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BlogPostRequest;
use App\Http\Resources\BlogPostResource;
use App\Models\BlogPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BlogPostController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = BlogPost::with(['category', 'author']);

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', '%' . $search . '%')
                  ->orWhere('excerpt', 'like', '%' . $search . '%');
            });
        }

        if ($request->has('category_id')) {
            // category_id is sent as a UUID; resolve to integer FK
            $cat = \App\Models\BlogCategory::where('uuid', $request->category_id)->first();
            if ($cat) {
                $query->where('blog_category_id', $cat->id);
            }
        }

        $posts = $query->orderBy('created_at', 'desc')->paginate(10);

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
     * Store a newly created resource in storage.
     */
    public function store(BlogPostRequest $request): JsonResponse
    {
        $data = $request->validated();
        
        // Si no se proporciona un author_name, usar el nombre del usuario autenticado o 'ROKE Industries'
        if (empty($data["author_name"])) {
            $data["author_name"] = auth()->check() ? auth()->user()->name : 'ROKE Industries';
        }

        // Asignar user_id si hay un usuario autenticado y no se ha proporcionado explícitamente
        if (auth()->check() && (empty($data["user_id"]) || is_null($data["user_id"]))) {
            $data["user_id"] = auth()->id();
        }

        if (empty($data["slug"])) {
            $data["slug"] = Str::slug($request->title);
        }

        // Convertir blog_category_id (UUID) a ID interno
        $category = \App\Models\BlogCategory::where("uuid", $data["blog_category_id"])->firstOrFail();
        $data["blog_category_id"] = $category->id;

        if ($request->hasFile("image")) {
            $data["image"] = $request->file("image")->store("blog_images", "public");
        }

        $post = BlogPost::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Publicación de blog creada exitosamente.',
            'data' => new BlogPostResource($post),
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $uuid): JsonResponse
    {
        $post = BlogPost::with(['category', 'author'])->where('uuid', $uuid)->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => new BlogPostResource($post),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(BlogPostRequest $request, string $uuid): JsonResponse
    {
        $post = BlogPost::where("uuid", $uuid)->firstOrFail();
        $data = $request->validated();
        
        // Si no se proporciona un author_name, usar el nombre del usuario autenticado o 'ROKE Industries'
        if (empty($data["author_name"])) {
            $data["author_name"] = auth()->check() ? auth()->user()->name : 'ROKE Industries';
        }

        // Asignar user_id si hay un usuario autenticado y no se ha proporcionado explícitamente
        if (auth()->check() && (empty($data["user_id"]) || is_null($data["user_id"]))) {
            $data["user_id"] = auth()->id();
        }

        if (empty($data["slug"])) {
            $data["slug"] = Str::slug($request->title);
        }

        // Convertir blog_category_id (UUID) a ID interno
        $category = \App\Models\BlogCategory::where("uuid", $data["blog_category_id"])->firstOrFail();
        $data["blog_category_id"] = $category->id;

        if ($request->hasFile("image")) {
            if ($post->image) {
                Storage::disk("public")->delete($post->image);
            }
            $data["image"] = $request->file("image")->store("blog_images", "public");
        }

        $post->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Publicación de blog actualizada exitosamente.',
            'data' => new BlogPostResource($post->load(['category', 'author'])),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $uuid): JsonResponse
    {
        $post = BlogPost::where('uuid', $uuid)->firstOrFail();

        if ($post->image) {
            Storage::disk('public')->delete($post->image);
        }

        $post->delete();

        return response()->json([
            'success' => true,
            'message' => 'Publicación de blog eliminada exitosamente.',
        ]);
    }

    /**
     * Handle image upload from editor.
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('blog_content_images', 'public');
            return response()->json([
                'success' => true,
                'url' => asset('storage/' . $path),
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'No se pudo cargar la imagen.',
        ], 400);
    }
}
