<?php

namespace App\Domains\Platform\Http\Controllers\Api;

use App\Domains\Platform\Models\NewsletterSubscription;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\NewsletterSubscribeRequest;
use Illuminate\Http\JsonResponse;

class NewsletterSubscriptionController extends Controller
{
    public function subscribe(NewsletterSubscribeRequest $request): JsonResponse
    {
        $email = $request->validated('email');

        $subscription = NewsletterSubscription::firstOrNew(['email' => $email]);
        $subscription->fill([
            'is_active' => true,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
        $subscription->save();

        return response()->json([
            'success' => true,
        ]);
    }
}
