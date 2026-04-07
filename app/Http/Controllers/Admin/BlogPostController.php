<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BlogPostRequest;
use App\Http\Resources\BlogPostResource;
use App\Models\BlogPost;
use Illuminate\Http\JsonResponse;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BlogPostController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function store(BlogPostRequest $request): JsonResponse
    {
        $query = BlogPost::with([\'category\', \'author\']);

        if ($request->has(\'search\')) {
            $search = $request->search;
            $query->where(\'title\', \'like\', \'%\' . $search . \'%\')
                ->orWhere(\'excerpt\', \'like\', \'%\' . $search . \'%\');
        }

        if ($request->has(\'category_id\')) {
            $query->where(\'blog_category_id\', $request->category_id);
        }

        $posts = $query->orderBy(\'created_at\', \'desc\')->paginate(10);

        return response()->json([
            \'success\' => true,
            \'data\' => BlogPostResource::collection($posts),
            \'meta\' => [
                \'total\' => $posts->total(),
                \'perPage\' => $posts->perPage(),
                \'currentPage\' => $posts->currentPage(),
                \'lastPage\' => $posts->lastPage(),
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(BlogPostRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data[\'slug\'] = Str::slug($request->title);
        $data[\'user_id\'] = auth()->id(); // Assign the authenticated user as author

        if ($request->hasFile(\'image\')) {
            $data[\'image\'] = $request->file(\'image\')->store(\'blog_images\', \'public\');
        }

        $post = BlogPost::create($data);

        return response()->json([
            \'success\' => true,
            \'message\' => \'Publicación de blog creada exitosamente.\',
            \'data\' => new BlogPostResource($post),
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $uuid): JsonResponse
    {
        $post = BlogPost::with([\'category\', \'author\'])->where(\'uuid\', $uuid)->firstOrFail();

        return response()->json([
            \'success\' => true,
            \'data\' => new BlogPostResource($post),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(BlogPostRequest $request, string $uuid): JsonResponse
    {
        $post = BlogPost::with([\'category\', \'author\'])->where(\'uuid\', $uuid)->firstOrFail();
        $data = $request->validated();
        $data[\'slug\'] = Str::slug($request->title);

        if ($request->hasFile(\'image\')) {
            // Delete old image if exists
            if ($post->image) {
                Storage::disk(\'public\')->delete($post->image);
            }
            $data[\'image\'] = $request->file(\'image\')->store(\'blog_images\', \'public\');
        }

        $post->update($data);

        return response()->json([
            \'success\' => true,
            \'message\' => \'Publicación de blog actualizada exitosamente.\',
            \'data\' => new BlogPostResource($post),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $uuid): JsonResponse
    {
        $post = BlogPost::where(\'uuid\', $uuid)->firstOrFail();

        if ($post->image) {
            Storage::disk(\'public\')->delete($post->image);
        }

        $post->delete();

        return response()->json([
            \'success\' => true,
            \'message\' => \'Publicación de blog eliminada exitosamente.\',
        ]);
    }
}.
}
