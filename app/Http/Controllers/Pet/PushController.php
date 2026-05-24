<?php

namespace App\Http\Controllers\Pet;

use App\Http\Controllers\Controller;
use App\Models\Pet\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushController extends Controller
{
    /**
     * Register a push subscription.
     *
     * Accepts two formats:
     *   - Web Push (VAPID): { endpoint, p256dh, auth }
     *   - Mobile FCM:       { fcm_token }
     */
    public function subscribe(Request $request): JsonResponse
    {
        if ($request->has('fcm_token')) {
            // ── Mobile FCM token ───────────────────────────────────────────
            $data = $request->validate([
                'fcm_token' => 'required|string|max:512',
            ]);

            PushSubscription::updateOrCreate(
                ['endpoint' => $data['fcm_token']],
                [
                    'owner_id' => $request->user()->uuid,
                    'type'     => 'fcm',
                    'p256dh'   => null,
                    'auth'     => null,
                ]
            );
        } else {
            // ── Browser Web Push (VAPID) ───────────────────────────────────
            $data = $request->validate([
                'endpoint' => 'required|string|url',
                'p256dh'   => 'required|string',
                'auth'     => 'required|string',
            ]);

            PushSubscription::updateOrCreate(
                ['endpoint' => $data['endpoint']],
                [
                    'owner_id' => $request->user()->uuid,
                    'type'     => 'webpush',
                    'p256dh'   => $data['p256dh'],
                    'auth'     => $data['auth'],
                ]
            );
        }

        return response()->json(['ok' => true], 201);
    }

    /**
     * Remove a push subscription.
     *
     * Accepts { endpoint } (web push) or { fcm_token } (mobile).
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint'  => 'nullable|string',
            'fcm_token' => 'nullable|string',
        ]);

        $identifier = $data['endpoint'] ?? $data['fcm_token'];

        if ($identifier) {
            PushSubscription::where('owner_id', $request->user()->uuid)
                ->where('endpoint', $identifier)
                ->delete();
        }

        return response()->json(['ok' => true]);
    }
}
