<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentationResource;
use App\Models\Documentation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Documentation::where("is_published", true);

        if ($request->has("category")) {
            $query->where("category", $request->input("category"));
        }

        if ($request->has("search")) {
            $query->where(function ($q) use ($request) {
                $q->where("title", "like", "%" . $request->input("search") . "%")
                  ->orWhere("content", "like", "%" . $request->input("search") . "%");
            });
        }

        $documentation = $query->orderBy("created_at", "desc")->paginate(10);

        return response()->json(DocumentationResource::collection($documentation)->response()->getData(true));
    }

    public function show(string $slug): JsonResponse
    {
        $documentation = Documentation::where("slug", $slug)->where("is_published", true)->firstOrFail();

        return response()->json(new DocumentationResource($documentation));
    }
}
