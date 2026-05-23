<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

class NotificationController extends Controller
{
    /**
     * Get admin notifications dashboard
     */
    public function dashboard(): JsonResponse
    {
        $admin = Auth::user();

        $stats = [
            'unread_count' => $admin->unreadNotifications()->where('data->target', 'admin')->whereNull('archived_at')->count(),
            'today_count'  => $admin->notifications()->where('data->target', 'admin')->whereDate('created_at', today())->count(),
            'week_count'   => $admin->notifications()->where('data->target', 'admin')->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
        ];

        $recent_notifications = $admin->notifications()
            ->where('data->target', 'admin')
            ->whereNull('archived_at')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $notification_types = $admin->notifications()
            ->where('data->target', 'admin')
            ->selectRaw('data->>"$.type" as type, COUNT(*) as count')
            ->groupBy('type')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'stats' => $stats,
                'recent_notifications' => $recent_notifications,
                'notification_types' => $notification_types,
            ],
        ]);
    }

    /**
     * Get all admin notifications
     * Supports: type, unread_only, archived (true = only archived, false/omitted = exclude archived)
     */
    public function index(Request $request): JsonResponse
    {
        $admin = Auth::user();

        $query = $admin->notifications()
            ->where('data->target', 'admin')
            ->when($request->type, fn ($q, $type) => $q->where('data->type', $type))
            ->orderBy('created_at', 'desc');

        if ($request->boolean('archived')) {
            $query->whereNotNull('archived_at');
        } elseif ($request->boolean('unread_only')) {
            $query->whereNull('read_at')->whereNull('archived_at');
        } else {
            $query->whereNull('archived_at');
        }

        $notifications = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $notifications,
        ]);
    }

    /**
     * Send broadcast notification to all users
     */
    public function broadcastToUsers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'message'     => 'required|string|max:1000',
            'type'        => 'required|string|in:info,warning,success,error',
            'action_url'  => 'nullable|string|url|max:500',
            'action_text' => 'nullable|string|max:100',
            'user_ids'    => 'nullable|array',
            'user_ids.*'  => 'exists:users,id',
        ]);

        $users = empty($validated['user_ids'])
            ? User::where('role', 'client')->get()
            : User::whereIn('id', $validated['user_ids'])->get();

        $notificationData = [
            'type'              => 'admin_broadcast',
            'title'             => $validated['title'],
            'message'           => $validated['message'],
            'notification_type' => $validated['type'],
            'action_url'        => $validated['action_url'] ?? null,
            'action_text'       => $validated['action_text'] ?? null,
            'icon'              => $this->getIconForType($validated['type']),
            'color'             => $validated['type'],
            'sent_by'           => Auth::user()->name,
        ];

        foreach ($users as $user) {
            $user->notify(new \App\Notifications\AdminBroadcast($notificationData));
        }

        return response()->json([
            'success' => true,
            'message' => "Notificación enviada a {$users->count()} usuarios.",
        ]);
    }

    /**
     * Send notification to specific user
     */
    public function sendToUser(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'message'     => 'required|string|max:1000',
            'type'        => 'required|string|in:info,warning,success,error',
            'action_url'  => 'nullable|string|url|max:500',
            'action_text' => 'nullable|string|max:100',
        ]);

        $notificationData = [
            'type'              => 'admin_direct',
            'title'             => $validated['title'],
            'message'           => $validated['message'],
            'notification_type' => $validated['type'],
            'action_url'        => $validated['action_url'] ?? null,
            'action_text'       => $validated['action_text'] ?? null,
            'icon'              => $this->getIconForType($validated['type']),
            'color'             => $validated['type'],
            'sent_by'           => Auth::user()->name,
        ];

        $user->notify(new \App\Notifications\AdminDirect($notificationData));

        return response()->json([
            'success' => true,
            'message' => "Notificación enviada a {$user->name}.",
        ]);
    }

    /**
     * Get notification statistics
     */
    public function getStats(): JsonResponse
    {
        $admin = Auth::user();

        $totalNotifications  = $admin->notifications()->where('data->target', 'admin')->whereNull('archived_at')->count();
        $unreadNotifications = $admin->unreadNotifications()->where('data->target', 'admin')->whereNull('archived_at')->count();
        $archivedCount       = $admin->notifications()->where('data->target', 'admin')->whereNotNull('archived_at')->count();
        $todayNotifications  = $admin->notifications()->where('data->target', 'admin')->whereDate('created_at', today())->count();

        $notificationsByType = $admin->notifications()
            ->where('data->target', 'admin')
            ->where('created_at', '>=', now()->subDays(30))
            ->reorder()
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(data, '$.type')) as type, COUNT(*) as count")
            ->groupByRaw("JSON_UNQUOTE(JSON_EXTRACT(data, '$.type'))")
            ->orderByDesc('count')
            ->get();

        $dailyActivity = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $dailyActivity[] = [
                'date'  => $date->format('Y-m-d'),
                'count' => $admin->notifications()->where('data->target', 'admin')->whereDate('created_at', $date)->count(),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'total_notifications'    => $totalNotifications,
                'unread_notifications'   => $unreadNotifications,
                'archived_notifications' => $archivedCount,
                'today_notifications'    => $todayNotifications,
                'notifications_by_type'  => $notificationsByType,
                'daily_activity'         => $dailyActivity,
            ],
        ]);
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(Request $request, string $notificationId): JsonResponse
    {
        $notification = Auth::user()->notifications()->find($notificationId);

        if (!$notification) {
            return response()->json(['success' => false, 'message' => 'Notificación no encontrada.'], 404);
        }

        $notification->markAsRead();

        return response()->json(['success' => true, 'message' => 'Notificación marcada como leída.']);
    }

    /**
     * Mark all non-archived notifications as read
     */
    public function markAllAsRead(): JsonResponse
    {
        Auth::user()->unreadNotifications()->whereNull('archived_at')->update(['read_at' => now()]);

        return response()->json(['success' => true, 'message' => 'Todas las notificaciones marcadas como leídas.']);
    }

    /**
     * Archive a notification
     */
    public function archive(string $notificationId): JsonResponse
    {
        $notification = Auth::user()->notifications()->find($notificationId);

        if (!$notification) {
            return response()->json(['success' => false, 'message' => 'Notificación no encontrada.'], 404);
        }

        $notification->archive();

        return response()->json(['success' => true, 'message' => 'Notificación archivada.']);
    }

    /**
     * Unarchive a notification
     */
    public function unarchive(string $notificationId): JsonResponse
    {
        $notification = Auth::user()->notifications()->find($notificationId);

        if (!$notification) {
            return response()->json(['success' => false, 'message' => 'Notificación no encontrada.'], 404);
        }

        $notification->unarchive();

        return response()->json(['success' => true, 'message' => 'Notificación restaurada.']);
    }

    /**
     * Archive all read notifications
     */
    public function archiveAllRead(): JsonResponse
    {
        Auth::user()->notifications()
            ->where('data->target', 'admin')
            ->whereNotNull('read_at')
            ->whereNull('archived_at')
            ->update(['archived_at' => now()]);

        return response()->json(['success' => true, 'message' => 'Notificaciones leídas archivadas.']);
    }

    /**
     * Delete a notification
     */
    public function destroy(string $notificationId): JsonResponse
    {
        $notification = Auth::user()->notifications()->find($notificationId);

        if (!$notification) {
            return response()->json(['success' => false, 'message' => 'Notificación no encontrada.'], 404);
        }

        $notification->delete();

        return response()->json(['success' => true, 'message' => 'Notificación eliminada.']);
    }

    /**
     * Delete all archived notifications
     */
    public function destroyAllArchived(): JsonResponse
    {
        Auth::user()->notifications()
            ->where('data->target', 'admin')
            ->whereNotNull('archived_at')
            ->delete();

        return response()->json(['success' => true, 'message' => 'Archivo vaciado.']);
    }

    private function getIconForType(string $type): string
    {
        return match ($type) {
            'info'    => 'information-circle',
            'warning' => 'exclamation-triangle',
            'success' => 'check-circle',
            'error'   => 'x-circle',
            default   => 'bell',
        };
    }
}
