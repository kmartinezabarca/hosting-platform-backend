<?php

namespace App\Http\Controllers\Pet;

use App\Http\Controllers\Controller;
use App\Models\Pet\InboxNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InboxController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $ownerId = $request->user()->uuid;

        $notifications = InboxNotification::where('owner_id', $ownerId)
            ->whereNull('archived_at')
            ->orderByDesc('created_at')
            ->paginate(30);

        $unreadCount = InboxNotification::where('owner_id', $ownerId)
            ->whereNull('archived_at')
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'data' => $notifications->items(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page'    => $notifications->lastPage(),
                'total'        => $notifications->total(),
                'unread_count' => $unreadCount,
            ],
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notif = InboxNotification::where('id', $id)
            ->where('owner_id', $request->user()->uuid)
            ->firstOrFail();

        if (!$notif->read_at) {
            $notif->update(['read_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        InboxNotification::where('owner_id', $request->user()->uuid)
            ->whereNull('read_at')
            ->whereNull('archived_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        $notif = InboxNotification::where('id', $id)
            ->where('owner_id', $request->user()->uuid)
            ->firstOrFail();

        $notif->update(['archived_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        InboxNotification::where('id', $id)
            ->where('owner_id', $request->user()->uuid)
            ->delete();

        return response()->json(['ok' => true]);
    }
}
