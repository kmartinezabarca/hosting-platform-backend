<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SystemStatusRequest;
use App\Http\Resources\SystemStatusResource;
use App\Models\SystemStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemStatusController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SystemStatus::query();

        if ($request->has("search")) {
            $query->where("service_name", "like", "%" . $request->input("search") . "%");
        }

        $statuses = $query->orderBy("service_name")->paginate(10);

        return response()->json(SystemStatusResource::collection($statuses)->response()->getData(true));
    }

    public function store(SystemStatusRequest $request): JsonResponse
    {
        $status = SystemStatus::create($request->validated());

        return response()->json(new SystemStatusResource($status), 201);
    }

    public function show(string $uuid): JsonResponse
    {
        $status = SystemStatus::where("uuid", $uuid)->firstOrFail();

        return response()->json(new SystemStatusResource($status));
    }

    public function update(SystemStatusRequest $request, string $uuid): JsonResponse
    {
        $status = SystemStatus::where("uuid", $uuid)->firstOrFail();
        $status->update($request->validated());

        return response()->json(new SystemStatusResource($status));
    }

    public function destroy(string $uuid): JsonResponse
    {
        $status = SystemStatus::where("uuid", $uuid)->firstOrFail();
        $status->delete();

        return response()->json(["message" => "Estado del sistema eliminado exitosamente."], 204);
    }
}
