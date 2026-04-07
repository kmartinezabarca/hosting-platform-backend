<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BlogCategoryRequest;
use App\Http\Resources\BlogCategoryResource;
use App\Models\BlogCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BlogCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = BlogCategory::query();

        if ($request->has("search")) {
            $search = $request->search;
            $query->where("name", "like", "%" . $search . "%")
                ->orWhere("description", "like", "%" . $search . "%");
        }

        $categories = $query->orderBy("sort_order", "asc")->paginate(10);

        return response()->json([
            "success" => true,
            "data" => BlogCategoryResource::collection($categories),
            "meta" => [
                "total" => $categories->total(),
                "perPage" => $categories->perPage(),
                "currentPage" => $categories->currentPage(),
                "lastPage" => $categories->lastPage(),
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(BlogCategoryRequest $request): JsonResponse
    {
        $category = BlogCategory::create(array_merge($request->validated(), [
            "slug" => Str::slug($request->name),
        ]));

        return response()->json([
            "success" => true,
            "message" => "Categoría de blog creada exitosamente.",
            "data" => new BlogCategoryResource($category),
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $uuid): JsonResponse
    {
        $category = BlogCategory::where("uuid", $uuid)->firstOrFail();

        return response()->json([
            "success" => true,
            "data" => new BlogCategoryResource($category),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(BlogCategoryRequest $request, string $uuid): JsonResponse
    {
        $category = BlogCategory::where("uuid", $uuid)->firstOrFail();
        $category->update(array_merge($request->validated(), [
            "slug" => Str::slug($request->name),
        ]));

        return response()->json([
            "success" => true,
            "message" => "Categoría de blog actualizada exitosamente.",
            "data" => new BlogCategoryResource($category),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $uuid): JsonResponse
    {
        $category = BlogCategory::where("uuid", $uuid)->firstOrFail();
        $category->delete();

        return response()->json([
            "success" => true,
            "message" => "Categoría de blog eliminada exitosamente.",
        ]);
    }
}
