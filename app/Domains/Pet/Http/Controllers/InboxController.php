<?php

namespace App\Domains\Pet\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Domains\Pet\Models\InboxNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InboxController extends Controller
{
    /** GET /inbox?filter=unread|read|archived|all — lista filtrada + conteos por pestaña. */
    public function index(Request $request): JsonResponse
    {
        $ownerId = $request->user()->uuid;
        $filter  = $request->query('filter', 'all'); // all = recibidas (no archivadas)

        $query = InboxNotification::where('owner_id', $ownerId);

        match ($filter) {
            'unread'   => $query->whereNull('archived_at')->whereNull('read_at'),
            'read'     => $query->whereNull('archived_at')->whereNotNull('read_at'),
            'archived' => $query->whereNotNull('archived_at'),
            default    => $query->whereNull('archived_at'),
        };

        $notifications = $query->orderByDesc('created_at')->paginate(30);

        // Conteos para las pestañas (independientes del filtro actual).
        $base = fn () => InboxNotification::where('owner_id', $ownerId);

        return response()->json([
            'data' => $notifications->items(),
            'meta' => [
                'current_page'   => $notifications->currentPage(),
                'last_page'      => $notifications->lastPage(),
                'total'          => $notifications->total(),
                'unread_count'   => $base()->whereNull('archived_at')->whereNull('read_at')->count(),
                'read_count'     => $base()->whereNull('archived_at')->whereNotNull('read_at')->count(),
                'archived_count' => $base()->whereNotNull('archived_at')->count(),
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

    /** POST /inbox/{id}/unarchive — regresa una notificación a "Recibidas". */
    public function unarchive(Request $request, string $id): JsonResponse
    {
        $notif = InboxNotification::where('id', $id)
            ->where('owner_id', $request->user()->uuid)
            ->firstOrFail();

        $notif->update(['archived_at' => null]);

        return response()->json(['ok' => true]);
    }

    /** POST /inbox/archive-all — archiva todas las recibidas (no archivadas). */
    public function archiveAll(Request $request): JsonResponse
    {
        $count = InboxNotification::where('owner_id', $request->user()->uuid)
            ->whereNull('archived_at')
            ->update(['archived_at' => now()]);

        return response()->json(['ok' => true, 'archived' => $count]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        InboxNotification::where('id', $id)
            ->where('owner_id', $request->user()->uuid)
            ->delete();

        return response()->json(['ok' => true]);
    }

    /** DELETE /inbox?scope=active|archived|all — borra en bloque según el ámbito. */
    public function destroyAll(Request $request): JsonResponse
    {
        $scope = $request->query('scope', 'all');

        $query = InboxNotification::where('owner_id', $request->user()->uuid);
        match ($scope) {
            'active'   => $query->whereNull('archived_at'),
            'archived' => $query->whereNotNull('archived_at'),
            default    => $query, // all
        };

        $count = $query->delete();

        return response()->json(['ok' => true, 'deleted' => $count]);
    }
}
