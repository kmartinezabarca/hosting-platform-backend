<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApiDocumentationRequest;
use App\Http\Resources\ApiDocumentationResource;
use App\Models\ApiDocumentation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiDocumentationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ApiDocumentation::query();

        if ($request->has("search")) {
            $query->where("title", "like", "%" . $request->input("search") . "%")
                  ->orWhere("content", "like", "%" . $request->input("search") . "%");
        }

        if ($request->has("category")) {
            $query->where("category", $request->input("category"));
        }

        $documentation = $query->orderBy("created_at", "desc")->paginate(10);

        return response()->json(ApiDocumentationResource::collection($documentation)->response()->getData(true));
    }

    public function store(ApiDocumentationRequest $request): JsonResponse
    {
        $documentation = ApiDocumentation::create($request->validated());

        return response()->json(new ApiDocumentationResource($documentation), 201);
    }

    public function show(string $uuid): JsonResponse
    {
        $documentation = ApiDocumentation::where("uuid", $uuid)->firstOrFail();

        return response()->json(new ApiDocumentationResource($documentation));
    }

    public function update(ApiDocumentationRequest $request, string $uuid): JsonResponse
    {
        $documentation = ApiDocumentation::where("uuid", $uuid)->firstOrFail();
        $documentation->update($request->validated());

        return response()->json(new ApiDocumentationResource($documentation));
    }

    public function destroy(string $uuid): JsonResponse
    {
        $documentation = ApiDocumentation::where("uuid", $uuid)->firstOrFail();
        $documentation->delete();

        return response()->json(["message" => "Documentación de API eliminada exitosamente."], 204);
    }
}
