<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class UserRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = UserRequest::query();

        // Filtrar por tipo (kind)
        if ($request->has("kind") && in_array($request->kind, ["blog_subscription", "documentation_request", "api_documentation_request"])) {
            $query->where("kind", $request->kind);
        }

        // Filtrar por estado de resolución
        if ($request->has("is_resolved")) {
            $query->where("is_resolved", (bool) $request->is_resolved);
        }

        // Búsqueda por texto en name, email, topic, description
        if ($request->has("search")) {
            $searchTerm = "%" . $request->search . "%";
            $query->where(function ($q) use ($searchTerm) {
                $q->where("name", "like", $searchTerm)
                  ->orWhere("email", "like", $searchTerm)
                  ->orWhere("topic", "like", $searchTerm)
                  ->orWhere("description", "like", $searchTerm);
            });
        }

        $userRequests = $query->orderBy("created_at", "desc")->paginate(15);

        return response()->json($userRequests);
    }

    public function show(string $id): JsonResponse
    {
        $userRequest = UserRequest::findOrFail($id);
        return response()->json($userRequest);
    }

    public function markResolved(string $id): JsonResponse
    {
        $userRequest = UserRequest::findOrFail($id);
        $userRequest->update(["is_resolved" => true, "status" => "resolved"]);
        return response()->json(["message" => "Solicitud marcada como resuelta.", "data" => $userRequest]);
    }

    public function destroy(string $id): JsonResponse
    {
        $userRequest = UserRequest::findOrFail($id);
        $userRequest->delete();
        return response()->json(["message" => "Solicitud eliminada correctamente."]);
    }
}
