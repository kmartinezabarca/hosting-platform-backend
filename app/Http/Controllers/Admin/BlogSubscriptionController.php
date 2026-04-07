<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\BlogSubscriptionResource;
use App\Models\BlogSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlogSubscriptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = BlogSubscription::query();

        if ($request->has("search")) {
            $query->where("email", "like", "%" . $request->input("search") . "%");
        }

        $subscriptions = $query->orderBy("created_at", "desc")->paginate(10);

        return response()->json(BlogSubscriptionResource::collection($subscriptions)->response()->getData(true));
    }

    public function show(string $uuid): JsonResponse
    {
        $subscription = BlogSubscription::where("uuid", $uuid)->firstOrFail();

        return response()->json(new BlogSubscriptionResource($subscription));
    }

    public function destroy(string $uuid): JsonResponse
    {
        $subscription = BlogSubscription::where("uuid", $uuid)->firstOrFail();
        $subscription->delete();

        return response()->json(["message" => "Suscripción eliminada exitosamente."], 204);
    }
}
