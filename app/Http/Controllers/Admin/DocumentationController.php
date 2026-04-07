<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DocumentationRequest;
use App\Http\Resources\DocumentationResource;
use App\Models\Documentation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Documentation::query();

        if ($request->has("search")) {
            $query->where("title", "like", "%" . $request->input("search") . "%")
                  ->orWhere("content", "like", "%" . $request->input("search") . "%");
        }

        if ($request->has("category")) {
            $query->where("category", $request->input("category"));
        }

        $documentation = $query->orderBy("created_at", "desc")->paginate(10);

        return response()->json(DocumentationResource::collection($documentation)->response()->getData(true));
    }

    public function store(DocumentationRequest $request): JsonResponse
    {
        $documentation = Documentation::create($request->validated());

        return response()->json(new DocumentationResource($documentation), 201);
    }

    public function show(string $uuid): JsonResponse
    {
        $documentation = Documentation::where("uuid", $uuid)->firstOrFail();

        return response()->json(new DocumentationResource($documentation));
    }

    public function update(DocumentationRequest $request, string $uuid): JsonResponse
    {
        $documentation = Documentation::where("uuid", $uuid)->firstOrFail();
        $documentation->update($request->validated());

        return response()->json(new DocumentationResource($documentation));
    }

    public function destroy(string $uuid): JsonResponse
    {
        $documentation = Documentation::where("uuid", $uuid)->firstOrFail();
        $documentation->delete();

        return response()->json(["message" => "Documentación eliminada exitosamente."], 204);
    }
}
