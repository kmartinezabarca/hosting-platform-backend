<?php

namespace App\Domains\Platform\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\SystemStatusResource;
use App\Domains\Platform\Models\SystemStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemStatusController extends Controller
{
    public function index(): JsonResponse
    {
        $statuses = SystemStatus::orderBy("service_name")->get();

        return response()->json(SystemStatusResource::collection($statuses));
    }
}
