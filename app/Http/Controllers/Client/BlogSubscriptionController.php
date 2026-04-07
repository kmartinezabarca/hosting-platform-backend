<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\BlogSubscriptionRequest;
use App\Http\Resources\BlogSubscriptionResource;
use App\Models\BlogSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlogSubscriptionController extends Controller
{
    public function subscribe(BlogSubscriptionRequest $request): JsonResponse
    {
        $subscription = BlogSubscription::create($request->validated());

        return response()->json(new BlogSubscriptionResource($subscription), 201);
    }

    public function unsubscribe(string $uuid): JsonResponse
    {
        $subscription = BlogSubscription::where("uuid", $uuid)->firstOrFail();
        $subscription->update(["is_active" => false]);

        return response()->json(["message" => "Suscripción cancelada exitosamente."]);
    }
}
