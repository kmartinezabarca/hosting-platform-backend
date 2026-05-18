<?php

namespace App\Http\Controllers\RokePet;

use App\Http\Controllers\Controller;
use App\Models\RokePet\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushController extends Controller
{
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => 'required|string|url',
            'p256dh'   => 'required|string',
            'auth'     => 'required|string',
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint' => $data['endpoint']],
            [
                'owner_id' => $request->user()->uuid,
                'p256dh'   => $data['p256dh'],
                'auth'     => $data['auth'],
            ]
        );

        return response()->json(['ok' => true], 201);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => 'required|string',
        ]);

        PushSubscription::where('owner_id', $request->user()->uuid)
            ->where('endpoint', $data['endpoint'])
            ->delete();

        return response()->json(['ok' => true]);
    }
}
