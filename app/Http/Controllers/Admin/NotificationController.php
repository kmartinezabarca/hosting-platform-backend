<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Events\ServiceStatusChanged;
use App\Events\PaymentProcessed;
use App\Events\InvoiceGenerated;
use App\Events\TicketReplied;
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
        
        // Estadísticas de notificaciones
        $stats = [
            'unread_count' => $admin->unreadNotifications()->count(),
            'today_count' => $admin->notifications()
                ->whereDate('created_at', today())
                ->count(),
            'week_count' => $admin->notifications()
                ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
                ->count(),
        ];

        // Notificaciones recientes
        $recent_notifications = $admin->notifications()
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Resumen por tipo
        $notification_types = $admin->notifications()
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
     */
    public function index(Request $request): JsonResponse
    {
        $admin = Auth::user();
        
        $notifications = $admin->notifications()
            ->when($request->type, function ($query, $type) {
                return $query->where('data->type', $type);
            })
            ->when($request->unread_only, function ($query) {
                return $query->whereNull('read_at');
            })
            ->orderBy('created_at', 'desc')
            ->paginate(20);

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
            'title' => 'required|string|max:255',
            'message' => 'required|string|max:1000',
            'type' => 'required|string|in:info,warning,success,error',
            'action_url' => 'nullable|string',
            'action_text' => 'nullable|string',
            'user_ids' => 'nullable|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        // Si no se especifican usuarios, enviar a todos
        if (empty($validated['user_ids'])) {
            $users = User::where('role', 'client')->get();
        } else {
            $users = User::whereIn('id', $validated['user_ids'])->get();
        }

        // Crear notificación personalizada
        $notificationData = [
            'type' => 'admin_broadcast',
            'title' => $validated['title'],
            'message' => $validated['message'],
            'notification_type' => $validated['type'],
            'action_url' => $validated['action_url'] ?? null,
            'action_text' => $validated['action_text'] ?? null,
            'icon' => $this->getIconForType($validated['type']),
            'color' => $validated['type'],
            'sent_by' => Auth::user()->name,
        ];

        // Enviar notificación a cada usuario
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
            'title' => 'required|string|max:255',
            'message' => 'required|string|max:1000',
            'type' => 'required|string|in:info,warning,success,error',
            'action_url' => 'nullable|string',
            'action_text' => 'nullable|string',
        ]);

        $notificationData = [
            'type' => 'admin_direct',
            'title' => $validated['title'],
            'message' => $validated['message'],
            'notification_type' => $validated['type'],
            'action_url' => $validated['action_url'] ?? null,
            'action_text' => $validated['action_text'] ?? null,
            'icon' => $this->getIconForType($validated['type']),
            'color' => $validated['type'],
            'sent_by' => Auth::user()->name,
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
        
        // Estadísticas generales
        $totalNotifications = $admin->notifications()->count();
        $unreadNotifications = $admin->unreadNotifications()->count();
        $todayNotifications = $admin->notifications()
            ->whereDate('created_at', today())
            ->count();

        // Notificaciones por tipo en los últimos 30 días
        $notificationsByType = $admin->notifications()
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('data->>"$.type" as type, COUNT(*) as count')
            ->groupBy('type')
            ->get();

        // Actividad por día en los últimos 7 días
        $dailyActivity = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $count = $admin->notifications()
                ->whereDate('created_at', $date)
                ->count();
            
            $dailyActivity[] = [
                'date' => $date->format('Y-m-d'),
                'count' => $count,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'total_notifications' => $totalNotifications,
                'unread_notifications' => $unreadNotifications,
                'today_notifications' => $todayNotifications,
                'notifications_by_type' => $notificationsByType,
                'daily_activity' => $dailyActivity,
            ],
        ]);
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(Request $request, string $notificationId): JsonResponse
    {
        $admin = Auth::user();
        
        $notification = $admin->notifications()->find($notificationId);
        
        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notificación no encontrada.',
            ], 404);
        }

        $notification->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'Notificación marcada como leída.',
        ]);
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(): JsonResponse
    {
        $admin = Auth::user();
        
        $admin->unreadNotifications->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'Todas las notificaciones marcadas como leídas.',
        ]);
    }

    /**
     * Delete notification
     */
    public function destroy(string $notificationId): JsonResponse
    {
        $admin = Auth::user();
        
        $notification = $admin->notifications()->find($notificationId);
        
        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notificación no encontrada.',
            ], 404);
        }

        $notification->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notificación eliminada.',
        ]);
    }

    /**
     * Get icon for notification type
     */
    private function getIconForType(string $type): string
    {
        return match ($type) {
            'info' => 'information-circle',
            'warning' => 'exclamation-triangle',
            'success' => 'check-circle',
            'error' => 'x-circle',
            default => 'bell',
        };
    }
}

